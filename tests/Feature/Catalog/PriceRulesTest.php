<?php

use App\Domain\Catalog\PriceContext;
use App\Domain\Catalog\PriceRules;
use App\Domain\Catalog\PriceRuleService;
use App\Domain\Catalog\ProductService;
use App\Domain\Pos\CheckoutService;
use App\Domain\Pos\ShiftService;
use App\Domain\Sales\SalesOrderService;
use App\Exceptions\BusinessRuleException;
use App\Models\Contact;
use App\Models\CustomerProfile;
use App\Models\PaymentMethod;
use App\Models\PriceBook;
use App\Models\PriceRule;
use App\Models\ProductCategory;
use App\Models\Register;
use App\Models\Unit;
use Illuminate\Support\Str;

/**
 * Price rules: In-store price by default; "when these conditions are true, charge this price".
 * Example from the shop: anyone buying more than 5 gets the wholesale price.
 */
beforeEach(function () {
    $this->setUpCompany();
    $this->owner = $this->userWithRole('owner');
    $this->cashier = $this->userWithRole('cashier', $this->store);
    $this->inStore = PriceBook::query()->where('code', 'RETAIL')->value('id');
    $this->wholesaleBook = PriceBook::query()->where('code', 'WHOLESALE')->value('id');
    $this->pcs = Unit::query()->where('code', 'pcs')->value('id');
    $this->ctn = Unit::query()->where('code', 'ctn')->value('id');
    $this->category = ProductCategory::create(['name' => 'Soap', 'slug' => 'soap-'.Str::random(4)]);
    // Lux: In-store 140 a piece, 3200 a carton of 24; Wholesale 128 a piece, 3000 a carton.
    $this->lux = $this->makeProduct('Lux Soap', '140', ['ctn' => [24, '3200']], ['category_id' => $this->category->id]);
    app(ProductService::class)->setPrice($this->lux, $this->wholesaleBook, $this->lux->units->firstWhere('is_base', true)->id, '128.00');
    app(ProductService::class)->setPrice($this->lux, $this->wholesaleBook, $this->lux->units->firstWhere('unit_id', $this->ctn)->id, '3000.00');
    $this->piece = $this->lux->units->firstWhere('is_base', true);
    $this->carton = $this->lux->units->firstWhere('unit_id', $this->ctn);
    $this->rule = fn (array $data) => app(PriceRuleService::class)->save($data + ['scope' => 'variant', 'variant_id' => $this->lux->id, 'match' => 'all', 'action' => 'wholesale'], null, $this->lux);
    $this->price = function (array $lines, ?Contact $customer = null) {
        $items = [];
        foreach ($lines as $i => [$unit, $qty]) {
            $baseline = app(\App\Domain\Catalog\PriceResolver::class)->resolve($this->lux, $unit, $customer?->customerProfile?->price_book_id)['price'];
            $items[$i] = ['variant' => $this->lux, 'unit' => $unit, 'quantity' => $qty, 'base_quantity' => \App\Support\Decimal::qty(\App\Support\Decimal::mul($qty, $unit->factor)), 'baseline' => $baseline];
        }
        $rules = new PriceRules(app(\App\Domain\Catalog\PriceResolver::class));

        return $rules->apply($items, new PriceContext($this->store->id, $customer, now()->toDateString()));
    };
    $this->customer = function (?int $book = null, string $category = 'retail') {
        $c = Contact::create(['type' => 'customer', 'name' => 'Customer '.Str::random(4)]);
        CustomerProfile::create(['contact_id' => $c->id, 'price_book_id' => $book, 'category' => $category]);

        return $c->load('customerProfile');
    };
});

it('gives the wholesale price to every unit once more than 5 are bought, and the In-store price below that', function () {
    ($this->rule)(['conditions' => [['type' => 'quantity', 'op' => 'gt', 'value' => '5']]]);

    expect(($this->price)([[$this->piece, '5']])[0]['price'])->toBe('140.00');
    $six = ($this->price)([[$this->piece, '6']])[0];
    expect($six['price'])->toBe('128.00')
        ->and($six['tag']['action'])->toBe('wholesale')
        ->and($six['tag']['from'])->toBe(['op' => 'gt', 'value' => '5', 'unit' => null]);
});

