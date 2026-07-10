<?php

namespace App\Http\Controllers;

use App\Models\Unit;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UnitController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('products/manage/Units', [
            'units' => Unit::orderBy('name')->get(),
        ]);
    }

    /**
     * Quick-create a unit from the product form's inline "Create new" flow.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'short_code' => ['required', 'string', 'max:20', 'unique:units,short_code'],
        ]);

        $unit = Unit::create($validated);

        if ($request->header('X-Inertia')) {
            return redirect()->route('products.manage.units.index')->with('success', "Unit \"{$unit->name}\" created.");
        }

        return response()->json($unit);
    }

    public function update(Request $request, Unit $unit)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'short_code' => ['required', 'string', 'max:20', 'unique:units,short_code,' . $unit->id],
        ]);

        $unit->update($validated);

        return redirect()->route('products.manage.units.index')->with('success', "Unit \"{$unit->name}\" updated.");
    }

    public function destroy(Unit $unit)
    {
        if ($unit->variants()->exists()) {
            return redirect()->route('products.manage.units.index')
                ->with('error', "Cannot delete \"{$unit->name}\" — it's still assigned to product variants.");
        }

        $name = $unit->name;
        $unit->delete();

        return redirect()->route('products.manage.units.index')->with('success', "Unit \"{$name}\" deleted.");
    }
}