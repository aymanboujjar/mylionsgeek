<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff gate for /api/events-info check-in and PII routes.
 *
 * Matches mobile userCanAccessScan: role `admin` OR access_scan on the
 * authenticated Sanctum user. Client-supplied role/access_scan fields are ignored.
 */
class EnsureEventsInfoScanAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum') ?? $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if (! $user->canAccessEventsScan()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