it('counts the whole cart in base units, so a carton plus loose pieces count together', function () {
    ($this->rule)(['conditions' => [['type' => 'quantity', 'op' => 'gte', 'value' => '30']]]);
    // 1 carton (24) + 6 pieces = 30 pieces
    $result = ($this->price)([[$this->carton, '1'], [$this->piece, '6']]);
    expect($result[0]['price'])->toBe('3000.00')->and($result[1]['price'])->toBe('128.00');
});

it('can count in a pack instead of the base unit', function () {
    ($this->rule)(['conditions' => [['type' => 'quantity', 'op' => 'gte', 'value' => '2', 'unit_id' => $this->ctn]]]);
    expect(($this->price)([[$this->carton, '1']])[0]['price'])->toBe('3200.00')
        ->and(($this->price)([[$this->carton, '2']])[0]['price'])->toBe('3000.00');
});

it('charges a fixed price per pack, or a percentage off the In-store price', function () {
    ($this->rule)(['conditions' => [['type' => 'quantity', 'op' => 'gte', 'value' => '10']], 'action' => 'fixed', 'value' => '2880', 'unit_id' => $this->ctn]);
    // 2880 per carton of 24 = 120 a piece
    expect(($this->price)([[$this->piece, '10']])[0]['price'])->toBe('120.00');

    PriceRule::query()->delete();
    ($this->rule)(['conditions' => [], 'action' => 'percent_off', 'value' => '5']);
    expect(($this->price)([[$this->piece, '1']])[0]['price'])->toBe('133.00');
});

it('keeps the In-store price when the product has no wholesale price', function () {
    $atta = $this->makeProduct('Atta', '150');
    app(PriceRuleService::class)->save(['scope' => 'all', 'action' => 'wholesale', 'conditions' => [['type' => 'quantity', 'op' => 'gte', 'value' => '1']]]);
    $unit = $atta->units->first();
    $result = (new PriceRules(app(\App\Domain\Catalog\PriceResolver::class)))->apply([['variant' => $atta, 'unit' => $unit, 'quantity' => '3', 'base_quantity' => '3', 'baseline' => '150.00']], new PriceContext($this->store->id, null, now()->toDateString()));
    expect($result[0]['price'])->toBe('150.00')->and($result[0]['rule_id'])->toBeNull();
});

it('uses the most specific rule first, and the first rule that fits wins without adding up', function () {
    app(PriceRuleService::class)->save(['scope' => 'all', 'action' => 'percent_off', 'value' => '50', 'conditions' => []]);
    app(PriceRuleService::class)->save(['scope' => 'category', 'category_id' => $this->category->id, 'action' => 'percent_off', 'value' => '10', 'conditions' => []]);
    ($this->rule)(['conditions' => [['type' => 'quantity', 'op' => 'gte', 'value' => '6']]]);

    expect(($this->price)([[$this->piece, '6']])[0]['price'])->toBe('128.00'); // product rule: wholesale
    expect(($this->price)([[$this->piece, '1']])[0]['price'])->toBe('126.00'); // product rule does not fit: category 10% off
});

it('never raises a price', function () {
    ($this->rule)(['conditions' => [], 'action' => 'fixed', 'value' => '150', 'unit_id' => null]);
    expect(($this->price)([[$this->piece, '1']])[0]['price'])->toBe('140.00');
});

it('does not give wholesale rules to customers who already have the Wholesale price list', function () {
    ($this->rule)(['conditions' => [['type' => 'quantity', 'op' => 'gte', 'value' => '6']]]);
    app(PriceRuleService::class)->save(['scope' => 'all', 'action' => 'percent_off', 'value' => '20', 'conditions' => [['type' => 'quantity', 'op' => 'gte', 'value' => '6']]]);
    $trader = ($this->customer)($this->wholesaleBook, 'wholesale');

    $result = ($this->price)([[$this->piece, '6']], $trader)[0];
    expect($result['price'])->toBe('128.00')->and($result['rule_id'])->toBeNull(); // their own list, no second discount

    // A rule that names their price list does apply to them.
    app(PriceRuleService::class)->save(['scope' => 'variant', 'variant_id' => $this->lux->id, 'action' => 'fixed', 'value' => '120', 'conditions' => [['type' => 'price_list', 'ids' => [$this->wholesaleBook]], ['type' => 'quantity', 'op' => 'gte', 'value' => '48']]], null, $this->lux);
    expect(($this->price)([[$this->piece, '48']], $trader)[0]['price'])->toBe('120.00');
});

