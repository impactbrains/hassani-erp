<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * The next / previous arrows on a record's page.
 *
 * Each list screen remembers (in the session) the filters, search and sort it was last shown with.
 * A record's page re-runs that same list and finds its place in it: "next" is the record below it
 * on the list you came from, and the arrows wrap round from the last record to the first.
 * When the record is not in that list (opened from a link, say), the whole unfiltered list is used.
 */
final class Pager
{
    /** The most records one list is followed through. */
    public const CAP = 20000;

    /** Called by a list screen: keeps its filters (not the page number) for the record pages that follow. */
    public static function remember(Request $request, string $module): void
    {
        $request->session()->put('pager.'.$module, Arr::except($request->query(), ['page']));
    }

    /**
     * @param  Closure(Request): Builder  $list  the module's list query for the given request (filters and order as on the screen, no paging)
     * @param  Closure(int): string  $url  address of one record's page
     * @return array{position: int, total: int, capped: bool, source: string, prev: string, next: string}|null
     */
    public static function for(Request $request, string $module, int $id, Closure $list, Closure $url): ?array
    {
        $remembered = (array) $request->session()->get('pager.'.$module, []);
        $attempts = $remembered === [] ? ['all' => []] : ['list' => $remembered, 'all' => []];
        foreach ($attempts as $source => $query) {
            $ids = self::ids($list(self::subRequest($request, $query)));
            $index = array_search($id, $ids, true);
            if ($index !== false) {
                return self::payload($ids, $index, $source, $url);
            }
        }

        return null;
    }

    /**
     * @param  list<int>  $ids  the list in screen order
     * @param  Closure(int): string  $url
     * @return array{position: int, total: int, capped: bool, source: string, prev: string, next: string}
     */
    public static function payload(array $ids, int $index, string $source, Closure $url): array
    {
        $count = count($ids);

        return [
            'position' => $index + 1, 'total' => $count, 'capped' => $count >= self::CAP, 'source' => $source,
            'prev' => $url($ids[($index - 1 + $count) % $count]), 'next' => $url($ids[($index + 1) % $count]),
        ];
    }

    /** A copy of this request that carries the remembered list filters, so a controller's list query can be reused as is. */
    private static function subRequest(Request $request, array $query): Request
    {
        $sub = Request::create($request->url(), 'GET', $query);
        $sub->setUserResolver($request->getUserResolver());
        $sub->attributes->add($request->attributes->all());

        return $sub;
    }

    /** @return list<int> */
    private static function ids(Builder $query): array
    {
        return array_values(array_unique($query->limit(self::CAP)->pluck($query->getModel()->getQualifiedKeyName())->map(fn ($id) => (int) $id)->all()));
    }
}
