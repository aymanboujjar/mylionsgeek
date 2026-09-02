<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates mobile API training-management mutations.
 *
 * Same roles as web admin training routes: admin, super_admin, moderateur, coach.
 * studio_responsable is intentionally excluded (view/attendance staff only).
 */
class EnsureTrainingManagementRole
{
    public const ROLES = [
        'admin',
        'super_admin',
        'moderateur',
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
