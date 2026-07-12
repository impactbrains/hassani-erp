<?php

namespace App\Http\Controllers;

use App\Models\Badge;
use App\Models\Contact;
use App\Models\Department;
use App\Models\EmployeeDetail;
use App\Models\EmployeeResumeItem;
use App\Models\EmployeeWorkSchedule;
use App\Models\JobPosition;
use App\Models\Media;
use App\Models\Skill;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Handles the employee profile screen's 4 "light" tabs (Work, Resume,
 * Personal, Badges) as a single Inertia page — all four are cheap
 * relations on one Contact, so there's no benefit to separate requests
 * per tab, and it avoids a loading flicker every time the user clicks
 * between them.
 *
 * History is deliberately NOT loaded here — see historyIndex() below,
 * fetched lazily only when that tab is opened, since ActivityLog rows
 * for a long-tenured employee could be a lot of data and there's no
 * reason to pay for it on every profile page load.
 *
 * This controller only handles contacts where type=employee — it does
 * not replace ContactController, which still handles customers/suppliers
 * and the base show/edit/index for all contact types. Route this under
 * a dedicated `employees.` prefix, see routes file.
 */
class EmployeeController extends Controller
{
    public function show(Contact $contact): Response
    {
        abort_unless($contact->type === 'employee', 404);

        $contact->load([
            'employeeDetail.department',
            'employeeDetail.jobPosition',
            'employeeDetail.manager:id,name',
            'employeeDetail.resumeFile',
            'employeeDetail.visaDocument',
            'employeeDetail.idCard',
            'employeeDetail.drivingLicense',
            'resumeItems',
            'skills',
            'badges',
        ]);

        return Inertia::render('contacts/employee/Show', [
            'contact' => $contact,
            'workSchedule' => EmployeeWorkSchedule::weekFor($contact->id),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'jobPositions' => JobPosition::orderBy('name')->get(['id', 'name']),
            // Managers = any other active employee, excluding this contact
            // itself (can't be your own manager).
            'possibleManagers' => Contact::employees()
                ->where('id', '!=', $contact->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'allSkills' => Skill::orderBy('name')->get(['id', 'name']),
            'allBadges' => Badge::orderBy('name')->get(['id', 'name', 'icon', 'color']),
        ]);
    }

    /**
     * Work tab: department/position/title/manager/location/note.
     * Uses updateOrCreate since employee_details may not exist yet for
     * a freshly-created employee contact.
     */
    public function updateWork(Request $request, Contact $contact)
    {
        abort_unless($contact->type === 'employee', 404);

        $validated = $request->validate([
            'department_id' => ['nullable', 'exists:departments,id'],
            'job_position_id' => ['nullable', 'exists:job_positions,id'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'manager_contact_id' => ['nullable', 'exists:contacts,id'],
            'work_address' => ['nullable', 'string', 'max:255'],
            'work_location' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        EmployeeDetail::updateOrCreate(['contact_id' => $contact->id], $validated);

        \App\Models\ActivityLog::log('contacts', 'employee_work_updated', "Updated work details for \"{$contact->name}\"", $contact);

        return back()->with('success', 'Work details updated.');
    }

    /**
     * Timing + Usual Work Location tab. Expects all 7 days in one
     * request (the frontend renders a Mon-Sun table and submits the
     * whole week together, rather than one save button per day).
     */
    public function updateSchedule(Request $request, Contact $contact)
    {
        abort_unless($contact->type === 'employee', 404);

        $validated = $request->validate([
            'days' => ['required', 'array', 'size:7'],
            'days.*.day' => ['required', 'integer', 'between:0,6'],
            'days.*.is_working_day' => ['required', 'boolean'],
            'days.*.start_time' => ['nullable', 'date_format:H:i'],
            'days.*.end_time' => ['nullable', 'date_format:H:i'],
            'days.*.work_location' => ['nullable', 'string', 'max:255'],
        ]);

        // Laravel's after: rule doesn't resolve a wildcard on its right
        // side per-row (days.*.end_time after:days.*.start_time compares
        // against the literal string "days.*.start_time", not each row's
        // own value), so the start/end relationship is checked manually
        // here instead of trusting a rule that would silently never fire.
        foreach ($validated['days'] as $index => $day) {
            if ($day['start_time'] && $day['end_time'] && $day['end_time'] <= $day['start_time']) {
                return back()->withErrors([
                    "days.{$index}.end_time" => 'End time must be after start time.',
                ]);
            }
        }

        foreach ($validated['days'] as $day) {
            EmployeeWorkSchedule::updateOrCreate(
                ['contact_id' => $contact->id, 'day' => $day['day']],
                $day
            );
        }

        \App\Models\ActivityLog::log('contacts', 'employee_schedule_updated', "Updated work schedule for \"{$contact->name}\"", $contact);

        return back()->with('success', 'Schedule updated.');
    }

    /**
     * Personal tab: private contact, personal info, emergency contact,
     * visa/permit, citizenship, location, family, education level, and
     * the two document slots (ID card, driving license).
     */
    public function updatePersonal(Request $request, Contact $contact)
    {
        abort_unless($contact->type === 'employee', 404);

        $validated = $request->validate([
            'private_email' => ['nullable', 'email', 'max:255'],
            'private_phone' => ['nullable', 'string', 'max:50'],
            'bank_account' => ['nullable', 'string', 'max:255'],

            'legal_name' => ['nullable', 'string', 'max:255'],
            'birthday' => ['nullable', 'date'],
            'place_of_birth' => ['nullable', 'string', 'max:255'],
            'birth_country' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', 'string', 'max:50'],

            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:50'],

            'visa_no' => ['nullable', 'string', 'max:100'],
            'work_permit_no' => ['nullable', 'string', 'max:100'],
            'visa_document_media_id' => ['nullable', 'exists:media,id'],

            'nationality' => ['nullable', 'string', 'max:255'],
            'identification_no' => ['nullable', 'string', 'max:100'],
            'ssn_no' => ['nullable', 'string', 'max:100'],
            'passport_no' => ['nullable', 'string', 'max:100'],

            'private_address' => ['nullable', 'string', 'max:500'],
            'home_work_distance' => ['nullable', 'string', 'max:100'],

            'marital_status' => ['nullable', 'string', 'max:50'],
            'dependent_children' => ['nullable', 'integer', 'min:0', 'max:50'],

            'certificate_level' => ['nullable', 'string', 'max:255'],
            'field_of_study' => ['nullable', 'string', 'max:255'],

            'id_card_media_id' => ['nullable', 'exists:media,id'],
            'driving_license_media_id' => ['nullable', 'exists:media,id'],
        ]);

        EmployeeDetail::updateOrCreate(['contact_id' => $contact->id], $validated);

        \App\Models\ActivityLog::log('contacts', 'employee_personal_updated', "Updated personal details for \"{$contact->name}\"", $contact);

        return back()->with('success', 'Personal details updated.');
    }

    /**
     * Resume tab — the uploaded resume file (separate from the
     * structured items below).
     */
    public function updateResumeFile(Request $request, Contact $contact)
    {
        abort_unless($contact->type === 'employee', 404);

        $validated = $request->validate([
            'resume_file_media_id' => ['nullable', 'exists:media,id'],
        ]);

        EmployeeDetail::updateOrCreate(['contact_id' => $contact->id], $validated);

        return back()->with('success', 'Resume file updated.');
    }

    /**
     * Resume tab — add one experience/education timeline entry. Matches
     * the screenshot's "ADD" button under "Other Experience".
     */
    public function storeResumeItem(Request $request, Contact $contact)
    {
        abort_unless($contact->type === 'employee', 404);

        $validated = $request->validate([
            'type' => ['required', 'in:experience,education'],
            'title' => ['required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'is_current' => ['boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $contact->resumeItems()->create([
            ...$validated,
            'sort_order' => $contact->resumeItems()->max('sort_order') + 1,
        ]);

        return back()->with('success', 'Added to resume.');
    }

    public function updateResumeItem(Request $request, Contact $contact, EmployeeResumeItem $resumeItem)
    {
        abort_unless($contact->type === 'employee', 404);
        abort_unless($resumeItem->contact_id === $contact->id, 404);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'is_current' => ['boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $resumeItem->update($validated);

        return back()->with('success', 'Resume entry updated.');
    }

    /**
     * Matches the screenshot's trash-can icon on each timeline entry.
     */
    public function destroyResumeItem(Contact $contact, EmployeeResumeItem $resumeItem)
    {
        abort_unless($contact->type === 'employee', 404);
        abort_unless($resumeItem->contact_id === $contact->id, 404);

        $resumeItem->delete();

        return back()->with('success', 'Resume entry removed.');
    }

    /**
     * Skills — matches the screenshot's "Pick a skill from the list"
     * button. Attaches an existing skill_id to this employee; creating
     * a brand-new skill (when missing from the library) is a separate
     * endpoint below, per the screenshot's "can be created by an HR
     * officer" note.
     */
    public function attachSkill(Request $request, Contact $contact)
    {
        abort_unless($contact->type === 'employee', 404);

        $validated = $request->validate([
            'skill_id' => ['required', 'exists:skills,id'],
            'level' => ['nullable', 'string', 'max:50'],
        ]);

        $contact->skills()->syncWithoutDetaching([
            $validated['skill_id'] => ['level' => $validated['level'] ?? null],
        ]);

        return back()->with('success', 'Skill added.');
    }

    public function detachSkill(Contact $contact, Skill $skill)
    {
        abort_unless($contact->type === 'employee', 404);

        $contact->skills()->detach($skill->id);

        return back()->with('success', 'Skill removed.');
    }

    /**
     * Creates a brand-new skill in the shared library (HR officer flow
     * from the screenshot's note), then immediately attaches it to this
     * employee so it's a one-step action from the frontend's point of
     * view rather than "create, then separately go attach it".
     */
    public function storeSkill(Request $request, Contact $contact)
    {
        abort_unless($contact->type === 'employee', 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $skill = Skill::firstOrCreate(['name' => $validated['name']]);
        $contact->skills()->syncWithoutDetaching([$skill->id]);

        return back()->with('success', "Skill \"{$skill->name}\" created and added.");
    }

    /**
     * Badges — admin assigns from the shared library. Same
     * create-vs-attach split as skills, minus the "create new" flow
     * since badges are curated by an admin, not grown ad hoc by every
     * HR officer (per the "Simple manual badges (name+icon, admin
     * assigns)" decision).
     */
    public function attachBadge(Request $request, Contact $contact)
    {
        abort_unless($contact->type === 'employee', 404);

        $validated = $request->validate([
            'badge_id' => ['required', 'exists:badges,id'],
        ]);

        $contact->badges()->syncWithoutDetaching([
            $validated['badge_id'] => ['awarded_at' => now()],
        ]);

        \App\Models\ActivityLog::log('contacts', 'employee_badge_awarded', "Awarded a badge to \"{$contact->name}\"", $contact);

        return back()->with('success', 'Badge awarded.');
    }

    public function detachBadge(Contact $contact, Badge $badge)
    {
        abort_unless($contact->type === 'employee', 404);

        $contact->badges()->detach($badge->id);

        return back()->with('success', 'Badge removed.');
    }

    /**
     * History tab — fetched lazily via a plain JSON endpoint (not part
     * of show() above) so a long-tenured employee's full activity
     * history doesn't load until the tab is actually opened.
     */
    public function historyIndex(Contact $contact)
    {
        abort_unless($contact->type === 'employee', 404);

        $activity = $contact->activityHistory()
            ->map(fn ($log) => [
                'id' => $log->id,
                'app' => $log->app,
                'action' => $log->action,
                'description' => $log->description,
                'user' => $log->user?->name,
                'created_at' => $log->created_at->format('M j, Y, g:i A'),
            ])
            ->values();

        return response()->json($activity);
    }
}