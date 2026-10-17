<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LoginEvent;
use App\Models\ReportExport;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Sign-in history, active sessions, the audit trail and the export log. */
class SecurityController extends Controller
{
    public function index(Request $request): Response
    {
        $this->allow($request, 'settings.security.view');
        $tab = $request->query('tab', 'audit');
        $q = trim((string) $request->query('q'));
        $company = CurrentCompany::require();

        $data = match ($tab) {
            'logins' => LoginEvent::query()->where(fn ($w) => $w->where('company_id', $company)->orWhere(fn ($x) => $x->whereNull('company_id')->whereIn('email', DB::table('users')->where('company_id', $company)->select('email'))))
                ->when($q !== '', fn ($w) => $w->where('email', 'ilike', "%{$q}%"))->when($request->query('event'), fn ($w, $e) => $w->where('event', $e))->latest('id')->paginate(50)->withQueryString(),
            'sessions' => DB::table('sessions')->join('users', 'users.id', '=', 'sessions.user_id')->where('users.company_id', $company)->orderByDesc('last_activity')
                ->select('sessions.id', 'sessions.ip_address', 'sessions.user_agent', 'sessions.last_activity', 'users.id as user_id', 'users.name', 'users.email')->paginate(50)->withQueryString(),
            'exports' => ReportExport::query()->with('user:id,name')->latest('id')->paginate(50)->withQueryString(),
            default => AuditLog::query()->with('user:id,name', 'store:id,name')
                ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('description', 'ilike', "%{$q}%")->orWhere('event', 'ilike', "%{$q}%")))
                ->when($request->query('user_id'), fn ($w, $u) => $w->where('user_id', $u))->when($request->query('event'), fn ($w, $e) => $w->where('event', 'like', $e.'%'))
                ->when($request->query('from'), fn ($w, $d) => $w->where('created_at', '>=', $d))->when($request->query('to'), fn ($w, $d) => $w->where('created_at', '<', \Carbon\CarbonImmutable::parse($d)->addDay()))
                ->latest('id')->paginate(50)->withQueryString(),
        };

        return Inertia::render('settings/Security', ['tab' => $tab, 'rows' => $data, 'filters' => $request->only(['q', 'event', 'user_id', 'from', 'to']), 'canManage' => $request->user()->hasPermission('settings.security.manage')]);
    }
}
