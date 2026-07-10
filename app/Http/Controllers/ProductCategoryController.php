<?php

namespace App\Http\Controllers;

use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductCategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('products/manage/Categories', [
            'categories' => ProductCategory::with('parent')->orderBy('name')->get(),
        ]);
    }

    /**
     * Quick-create a category from the product form's inline "Create new" flow.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:product_categories,name'],
            'parent_id' => ['nullable', 'exists:product_categories,id'],
        ]);

        $category = ProductCategory::create($validated);

        if ($request->header('X-Inertia')) {
            return redirect()->route('products.manage.categories.index')->with('success', "Category \"{$category->name}\" created.");
        }

        return response()->json($category);
    }

    public function update(Request $request, ProductCategory $category)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:product_categories,name,' . $category->id],
            'parent_id' => ['nullable', 'exists:product_categories,id', 'not_in:' . $category->id],
        ]);

        $category->update($validated);

        return redirect()->route('products.manage.categories.index')->with('success', "Category \"{$category->name}\" updated.");
    }

    public function destroy(ProductCategory $category)
    {
        if ($category->products()->exists()) {
            return redirect()->route('products.manage.categories.index')
                ->with('error', "Cannot delete \"{$category->name}\" — it's still assigned to products.");
        }

        $name = $category->name;
        $category->delete();

        return redirect()->route('products.manage.categories.index')->with('success', "Category \"{$name}\" deleted.");
    }
}
