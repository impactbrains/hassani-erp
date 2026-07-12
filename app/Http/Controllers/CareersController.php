<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Contact;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CareersController extends Controller
{
    /**
     * Public landing page — links to the job application and supplier
     * application forms. No auth required.
     */
    public function index(): Response
    {
        return Inertia::render('careers/Index');
    }

    public function showJobApplication(): Response
    {
        return Inertia::render('careers/JobApplication');
    }

    /**
     * Creates a fresh Contact directly as a candidate (track=employee).
     * Since this contact has no prior relationship with the business,
     * previous_type stays null — there's nothing to revert to if rejected.
     */
    public function submitJobApplication(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'city' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $contact = Contact::create([
            'type' => 'candidate',
            'track' => 'employee',
            'stage' => 'applied',
            'previous_type' => null,
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'],
            'city' => $validated['city'] ?? null,
            'application_notes' => $validated['notes'] ?? null,
            'applied_at' => now(),
            'is_active' => true,
        ]);

        ActivityLog::log(
            'contacts',
            'candidate_applied',
            "\"{$contact->name}\" submitted a public job application",
            $contact
        );

        return Inertia::render('careers/ApplicationSubmitted', [
            'type' => 'job',
        ]);
    }

    public function showSupplierApplication(): Response
    {
        return Inertia::render('careers/SupplierApplication');
    }

    public function submitSupplierApplication(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'city' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $contact = Contact::create([
            'type' => 'candidate',
            'track' => 'supplier',
            'stage' => 'applied',
            'previous_type' => null,
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'],
            'city' => $validated['city'] ?? null,
            'tax_number' => $validated['tax_number'] ?? null,
            'application_notes' => $validated['notes'] ?? null,
            'applied_at' => now(),
            'is_active' => true,
        ]);

        ActivityLog::log(
            'contacts',
            'candidate_applied',
            "\"{$contact->name}\" submitted a public supplier application",
            $contact
        );

        return Inertia::render('careers/ApplicationSubmitted', [
            'type' => 'supplier',
        ]);
    }
}