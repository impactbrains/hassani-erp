<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    public function index(Request $request): Response
    {
        $contacts = Contact::query()
            ->when($request->type, fn ($q, $type) => $q->where('type', $type))
            ->when($request->search, fn ($q, $search) => $q->where(function ($sq) use ($search) {
                $sq->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            }))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('contacts/Index', [
            'contacts' => $contacts,
            'filters' => $request->only(['type', 'search']),
            'typeCounts' => Contact::selectRaw('type, count(*) as count')
                ->groupBy('type')
                ->pluck('count', 'type'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('contacts/Form', [
            'contact' => null,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateContact($request);

        $contact = Contact::create($validated);

        return redirect()->route('contacts.show', ['contact' => $contact->id])
            ->with('success', "\"{$contact->name}\" added.");
    }

    public function show(Contact $contact): Response
    {
        $recentDocuments = $contact->documents()
            ->whereIn('type', ['pos_order', 'pos_refund'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'type', 'number', 'status', 'total', 'created_at']);

        return Inertia::render('contacts/Show', [
            'contact' => $contact,
            'totalSpent' => $contact->totalSpent(),
            'orderCount' => $contact->orderCount(),
            'recentDocuments' => $recentDocuments,
        ]);
    }

    public function edit(Contact $contact): Response
    {
        return Inertia::render('contacts/Form', [
            'contact' => $contact,
        ]);
    }

    public function update(Request $request, Contact $contact)
    {
        $validated = $this->validateContact($request, $contact->id);

        $contact->update($validated);

        return redirect()->route('contacts.show', ['contact' => $contact->id])
            ->with('success', "\"{$contact->name}\" updated.");
    }

    public function destroy(Contact $contact)
    {
        if ($contact->documents()->exists()) {
            // Preserve transaction history — deactivate rather than delete,
            // same pattern used for products/variants with sales history.
            $contact->update(['is_active' => false]);

            return redirect()->route('contacts.index')
                ->with('success', "\"{$contact->name}\" has order history, so it was deactivated instead of deleted.");
        }

        $name = $contact->name;
        $contact->delete();

        return redirect()->route('contacts.index')->with('success', "\"{$name}\" deleted.");
    }

    private function validateContact(Request $request, ?int $contactId = null): array
    {
        return $request->validate([
            'type' => ['required', 'in:customer,supplier,employee'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ]);
    }
}