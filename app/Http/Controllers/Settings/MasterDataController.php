<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Store;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** One screen for every simple list in MasterData. Rows in use are deactivated, not deleted. */
class MasterDataController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $lists = collect(MasterData::all())->filter(fn ($d) => $user->hasPermission($d['permission']))->map(fn ($d, $k) => ['key' => $k, 'label' => $d['label'], 'section' => $d['section']])->values();
        abort_if($lists->isEmpty(), 403);
        $key = $request->query('list', $lists->first()['key']);
        $def = MasterData::find($key);
        $this->allow($request, $def['permission']);
        $query = $def['model']::query()->orderBy($def['order'])->orderBy('id');
        if ($def['store_scoped'] ?? false) {
            $query->whereIn('store_id', $user->accessibleStoreIds());
        }

        return Inertia::render('settings/MasterData', [
            'lists' => $lists,
            'list' => ['key' => $key, 'label' => $def['label'], 'append_only' => (bool) ($def['append_only'] ?? false), 'fields' => collect($def['fields'])->map(fn ($f) => array_diff_key($f, ['rules' => 1]))],
            'rows' => $query->limit(1000)->get(),
            'sources' => $this->sources($def, $user),
        ]);
    }

    public function save(Request $request, string $list, ?int $id = null): RedirectResponse
    {
        $def = MasterData::find($list);
        $this->allow($request, $def['permission']);
        abort_if($id && ($def['append_only'] ?? false), 422, 'This list keeps its history: add a new row with a later start date instead.');
        $data = $request->validate(collect($def['fields'])->map(fn ($f) => $f['rules'])->all());
        if (($def['store_scoped'] ?? false) || array_key_exists('store_id', $data)) {
            abort_if(! empty($data['store_id']) && ! in_array((int) $data['store_id'], $request->user()->accessibleStoreIds(), true), 403);
        }
        /** @var Model $model */
        $model = $id ? $def['model']::query()->findOrFail($id) : new $def['model'];
        // Empty optional numbers fall back to the column default instead of failing on NOT NULL columns.
        foreach ($def['fields'] as $name => $field) {
            if (array_key_exists($name, $data) && $data[$name] === null && (! $model->exists || in_array($field['type'] ?? '', ['number', 'decimal'], true)) && ! in_array($field['type'] ?? '', ['ref'], true)) {
                unset($data[$name]);
            }
        }
        $old = $model->exists ? $model->only(array_keys($data)) : [];
        $model->fill($data + (! $model->exists && isset($def['defaults']) ? ($def['defaults'])($data) : []));
        if (! $model->exists && in_array('created_by', $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable()), true)) {
            $model->created_by = $request->user()->id;
        }
        $model->save();
        Audit::record('master_data.saved', "{$def['label']}: saved “".($data['name'] ?? $data['label'] ?? $data['code'] ?? $model->getKey()).'”', $model, $old, $data);

        return back()->with('success', 'Saved.');
    }

    public function destroy(Request $request, string $list, int $id): RedirectResponse
    {
        $def = MasterData::find($list);
        $this->allow($request, $def['permission']);
        $model = $def['model']::query()->findOrFail($id);
        try {
            \Illuminate\Support\Facades\DB::transaction(fn () => $model->delete());
            Audit::record('master_data.deleted', "{$def['label']}: deleted #{$id}", null, $model->toArray());

            return back()->with('success', 'Deleted.');
        } catch (\Illuminate\Database\QueryException) {
            if (array_key_exists('is_active', $model->getAttributes())) {
                $model->update(['is_active' => false]);

                return back()->with('success', 'This is in use, so it was deactivated instead of deleted.');
            }

            return back()->with('error', 'This is in use and cannot be deleted.');
        }
    }

    /** @return array<string, list<array{id: int, name: string}>> */
    private function sources(array $def, $user): array
    {
        $out = [];
        foreach ($def['fields'] as $field) {
            if (($field['type'] ?? null) !== 'ref') {
                continue;
            }
            $out[$field['source']] = match ($field['source']) {
                'accounts' => Account::query()->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name'])->map(fn ($a) => ['id' => $a->id, 'name' => "{$a->code} {$a->name}"]),
                'stores' => Store::query()->whereIn('id', $user->accessibleStoreIds())->get(['id', 'name']),
                'locations' => \App\Models\Location::query()->whereIn('store_id', $user->accessibleStoreIds())->get(['id', 'name', 'store_id']),
                default => MasterData::find($field['source'])['model']::query()->orderBy('name')->get(['id', 'name']),
            };
        }

        return $out;
    }
}