it('checks customer type, store, dates, pack size and the bill total', function () {
    ($this->rule)(['match' => 'all', 'conditions' => [['type' => 'customer_type', 'values' => ['shopkeeper']], ['type' => 'store', 'ids' => [$this->store->id]], ['type' => 'pack', 'unit_ids' => [$this->pcs]]]]);
    expect(($this->price)([[$this->piece, '1']], ($this->customer)(null, 'shopkeeper'))[0]['price'])->toBe('128.00')
        ->and(($this->price)([[$this->piece, '1']])[0]['price'])->toBe('140.00'); // walk-in

    PriceRule::query()->delete();
    ($this->rule)(['conditions' => [['type' => 'date', 'from' => now()->addDay()->toDateString()]]]);
    expect(($this->price)([[$this->piece, '1']])[0]['price'])->toBe('140.00');

    PriceRule::query()->delete();
    ($this->rule)(['match' => 'any', 'conditions' => [['type' => 'bill_total', 'op' => 'gte', 'value' => '5000'], ['type' => 'quantity', 'op' => 'gte', 'value' => '100']]]);
    expect(($this->price)([[$this->piece, '10']])[0]['price'])->toBe('140.00')
        ->and(($this->price)([[$this->piece, '36']])[0]['price'])->toBe('128.00'); // bill 5040
});

it('hints how many more to add when close to a quantity rule', function () {
    ($this->rule)(['conditions' => [['type' => 'quantity', 'op' => 'gte', 'value' => '6']]]);
    expect(($this->price)([[$this->piece, '2']])[0]['hint'])->toBeNull() // not close yet
        ->and(($this->price)([[$this->piece, '4']])[0]['hint']['more'])->toBe('2');
});

it('refuses rules that make no sense', function () {
    expect(fn () => app(PriceRuleService::class)->save(['scope' => 'all', 'action' => 'fixed', 'value' => '100', 'conditions' => []]))->toThrow(BusinessRuleException::class, 'fixed price can only be set on one product');
    expect(fn () => ($this->rule)(['action' => 'percent_off', 'value' => '120', 'conditions' => []]))->toThrow(BusinessRuleException::class, 'less than 100');
    expect(fn () => ($this->rule)(['conditions' => [['type' => 'date', 'from' => '2026-12-31', 'to' => '2026-01-01']]]))->toThrow(BusinessRuleException::class, 'end date is before');
});

it('applies rules at the POS without manager approval, shows the tag in the quote, and records the rule on the receipt line', function () {
    ($this->rule)(['conditions' => [['type' => 'quantity', 'op' => 'gt', 'value' => '5']]]);
    $register = Register::query()->where('store_id', $this->store->id)->firstOrFail();
    app(ShiftService::class)->open($this->cashier, $register, ['5000' => 2]);
    $this->receive($this->lux, '100', '100');
    $lines = [['variant_id' => $this->lux->id, 'variant_unit_id' => $this->piece->id, 'quantity' => '6']];

    $quote = app(CheckoutService::class)->quote($this->cashier, ['register_id' => $register->id, 'lines' => $lines]);
    expect($quote['list_prices'][0])->toBe('128.00')
        ->and($quote['price_tags'][0]['action'])->toBe('wholesale')
        ->and($quote['total'])->toBe('768.00');

    $cash = PaymentMethod::query()->where('code', 'cash')->firstOrFail();
    $invoice = app(CheckoutService::class)->checkout($this->cashier, ['register_id' => $register->id, 'idempotency_key' => (string) Str::uuid(), 'lines' => $lines,
        'payments' => [['payment_method_id' => $cash->id, 'amount' => '768']], 'expected_total' => '768.00']);
    $line = $invoice->lines->first();
    expect($line->unit_price)->toBe('128.00')
        ->and($line->price_overridden)->toBeFalse()
        ->and($line->price_rule_id)->not->toBeNull();
});

