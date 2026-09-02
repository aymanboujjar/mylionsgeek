<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates mobile API staff attendance-management endpoints.
 *
 * Same roles as web attendance routes: admin|coach.
 * super_admin, moderateur, and studio_responsable are intentionally excluded.
 */
class EnsureAttendanceStaffRole
{
    public const ROLES = [
        'admin',
        'coach',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum') ?? $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if (! self::allows($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }

    public static function allows(User $user): bool
    {
        return (bool) array_intersect($user->normalizedRoles(), self::ROLES);
    }
}
