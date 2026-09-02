<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates POST /api/invite-student.
 *
 * Legitimate callers:
 *  - lionsgeek.ma server-to-server (LIONSGEEK_MA_API_KEY bearer), same pattern as
 *    the event-created webhook
 *  - Sanctum/session users with the same staff roles that can create users
 *    in the admin UI
 */
class AuthorizeStudentInvite
{
    public const STAFF_ROLES = [
        'admin',
        'super_admin',
        'moderateur',
        'coach',
        'pro',
        'studio_responsable',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->hasValidLionsgeekSharedKey($request)) {
            return $next($request);
        }

        $user = $request->user('sanctum') ?? $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if (! $this->isStaff($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }

    private function hasValidLionsgeekSharedKey(Request $request): bool
    {
        $stored = (string) config('services.lionsgeek.key');
        $bearer = (string) $request->bearerToken();

        if ($stored === '' || $bearer === '') {
            return false;
        }

        return hash_equals($stored, $bearer);
    }

    private function isStaff(User $user): bool
    {
        return (bool) array_intersect($user->normalizedRoles(), self::STAFF_ROLES);
    }
}