it('applies rules to sales orders taken by staff, even when the form sends the plain list price', function () {
    ($this->rule)(['conditions' => [['type' => 'quantity', 'op' => 'gte', 'value' => '6']]]);
    $agent = $this->userWithRole('sales_agent', $this->store);
    $customer = ($this->customer)();
    $order = app(SalesOrderService::class)->save($agent, $this->store, ['customer_id' => $customer->id, 'lines' => [
        ['variant_id' => $this->lux->id, 'variant_unit_id' => $this->piece->id, 'quantity' => '6', 'unit_price' => '140'],
    ]]);
    expect($order->lines->first()->unit_price)->toBe('128.00');

    $online = app(SalesOrderService::class)->save(null, $this->store, ['customer_id' => $customer->id, 'channel' => 'ecommerce', 'lines' => [
        ['variant_id' => $this->lux->id, 'variant_unit_id' => $this->piece->id, 'quantity' => '6'],
    ]]);
    expect($online->lines->first()->unit_price)->toBe('140.00');
});

it('saves rules and the purchase price from the product form', function () {
    $this->withoutVite();
    $product = $this->lux->product;
    $this->actingAs($this->owner)->put(route('catalog.products.update', $product), [
        'name' => $product->name, 'type' => 'stocked',
        'variants' => [['id' => $this->lux->id, 'sku' => $this->lux->sku, 'name' => $this->lux->name, 'base_unit_id' => $this->pcs, 'purchase_price' => '118.5', 'mrp' => '150',
            'units' => [['unit_id' => $this->pcs, 'factor' => '1', 'can_sell' => true, 'can_purchase' => true, 'is_default_sale' => true], ['unit_id' => $this->ctn, 'factor' => '24', 'can_sell' => true, 'can_purchase' => true]],
            'prices' => [['price_book_id' => $this->inStore, 'unit_id' => $this->pcs, 'price' => '140'], ['price_book_id' => $this->wholesaleBook, 'unit_id' => $this->ctn, 'price' => '', 'clear' => true]],
            'price_rules' => [['match' => 'all', 'conditions' => [['type' => 'quantity', 'op' => 'gt', 'value' => '5']], 'action' => 'wholesale', 'is_active' => true]],
        ]],
    ])->assertSessionHasNoErrors();

    $variant = $this->lux->fresh();
    expect($variant->purchase_price)->toBe('118.5000')
        ->and(PriceRule::query()->where('variant_id', $variant->id)->count())->toBe(1)
        ->and(app(\App\Domain\Catalog\PriceResolver::class)->bookPrice($variant, $this->carton, $this->wholesaleBook))->toBeNull(); // emptied Wholesale carton price
});

it('updates the purchase price when stock is received', function () {
    $supplier = Contact::create(['type' => 'supplier', 'name' => 'Jodia']);
    app(\App\Domain\Purchasing\ReceivingService::class)->receive($this->owner, $this->store, ['supplier_id' => $supplier->id,
        'lines' => [['variant_id' => $this->lux->id, 'variant_unit_id' => $this->carton->id, 'quantity_received' => '1', 'unit_price' => '2760']]]);
    expect($this->lux->fresh()->purchase_price)->toBe('115.0000');
});

it('imports the new price columns, and the old column names still work', function () {
    $csv = tempnam(sys_get_temp_dir(), 'imp');
    file_put_contents($csv, "name,unit,pack_unit,pack_size,purchase_price,in_store_price,wholesale_price,market_price,pack_in_store_price,pack_wholesale_price,sku\n"
        ."Surf Excel 500g,pcs,ctn,24,300,340,325,350,8000,7700,SURF-500\n");
    $result = app(\App\Domain\Catalog\ProductImporter::class)->import($csv, $this->owner, $this->store, true);
    expect($result['created'])->toBe(1);
    $surf = \App\Models\ProductVariant::query()->where('sku', 'SURF-500')->with('units')->first();
    $resolver = app(\App\Domain\Catalog\PriceResolver::class);
    expect($surf->purchase_price)->toBe('300.0000')->and($surf->mrp)->toBe('350.00')
        ->and($resolver->bookPrice($surf, $surf->units->firstWhere('unit_id', $this->ctn), $this->wholesaleBook))->toBe('7700.00');

    file_put_contents($csv, "name,unit,retail_price,mrp,sku\nOld Format Soap,pcs,99,105,OLD-1\n");
    app(\App\Domain\Catalog\ProductImporter::class)->import($csv, $this->owner, $this->store, true);
    $old = \App\Models\ProductVariant::query()->where('sku', 'OLD-1')->with('units')->first();
    expect($old->mrp)->toBe('105.00')->and($resolver->bookPrice($old, $old->units->first(), $this->inStore))->toBe('99.00');
});
