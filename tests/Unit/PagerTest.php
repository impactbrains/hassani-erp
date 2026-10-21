<?php

use App\Support\Pager;

$url = fn (int $id) => "/records/{$id}";

it('finds the next and previous record in list order', function () use ($url) {
    $page = Pager::payload([30, 20, 10], 1, 'list', $url);

    expect($page['position'])->toBe(2)
        ->and($page['total'])->toBe(3)
        ->and($page['prev'])->toBe('/records/30')
        ->and($page['next'])->toBe('/records/10')
        ->and($page['source'])->toBe('list');
});

it('wraps round from the last record to the first and back', function () use ($url) {
    $ids = [30, 20, 10];

    expect(Pager::payload($ids, 2, 'all', $url)['next'])->toBe('/records/30')
        ->and(Pager::payload($ids, 0, 'all', $url)['prev'])->toBe('/records/10');
});

it('points at itself when it is the only record', function () use ($url) {
    $page = Pager::payload([5], 0, 'all', $url);

    expect($page['total'])->toBe(1)->and($page['next'])->toBe('/records/5')->and($page['prev'])->toBe('/records/5');
});
