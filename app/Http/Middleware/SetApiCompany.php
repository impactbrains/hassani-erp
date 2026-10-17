<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * API requests carry a bearer token instead of a session. The token belongs
 * either to an API client (the e-commerce website/app) or to a staff user
 * (the rider app); its owner decides the company for the request.
 */
final class SetApiCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $previous = CurrentCompany::id();
        $token = $request->bearerToken();
        if ($token) {
            $record = PersonalAccessToken::findToken($token);
            if ($record) {
                $table = match ($record->tokenable_type) {
                    ApiClient::class, (new ApiClient)->getMorphClass() => 'api_clients',
                    User::class, (new User)->getMorphClass() => 'users',
                    default => null,
                };
                $companyId = $table ? DB::table($table)->where('id', $record->tokenable_id)->value('company_id') : null;
                if ($companyId) {
                    CurrentCompany::set((int) $companyId);
                }
            }
        }

        try {
            return $next($request);
        } finally {
            $previous === null ? CurrentCompany::clear() : CurrentCompany::set($previous);
        }
    }
}
