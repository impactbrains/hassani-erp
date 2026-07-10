<?php

namespace App\Http\Controllers;

use App\Models\Tax;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TaxController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('products/manage/Taxes', [
            'taxes' => Tax::orderBy('name')->get(),
        ]);
    }

    /**
     * Quick-create a tax from the product form's inline "Create new" flow,
     * and also used by the dedicated Taxes management page.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:taxes,name'],
            'rate' => ['required', 'numeric', 'min:0'],
            'type' => ['required', 'in:percentage,fixed'],
            'is_active' => ['boolean'],
        ]);

        $tax = Tax::create($validated);

        if ($request->header('X-Inertia')) {
            return redirect()->route('products.manage.taxes.index')->with('success', "Tax \"{$tax->name}\" created.");
        }

        return response()->json($tax);
    }

    public function update(Request $request, Tax $tax)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:taxes,name,' . $tax->id],
            'rate' => ['required', 'numeric', 'min:0'],
            'type' => ['required', 'in:percentage,fixed'],
            'is_active' => ['boolean'],
        ]);

        $tax->update($validated);

        return redirect()->route('products.manage.taxes.index')->with('success', "Tax \"{$tax->name}\" updated.");
    }

    public function destroy(Tax $tax)
    {
        if ($tax->is_default) {
            return redirect()->route('products.manage.taxes.index')
                ->with('error', 'Cannot delete the default tax. Set a different tax as default first.');
        }

        $name = $tax->name;
        $tax->delete();

        return redirect()->route('products.manage.taxes.index')->with('success', "Tax \"{$name}\" deleted.");
    }

    public function setDefault(Tax $tax)
    {
        DB::transaction(function () use ($tax) {
            Tax::where('is_default', true)->update(['is_default' => false]);
            $tax->update(['is_default' => true]);
        });

        return redirect()->route('products.manage.taxes.index')->with('success', "\"{$tax->name}\" is now the default tax.");
    }
}