<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Staff sign-in for the rider app: returns a 30-day token limited to rider work. */
class AuthController extends ApiController
{
    public function token(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string'], 'device_name' => ['required', 'string', 'max:80']]);
        $user = User::query()->where('email', mb_strtolower($data['email']))->first();
        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password) || ! $user->isActive()) {
            LoginEvent::create(['company_id' => $user?->company_id, 'user_id' => $user?->id, 'email' => $data['email'], 'event' => 'api_login_failed', 'ip_address' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 500)]);
            throw ValidationException::withMessages(['email' => 'The e-mail or password is not correct, or the account is not active.']);
        }
        \App\Support\Tenancy\CurrentCompany::set($user->company_id);
        if (! $user->hasPermission('sales.deliveries.rider')) {
            abort(403, 'Only riders can sign in to the rider app.');
        }
        if ($user->must_change_password) {
            abort(403, 'Change your temporary password on the shop computer first.');
        }
        $token = $user->createToken('rider:'.$data['device_name'], ['rider'], now()->addDays(30));
        LoginEvent::create(['company_id' => $user->company_id, 'user_id' => $user->id, 'email' => $user->email, 'event' => 'api_login_succeeded', 'ip_address' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 500)]);

        return response()->json(['token' => $token->plainTextToken, 'expires_at' => $token->accessToken->expires_at, 'user' => ['id' => $user->id, 'name' => $user->name]]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }
}
