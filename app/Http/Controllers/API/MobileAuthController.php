<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class MobileAuthController extends Controller
{
    private const MOBILE_TOKEN_TTL_DAYS = 30;

    public function login(Request $request)
    {
        
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        if ($user->account_state) {
            return response()->json([
                'message' => 'Account disabled',
            ], 403);
        }

        $token = $user->createToken(
            'mobile',
            ['*'],
            now()->addDays(self::MOBILE_TOKEN_TTL_DAYS)
        )->plainTextToken;

        $user->forceFill(['last_online' => now()])->save();

        $roles = $user->normalizedRoles();

        // Return full user data except sensitive fields (password, tokens)
        $userData = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone ?? null,
            'cin' => $user->cin ?? null,
            'avatar' => $user->image ,
            'image' => $user->image ?? null, // Keep raw image path for reference
            'roles' => $roles, // Always array
            'role' => $roles, // Alias for compatibility
            'promo' => $user->promo ?? null,
            'status' => $user->status ?? null,
            'formation_id' => $user->primaryFormationId(),
            'formation_ids' => $user->resolvedFormationIds(),
            'account_state' => $user->account_state ?? 0,
            'state' => $user->account_state ?? 0, // Alias for compatibility
            'access_cowork' => $user->access_cowork ?? 0,
            'access_studio' => $user->access_studio ?? 0,
            'access_scan' => $user->access_scan ?? 0,
            'wakatime_api_key' => $user->wakatime_api_key ? substr($user->wakatime_api_key, 0, 10) . '...' : null, // Partially hidden for security
            'last_online' => $user->last_online ? (is_string($user->last_online) ? $user->last_online : $user->last_online->format('Y-m-d H:i:s')) : null,
            'created_at' => $user->created_at ? (is_string($user->created_at) ? $user->created_at : $user->created_at->toDateTimeString()) : null,
            'updated_at' => $user->updated_at ? (is_string($user->updated_at) ? $user->updated_at : $user->updated_at->toDateTimeString()) : null,
            // Excluded: password, remember_token, activation_token, email_verified_at
        ];

        return response()->json([
            'token' => $token,
            'user' => $userData,
        ]);
    }

    public function forgot(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        return response()->json([
            'status' => 'A reset link will be sent if the account exists.',
            'ok' => true,
        ]);
    }

    /**
     * Change password while authenticated (mobile). Requires current password.
     */
    public function updatePassword(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (!Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => [__('The provided password does not match your current password.')],
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($validated['password']),
        ])->save();

        $user->tokens()->delete();

        return response()->json([
            'message' => 'Password updated successfully. Please log in again.',
            'must_relogin' => true,
        ]);
    }

    public function logout(Request $request)
    {
        $bearer = $request->bearerToken();
        if ($bearer) {
            $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($bearer);
            if ($accessToken) {
                $accessToken->delete();
            }
        } else {
            $token = $request->user()?->currentAccessToken();
            if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
                $token->delete();
            }
        }

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }
}

