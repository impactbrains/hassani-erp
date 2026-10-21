<?php

use App\Domain\Crm\CustomerService;
use App\Models\Contact;
use App\Models\SupplierProfile;

/**
 * The next / previous arrows on a record page (App\Support\Pager) end to end: a list is opened with
 * filters, a record is opened from it, and its arrows walk the same filtered list.
 */
beforeEach(function () {
    $this->setUpCompany();
    $this->owner = $this->userWithRole('owner');
    $this->customer = fn (string $name, array $extra = []) => app(CustomerService::class)->save($this->owner, ['name' => $name] + $extra);
});


it('walks the customers list in the order and filter it was opened with', function () {
    $anwar = ($this->customer)('Anwar Store', ['area' => 'Saddar']);
    $bilal = ($this->customer)('Bilal Store', ['area' => 'Clifton']);
    $chand = ($this->customer)('Chand Store', ['area' => 'Saddar']);
    $dawood = ($this->customer)('Dawood Store', ['area' => 'Saddar']);

    // The whole list (no filters on the screen, so it is the "all records" list): Bilal sits between Anwar and Chand.
    $this->actingAs($this->owner)->get(route('crm.customers.index'))->assertOk();
    $this->actingAs($this->owner)->get(route('crm.customers.show', $bilal->id))->assertOk()
        ->assertInertia(fn ($page) => $page->where('pager.position', 2)->where('pager.total', 4)->where('pager.source', 'all')
            ->where('pager.prev', route('crm.customers.show', $anwar->id))->where('pager.next', route('crm.customers.show', $chand->id)));

    // Filtered to Saddar: Chand's neighbours skip Bilal, and the arrows wrap round.
    $this->actingAs($this->owner)->get(route('crm.customers.index', ['area' => 'Saddar']))->assertOk();
    $this->actingAs($this->owner)->get(route('crm.customers.show', $chand->id))
        ->assertInertia(fn ($page) => $page->where('pager.source', 'list')->where('pager.position', 2)->where('pager.total', 3)->where('pager.prev', route('crm.customers.show', $anwar->id))->where('pager.next', route('crm.customers.show', $dawood->id)));
    $this->actingAs($this->owner)->get(route('crm.customers.show', $dawood->id))
        ->assertInertia(fn ($page) => $page->where('pager.next', route('crm.customers.show', $anwar->id)));
});

it('falls back to the whole list when the record is not in the list it was opened from', function () {
    $anwar = ($this->customer)('Anwar Store', ['area' => 'Saddar']);
    $bilal = ($this->customer)('Bilal Store', ['area' => 'Clifton']);

    $this->actingAs($this->owner)->get(route('crm.customers.index', ['area' => 'Saddar']))->assertOk();
    $this->actingAs($this->owner)->get(route('crm.customers.show', $bilal->id))
        ->assertInertia(fn ($page) => $page->where('pager.source', 'all')->where('pager.total', 2)->where('pager.prev', route('crm.customers.show', $anwar->id)));
});

it('keeps a record page working when the list is sorted by a joined balance column', function () {
    $anwar = ($this->customer)('Anwar Store');
    $bilal = ($this->customer)('Bilal Store');

    $this->actingAs($this->owner)->get(route('crm.customers.index', ['sort' => 'balance', 'with_balance' => 1]))->assertOk();
    $this->actingAs($this->owner)->get(route('crm.customers.show', $anwar->id))->assertOk()
        ->assertInertia(fn ($page) => $page->where('pager.total', 2)->where('pager.source', 'all'));
    $this->actingAs($this->owner)->get(route('crm.customers.index', ['sort' => 'balance']))->assertOk();
    $this->actingAs($this->owner)->get(route('crm.customers.show', $bilal->id))->assertInertia(fn ($page) => $page->where('pager.source', 'list')->where('pager.total', 2));
});

it('walks suppliers, users, products and employees as well', function () {
    $first = Contact::create(['type' => 'supplier', 'name' => 'Alpha Traders']);
    $second = Contact::create(['type' => 'supplier', 'name' => 'Beta Traders']);
    SupplierProfile::create(['contact_id' => $first->id, 'lead_time_days' => 1]);
    SupplierProfile::create(['contact_id' => $second->id, 'lead_time_days' => 1]);
    $this->actingAs($this->owner)->get(route('purchasing.suppliers.index'))->assertOk();
    $this->actingAs($this->owner)->get(route('purchasing.suppliers.show', $first->id))
        ->assertInertia(fn ($page) => $page->where('pager.total', 2)->where('pager.next', route('purchasing.suppliers.show', $second->id))->where('pager.prev', route('purchasing.suppliers.show', $second->id)));

    $other = $this->userWithRole('cashier', $this->store);
    $this->actingAs($this->owner)->get(route('settings.users.index'))->assertOk();
    $this->actingAs($this->owner)->get(route('settings.users.show', $other->id))->assertInertia(fn ($page) => $page->where('pager.total', 2));

    $soap = $this->makeProduct('Lux Soap', '100');
    $this->makeProduct('Surf Powder', '300');
    $this->actingAs($this->owner)->get(route('catalog.products.index'))->assertOk();
    $this->actingAs($this->owner)->get(route('catalog.products.edit', $soap->product_id))->assertInertia(fn ($page) => $page->where('pager.total', 2)->where('pager.position', 1));

    $this->actingAs($this->owner)->get(route('hr.employees.index'))->assertOk();
});

it('has no arrows when there is only one record', function () {
    $only = ($this->customer)('Only Store');

    $this->actingAs($this->owner)->get(route('crm.customers.show', $only->id))
        ->assertInertia(fn ($page) => $page->where('pager.total', 1));
});

it('steps through journal entries and ledger accounts, keeping the date range on the ledger', function () {
    $this->actingAs($this->owner)->get(route('accounting.journals.index'))->assertOk();

    $accounts = \App\Models\Account::query()->orderBy('code')->orderBy('id')->limit(3)->get();
    $this->actingAs($this->owner)->get(route('accounting.accounts.show', ['account' => $accounts[1]->id, 'from' => '2026-01-01', 'to' => '2026-01-31']))
        ->assertOk()->assertInertia(fn ($page) => $page->where('pager.position', 2)
            ->where('pager.prev', route('accounting.accounts.show', ['account' => $accounts[0]->id, 'from' => '2026-01-01', 'to' => '2026-01-31']))
            ->where('pager.next', route('accounting.accounts.show', ['account' => $accounts[2]->id, 'from' => '2026-01-01', 'to' => '2026-01-31'])));
});
