<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Identity\UserAccountService;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\LoginEvent;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff logins. Accounts are created here (no public sign-up); the new user
 * gets a one-time temporary password to change at first sign-in. Roles can
 * be limited to one store and to a date (temporary cover).
 */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $this->allow($request, 'settings.users.view');
        $q = trim((string) $request->query('q'));

        return Inertia::render('settings/Users', [
            'users' => User::query()->inCurrentCompany()->with(['roleAssignments.role:id,name,is_owner', 'roleAssignments.store:id,name', 'employee:id,user_id,name'])
                ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'ilike', "%{$q}%")->orWhere('email', 'ilike', "%{$q}%")))
                ->when($request->query('status'), fn ($w, $s) => $w->where('status', $s))
                ->orderBy('name')->paginate(50)->withQueryString(),
            'filters' => $request->only(['q', 'status']),
            'canManage' => $request->user()->hasPermission('settings.users.manage'),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->allow($request, 'settings.users.manage');

        return Inertia::render('settings/UserForm', ['user' => null] + $this->formData());
    }

    public function store(Request $request, UserAccountService $accounts): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:190'], 'locale' => ['nullable', 'in:en,ur'], 'employee_id' => ['nullable', 'integer'], 'roles' => ['required', 'array', 'min:1'], 'roles.*.role_id' => ['required', 'integer'], 'roles.*.store_id' => ['nullable', 'integer'], 'roles.*.expires_at' => ['nullable', 'date', 'after:now'], 'roles.*.reason' => ['nullable', 'string', 'max:200']]);
        $result = $accounts->create($request->user(), $data, $data['roles']);

        return redirect()->route('settings.users.show', $result['user'])->with('secret', ['label' => 'Temporary password for '.$result['user']->email, 'value' => $result['temporary_password'], 'note' => 'Give this to the person directly. It is shown only once and must be changed at first sign-in.']);
    }

    public function show(Request $request, User $user, \App\Domain\Identity\RoleService $roles): Response
    {
        $this->allow($request, 'settings.users.view');
        abort_unless($user->company_id === $request->user()->company_id, 404);

        return Inertia::render('settings/UserShow', [
            'account' => $user->load('roleAssignments.role:id,name,is_owner', 'roleAssignments.store:id,name', 'roleAssignments.granter:id,name', 'employee:id,user_id,name')->only(['id', 'name', 'email', 'status', 'locale', 'last_login_at', 'last_activity_at', 'deactivated_at', 'must_change_password', 'two_factor_confirmed_at', 'roleAssignments', 'employee']) + ['has_pin' => $user->pin_hash !== null],
            'effective' => collect($user->roleAssignments)->map(fn ($a) => $a->role)->filter()->unique('id')->mapWithKeys(fn ($r) => [$r->name => $roles->summary($r)]),
            'sessions' => DB::table('sessions')->where('user_id', $user->id)->orderByDesc('last_activity')->get(['id', 'ip_address', 'user_agent', 'last_activity']),
            'logins' => LoginEvent::query()->where('user_id', $user->id)->latest('id')->limit(20)->get(['event', 'ip_address', 'user_agent', 'created_at']),
            'canManage' => $request->user()->hasPermission('settings.users.manage'),
            'isSelf' => $request->user()->id === $user->id,
        ] + $this->formData());
    }

    public function update(Request $request, User $user, UserAccountService $accounts): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'locale' => ['nullable', 'in:en,ur'], 'roles' => ['required', 'array'], 'roles.*.role_id' => ['required', 'integer'], 'roles.*.store_id' => ['nullable', 'integer'], 'roles.*.expires_at' => ['nullable', 'date'], 'roles.*.reason' => ['nullable', 'string', 'max:200']]);
        $this->allow($request, 'settings.users.manage');
        abort_unless($user->company_id === $request->user()->company_id, 404);
        $user->update(['name' => $data['name'], 'locale' => $data['locale'] ?? $user->locale]);
        if ($request->user()->id !== $user->id) {
            $accounts->assignRoles($request->user(), $user, $data['roles']);
        }

        return back()->with('success', 'Saved.');
    }

    public function action(Request $request, User $user, UserAccountService $accounts): RedirectResponse
    {
        abort_unless($user->company_id === $request->user()->company_id, 404);
        $data = $request->validate(['action' => ['required', 'in:suspend,deactivate,reactivate,reset_password,revoke_sessions,revoke_session,link_employee'], 'reason' => ['nullable', 'string', 'max:300'], 'session_id' => ['nullable', 'string'], 'employee_id' => ['nullable', 'integer']]);
        $actor = $request->user();
        switch ($data['action']) {
            case 'suspend': $accounts->suspend($actor, $user, $data['reason'] ?: 'Suspended');
                break;
            case 'deactivate': $accounts->deactivate($actor, $user, $data['reason'] ?: 'Deactivated');
                break;
            case 'reactivate': $accounts->reactivate($actor, $user);
                break;
            case 'reset_password':
                $temporary = $accounts->resetPassword($actor, $user);

                return back()->with('secret', ['label' => 'New temporary password for '.$user->email, 'value' => $temporary, 'note' => 'Shown only once. They must change it at sign-in. All their devices were signed out.']);
            case 'revoke_sessions': $this->allow($request, 'settings.security.manage');
                $accounts->revokeAccess($user, 'Signed out by '.$actor->name);
                break;
            case 'revoke_session': $accounts->revokeSession($actor, $user, (string) $data['session_id']);
                break;
            case 'link_employee': $accounts->linkEmployee($actor, $user, $data['employee_id'] ?? null);
                break;
        }

        return back()->with('success', 'Done.');
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        return [
            'roles' => Role::query()->orderByDesc('is_owner')->orderBy('name')->get(['id', 'key', 'name', 'description', 'is_owner']),
            'stores' => Store::query()->where('is_active', true)->get(['id', 'name']),
            'employees' => Contact::query()->employees()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'user_id']),
        ];
    }
}
