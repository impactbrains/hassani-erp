<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Task;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    /**
     * Every staff member can view the task list, but non-admins only
     * see tasks that are theirs — either assigned to them or created
     * by them — never the full company-wide list. Admins see
     * everything, no filter applied.
     */
    public function index(Request $request): Response
    {
        $isAdmin = (bool) ($request->user()->is_admin ?? false);
        $myContactId = $request->user()->contact?->id;

        $tasks = Task::query()
            ->with(['assignees:id,name', 'createdBy:id,name'])
            ->when(!$isAdmin, function ($q) use ($myContactId) {
                // Non-admins only ever see their own tasks — assigned to
                // them, or created by them — regardless of what filters
                // they pass in. This is a hard visibility boundary, not
                // just a default, so it's applied unconditionally here
                // rather than as an optional ->when($request->x) filter.
                $q->where(function ($sub) use ($myContactId) {
                    if ($myContactId === null) {
                        // No linked Contact at all (shouldn't normally
                        // happen since every User gets one on creation,
                        // but fail closed rather than showing everything
                        // if it somehow does) — show nothing.
                        $sub->whereRaw('1 = 0');
                        return;
                    }
                    $sub->assignedTo($myContactId)
                        ->orWhere('created_by_contact_id', $myContactId);
                });
            })
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->priority, fn ($q, $priority) => $q->where('priority', $priority))
            ->when($isAdmin && $request->assigned_to, fn ($q, $contactId) => $q->assignedTo((int) $contactId))
            ->when($request->search, fn ($q, $search) => $q->where('title', 'like', "%{$search}%"))
            ->orderByRaw("CASE status WHEN 'to_do' THEN 0 WHEN 'in_progress' THEN 1 WHEN 'done' THEN 2 ELSE 3 END")
            ->orderByRaw('due_date IS NULL, due_date asc')
            ->paginate(30)
            ->withQueryString();

        // Per-row flags, computed here rather than duplicating auth
        // logic in Vue — the frontend has no reliable way to know the
        // current user's own contact_id otherwise, and re-deriving
        // permission rules client-side is exactly the kind of thing
        // that quietly drifts out of sync with the real server-side
        // rule over time.
        //  - can_manage: full edit/delete (admin or the task's creator)
        //  - can_update_status: the looser status-only check (admin,
        //    an assignee, or the creator) — matches updateStatus()'s
        //    own authorization rule exactly.
        $tasks->getCollection()->transform(function (Task $task) use ($isAdmin, $myContactId) {
            $isCreator = $myContactId !== null && $task->created_by_contact_id === $myContactId;
            $isAssignee = $myContactId !== null && $task->assignees->contains('id', $myContactId);

            $task->can_manage = $isAdmin || $isCreator;
            $task->can_update_status = $isAdmin || $isAssignee || $isCreator;
            return $task;
        });

        return Inertia::render('tasks/Index', [
            'tasks' => $tasks,
            'filters' => $request->only(['status', 'priority', 'assigned_to', 'search']),
            // The "assigned to" filter dropdown only makes sense for
            // admins (who can see everyone's tasks) — non-admins only
            // ever see their own anyway, so there's nothing to filter by.
            'employees' => $isAdmin
                ? Contact::employees()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
            'canManage' => $isAdmin,
            'canCreateOwn' => $myContactId !== null,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('tasks/Form', [
            'task' => null,
            'employees' => Contact::employees()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateTask($request);
        $assigneeIds = $validated['assignee_ids'];
        unset($validated['assignee_ids']);

        $task = Task::create([
            ...$validated,
            'created_by_contact_id' => $request->user()->contact?->id,
        ]);

        $task->assignees()->sync($assigneeIds);

        ActivityLog::log(
            'tasks',
            'created',
            "Created task \"{$task->title}\" (assigned to " . $task->assignees()->pluck('name')->join(', ') . ')',
            $task
        );

        return redirect()->route('todo.index')->with('success', "\"{$task->title}\" created.");
    }

    /**
     * Self-service creation form — any staff member with todo access,
     * not just admins. No employees list needed here since the
     * assignee is always just themselves (see storeSelf below), unlike
     * create()'s full multi-assignee picker.
     */
    public function createOwn(): Response
    {
        return Inertia::render('tasks/FormOwn');
    }

    /**
     * Self-service task creation — deliberately a SEPARATE endpoint
     * from store(), not a shared one with a permission branch inside
     * it. The assignee is hard-set to the current user's own Contact
     * id here in the controller, never read from the request — so even
     * if someone tampers with the request body to include other
     * contact_ids, they physically cannot assign a task to anyone but
     * themselves through this route. Admins wanting to assign to
     * others still go through store() above.
     */
    public function storeSelf(Request $request)
    {
        $myContactId = $request->user()->contact?->id;
        abort_unless($myContactId !== null, 403, 'No linked employee record for this account.');

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', 'in:low,medium,high'],
            'due_date' => ['nullable', 'date'],
            'due_time' => ['nullable', 'date_format:H:i'],
        ]);

        $task = Task::create([
            ...$validated,
            'status' => 'to_do',
            'created_by_contact_id' => $myContactId,
        ]);

        $task->assignees()->sync([$myContactId]);

        ActivityLog::log('tasks', 'created', "Created task \"{$task->title}\" for themselves", $task);

        return redirect()->route('todo.index')->with('success', "\"{$task->title}\" created.");
    }

    /**
     * Reachable by any staff member at the route level, but only an
     * admin or the task's own creator may actually load the edit form
     * — everyone else gets a 403, even if they're an assignee. Being
     * assigned a task lets you change its status (see updateStatus),
     * not rewrite its title/description/assignees/due date.
     */
    public function edit(Request $request, Task $task): Response
    {
        $this->authorizeManage($request, $task);

        $task->load('assignees:id,name');

        return Inertia::render('tasks/Form', [
            'task' => $task,
            'employees' => Contact::employees()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, Task $task)
    {
        $this->authorizeManage($request, $task);

        $validated = $this->validateTask($request);
        $assigneeIds = $validated['assignee_ids'];
        unset($validated['assignee_ids']);

        $oldStatus = $task->status;
        $task->update($validated);
        $task->assignees()->sync($assigneeIds);

        if ($oldStatus !== $task->status) {
            ActivityLog::log('tasks', 'status_changed', "Task \"{$task->title}\" moved to " . str_replace('_', ' ', $task->status), $task);
        } else {
            ActivityLog::log('tasks', 'updated', "Updated task \"{$task->title}\"", $task);
        }

        return redirect()->route('todo.index')->with('success', "\"{$task->title}\" updated.");
    }

    /**
     * Lightweight status-only update, for the quick "mark done" /
     * drag-free status change from the list view — avoids sending the
     * whole edit form's payload just to flip one field.
     *
     * Gated to admins, the task's assignees, or its creator — NOT open
     * to any staff member with todo access generally. Route middleware
     * alone (app.access:todo) only checks "can this person use the todo
     * app at all", not "is this THEIR task" — that check has to happen
     * here, per-task, or any staff member could flip the status on
     * someone else's task just by knowing/guessing its ID.
     */
    public function updateStatus(Request $request, Task $task)
    {
        $isAdmin = (bool) ($request->user()->is_admin ?? false);
        $myContactId = $request->user()->contact?->id;

        $isAssignee = $myContactId !== null && $task->assignees()->where('contacts.id', $myContactId)->exists();
        $isCreator = $myContactId !== null && $task->created_by_contact_id === $myContactId;

        abort_unless($isAdmin || $isAssignee || $isCreator, 403);

        $validated = $request->validate([
            'status' => ['required', 'in:to_do,in_progress,done,cancelled'],
        ]);

        $task->update($validated);

        ActivityLog::log('tasks', 'status_changed', "Task \"{$task->title}\" moved to " . str_replace('_', ' ', $task->status), $task);

        return back()->with('success', 'Status updated.');
    }

    public function destroy(Request $request, Task $task)
    {
        $this->authorizeManage($request, $task);

        $title = $task->title;
        $task->delete();

        ActivityLog::log('tasks', 'deleted', "Deleted task \"{$title}\"");

        return redirect()->route('todo.index')->with('success', "\"{$title}\" deleted.");
    }

    /**
     * Shared "can this person edit/delete this specific task" check,
     * used by edit(), update(), and destroy(). Admins can manage any
     * task; everyone else can only manage tasks THEY created — being
     * an assignee is not enough (that only grants status changes, via
     * updateStatus's separate, looser check).
     */
    private function authorizeManage(Request $request, Task $task): void
    {
        $isAdmin = (bool) ($request->user()->is_admin ?? false);
        $myContactId = $request->user()->contact?->id;
        $isCreator = $myContactId !== null && $task->created_by_contact_id === $myContactId;

        abort_unless($isAdmin || $isCreator, 403);
    }

    private function validateTask(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', 'in:to_do,in_progress,done,cancelled'],
            'priority' => ['required', 'in:low,medium,high'],
            'due_date' => ['nullable', 'date'],
            'due_time' => ['nullable', 'date_format:H:i'],
            'assignee_ids' => ['required', 'array', 'min:1'],
            'assignee_ids.*' => ['exists:contacts,id'],
        ]);
    }
}