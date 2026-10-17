<?php

use App\Models\Company;
use App\Models\Product;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\MissingTenantContext;

it('isolates every tenant query to the current company', function () {
    $a = $this->setUpCompany('Shop A');
    $this->makeProduct('Only in A');
    $b = $this->setUpCompany('Shop B');
    $this->makeProduct('Only in B');

    CurrentCompany::set($a);
    expect(Product::query()->pluck('name')->all())->toBe(['Only in A']);
    CurrentCompany::set($b);
    expect(Product::query()->pluck('name')->all())->toBe(['Only in B']);
    expect(Product::query()->find(Product::query()->withoutGlobalScopes()->where('company_id', $a->id)->value('id')))->toBeNull();
});

it('fails loudly when a tenant query runs without a company', function () {
    $this->setUpCompany();
    CurrentCompany::clear();

    expect(fn () => Product::query()->count())->toThrow(MissingTenantContext::class);
});

it('refuses to write a record into another company', function () {
    $a = $this->setUpCompany('Shop A');
    $b = $this->setUpCompany('Shop B');
    CurrentCompany::set($a);

    // company_id is never mass-assignable: the record lands in the current company.
    $product = Product::create(['company_id' => $b->id, 'name' => 'Sneaky']);
    expect($product->company_id)->toBe($a->id);

    // Setting it explicitly to another company is refused outright.
    $other = new Product(['name' => 'Sneakier']);
    $other->company_id = $b->id;
    expect(fn () => $other->save())->toThrow(MissingTenantContext::class);
    expect(Company::query()->count())->toBe(2);
});
