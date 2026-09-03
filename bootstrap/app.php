<?php

use App\Http\Middleware\EnsureOnSchoolNetwork;
use App\Http\Middleware\EnsureOrganisationOnboarded;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\UpdateLastOnline;
use App\Http\Middleware\UpdateRolesUsers;
use App\Http\Middleware\AuthorizeStudentInvite;
use App\Http\Middleware\EnsureEventsInfoScanAccess;
use App\Http\Middleware\EnsureAttendanceStaffRole;
use App\Http\Middleware\EnsureTrainingManagementRole;
use App\Http\Middleware\VerifyLearning;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Trust only explicitly configured reverse proxies. Empty = none (use REMOTE_ADDR).
        // Set TRUSTED_PROXIES=127.0.0.1 for local nginx/Caddy, or comma-separated CIDRs
        // for a load balancer. Use * only for local tunnels (accepts X-Forwarded-For spoof risk).
        $trustedProxiesRaw = env('TRUSTED_PROXIES');
        $trustedProxies = match (true) {
            $trustedProxiesRaw === null, $trustedProxiesRaw === '' => [],
            trim((string) $trustedProxiesRaw) === '*' => '*',
            default => array_values(array_filter(array_map('trim', explode(',', (string) $trustedProxiesRaw)))),
        };

        $middleware->trustProxies(
            at: $trustedProxies,
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT
        );

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // hna  alias  ta3 l middlware
        $middleware->alias(([
            'role' => RoleMiddleware::class,
            'organisation.onboarded' => EnsureOrganisationOnboarded::class,
            'school.network' => EnsureOnSchoolNetwork::class,
            'learning' => VerifyLearning::class,
            'invite.student' => AuthorizeStudentInvite::class,
            'events.info.scan' => EnsureEventsInfoScanAccess::class,
            'training.manage' => EnsureTrainingManagementRole::class,
            'attendance.staff' => EnsureAttendanceStaffRole::class,
        ]));

        $middleware->web(append: [
            HandleAppearance::class,
            UpdateRolesUsers::class,
            // UpdateLastOnline::class,
            EnsureOrganisationOnboarded::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Handle 419 CSRF token expired errors for Inertia requests
        $exceptions->render(function (\Illuminate\Session\TokenMismatchException $e, \Illuminate\Http\Request $request) {
            if ($request->expectsJson() || $request->header('X-Inertia')) {
                return response()->json([
                    'message' => 'CSRF token expired. Please refresh the page and try again.',
                    'errors' => ['csrf' => ['CSRF token expired. Please refresh the page.']],
                ], 419);
            }
        });

        // Web (non-API): friendly Inertia error page when an action fails (production only).
        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                return null;
            }

            if ($e instanceof ValidationException || $e instanceof AuthenticationException) {
                return null;
            }

            if ($e instanceof \Illuminate\Session\TokenMismatchException) {
                return null;
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            // Unknown URLs (e.g. /test): full-screen page without app shell, even when APP_DEBUG is true.
            if ($status === 404) {
                return Inertia::render('NotFound', [
                    'message' => __('The page you are looking for could not be found.'),
                ])->toResponse($request)->setStatusCode(404);
            }

            if (config('app.debug')) {
                return null;
            }

            $message = match (true) {
                $status === 403 => __('You do not have permission to perform this action.'),
                $status === 419 => __('Your session has expired. Please refresh the page and try again.'),
                $status === 429 => __('Too many requests. Please wait a moment and try again.'),
                $status === 503 => __('The application is temporarily unavailable. Please try again later.'),
                default => __('Something went wrong while processing your request. Please try again.'),
            };

            Log::warning($e->getMessage(), [
                'exception' => $e::class,
                'status' => $status,
                'url' => $request->fullUrl(),
            ]);

            return Inertia::render('Error', [
                'status' => $status,
                'message' => $message,
            ])->toResponse($request)->setStatusCode($status);
        });
    })->create();
