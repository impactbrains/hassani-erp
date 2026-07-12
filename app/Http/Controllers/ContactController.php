<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\ContactRecord;
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

    /**
     * Employees tab — Overview / Employees / Candidates(track=employee).
     * $view controls which sub-tab is active; defaults to the employee list.
     */
    public function employees(Request $request): Response
    {
        $view = $request->get('view', 'employees');

        $contacts = match ($view) {
            'candidates' => Contact::candidates('employee')
                ->when($request->search, fn ($q, $search) => $q->where('name', 'like', "%{$search}%"))
                ->orderByDesc('applied_at'),
            default => Contact::employees()
                ->when($request->search, fn ($q, $search) => $q->where('name', 'like', "%{$search}%"))
                ->orderBy('name'),
        };

        return Inertia::render('contacts/Employees', [
            'contacts' => $contacts->paginate(20)->withQueryString(),
            'view' => $view,
            'filters' => $request->only(['search']),
            'counts' => [
                'employees' => Contact::employees()->count(),
                'candidates' => Contact::candidates('employee')->count(),
            ],
        ]);
    }

    /**
     * Suppliers tab — Overview / Suppliers / Candidates(track=supplier).
     */
    public function suppliers(Request $request): Response
    {
        $view = $request->get('view', 'suppliers');

        $contacts = match ($view) {
            'candidates' => Contact::candidates('supplier')
                ->when($request->search, fn ($q, $search) => $q->where('name', 'like', "%{$search}%"))
                ->orderByDesc('applied_at'),
            default => Contact::suppliers()
                ->when($request->search, fn ($q, $search) => $q->where('name', 'like', "%{$search}%"))
                ->orderBy('name'),
        };

        return Inertia::render('contacts/Suppliers', [
            'contacts' => $contacts->paginate(20)->withQueryString(),
            'view' => $view,
            'filters' => $request->only(['search']),
            'counts' => [
                'suppliers' => Contact::suppliers()->count(),
                'candidates' => Contact::candidates('supplier')->count(),
            ],
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

        ActivityLog::log('contacts', 'created', "Added contact \"{$contact->name}\"", $contact);

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

        $records = $contact->records()
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->each(fn ($record) => $record->formatted_created_at = $record->created_at?->format('M j, Y, g:i A'));

        return Inertia::render('contacts/Show', [
            'contact' => $contact,
            'totalSpent' => $contact->totalSpent(),
            'orderCount' => $contact->orderCount(),
            'recentDocuments' => $recentDocuments,
            'records' => $records,
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

        // Capture a readable diff before overwriting, so the activity log
        // says what actually changed rather than just "contact updated".
        $changes = [];
        foreach ($validated as $field => $newValue) {
            $oldValue = $contact->getAttribute($field);
            if ((string) $oldValue !== (string) $newValue) {
                $changes[] = $field;
            }
        }

        $contact->update($validated);

        if ($changes) {
            ActivityLog::log(
                'contacts',
                'updated',
                "Updated contact \"{$contact->name}\" (" . implode(', ', $changes) . ')',
                $contact
            );
        }

        return redirect()->route('contacts.show', ['contact' => $contact->id])
            ->with('success', "\"{$contact->name}\" updated.");
    }

    public function destroy(Contact $contact)
    {
        if ($contact->documents()->exists()) {
            $contact->update(['is_active' => false]);

            ActivityLog::log('contacts', 'deactivated', "Deactivated contact \"{$contact->name}\" (has order history)", $contact);

            return redirect()->route('contacts.index')
                ->with('success', "\"{$contact->name}\" has order history, so it was deactivated instead of deleted.");
        }

        $name = $contact->name;
        ActivityLog::log('contacts', 'deleted', "Deleted contact \"{$name}\"");
        $contact->delete();

        return redirect()->route('contacts.index')->with('success', "\"{$name}\" deleted.");
    }

    /**
     * Converts an existing contact (customer, supplier, or anyone) into a
     * candidate for the given track. Their current type is preserved on
     * the model so it can be restored if the application is rejected.
     */
    public function applyAsCandidate(Request $request, Contact $contact)
    {
        $validated = $request->validate([
            'track' => ['required', 'in:employee,supplier'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $previousType = $contact->type;
        $contact->applyAsCandidate($validated['track'], $validated['notes'] ?? null);

        ActivityLog::log(
            'contacts',
            'candidate_applied',
            "\"{$contact->name}\" is now a candidate ({$validated['track']} track, was {$previousType})",
            $contact
        );

        return redirect()->back()->with('success', "\"{$contact->name}\" moved into the candidate pipeline.");
    }

    public function advanceCandidateStage(Request $request, Contact $contact)
    {
        abort_unless($contact->type === 'candidate', 422, 'This contact is not a candidate.');

        $validated = $request->validate([
            'stage' => ['required', 'in:applied,reviewing,interview'],
        ]);

        $contact->advanceStage($validated['stage']);

        ActivityLog::log(
            'contacts',
            'candidate_stage_changed',
            "\"{$contact->name}\" moved to \"{$validated['stage']}\" stage",
            $contact
        );

        return redirect()->back()->with('success', 'Candidate stage updated.');
    }

    /**
     * Hires the candidate — flips their type to employee or supplier
     * depending on their track. From here, the employee 5-tab profile
     * (Work/Resume/Personal/Badges/History) becomes the relevant view.
     */
    public function hireCandidate(Contact $contact)
    {
        abort_unless($contact->type === 'candidate', 422, 'This contact is not a candidate.');

        $track = $contact->track;
        $contact->hire();

        ActivityLog::log(
            'contacts',
            'candidate_hired',
            "\"{$contact->name}\" was hired as {$track}",
            $contact
        );

        return redirect()->route('contacts.show', ['contact' => $contact->id])
            ->with('success', "\"{$contact->name}\" has been hired.");
    }

    /**
     * Rejects the candidate — restores their previous type if they had
     * one, or leaves them as a rejected candidate record for reference.
     */
    public function rejectCandidate(Contact $contact)
    {
        abort_unless($contact->type === 'candidate', 422, 'This contact is not a candidate.');

        $track = $contact->track;
        $contact->reject();

        ActivityLog::log(
            'contacts',
            'candidate_rejected',
            "\"{$contact->name}\"'s {$track} application was rejected",
            $contact
        );

        return redirect()->back()->with('success', "\"{$contact->name}\"'s application was rejected.");
    }

    /**
     * Free-text ledger entries per contact (e.g. "Taken 5 rupees from the
     * counter") — a running log distinct from formal POS orders, with an
     * optional amount for tracking running tabs/balances.
     */
    public function storeRecord(Request $request, Contact $contact)
    {
        $validated = $request->validate([
            'description' => ['required', 'string', 'max:500'],
            'amount' => ['nullable', 'numeric'],
        ]);

        $record = $contact->records()->create([
            ...$validated,
            'user_id' => $request->user()->id,
        ]);

        ActivityLog::log(
            'contacts',
            'record_added',
            "Added record for \"{$contact->name}\": {$record->description}",
            $contact
        );

        return redirect()->route('contacts.show', ['contact' => $contact->id])
            ->with('success', 'Record added.');
    }

    public function updateRecord(Request $request, Contact $contact, ContactRecord $record)
    {
        abort_unless($record->contact_id === $contact->id, 404);

        $validated = $request->validate([
            'description' => ['required', 'string', 'max:500'],
            'amount' => ['nullable', 'numeric'],
        ]);

        $record->update($validated);

        ActivityLog::log(
            'contacts',
            'record_updated',
            "Updated record for \"{$contact->name}\": {$record->description}",
            $contact
        );

        return redirect()->route('contacts.show', ['contact' => $contact->id])
            ->with('success', 'Record updated.');
    }

    public function destroyRecord(Contact $contact, ContactRecord $record)
    {
        abort_unless($record->contact_id === $contact->id, 404);

        $description = $record->description;
        $record->delete();

        ActivityLog::log(
            'contacts',
            'record_deleted',
            "Deleted record for \"{$contact->name}\": {$description}",
            $contact
        );

        return redirect()->route('contacts.show', ['contact' => $contact->id])
            ->with('success', 'Record deleted.');
    }

    public function history(Request $request): Response
    {
        $activity = ActivityLog::where('app', 'contacts')
            ->with('user:id,name')
            ->when($request->action, fn ($q, $action) => $q->where('action', $action))
            ->when($request->search, fn ($q, $search) => $q->where('description', 'like', "%{$search}%"))
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        $activity->getCollection()->transform(function (ActivityLog $row) {
            $row->formatted_created_at = $row->created_at?->format('M j, Y, g:i A');
            return $row;
        });

        return Inertia::render('contacts/History', [
            'activity' => $activity,
            'filters' => $request->only(['action', 'search']),
        ]);
    }

    private function validateContact(Request $request, ?int $contactId = null): array
    {
        return $request->validate([
            'type' => ['required', 'in:customer,supplier,employee,candidate'],
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