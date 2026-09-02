<?php

use App\Http\Controllers\API\EventsInfoProxyController;
use Illuminate\Support\Facades\Route;

/*
 * Proxy routes for the public LionsGeek site (lionsgeek.ma).
 *
 * Incoming callers authenticate with Sanctum. Authorization is applied per
 * group (public-event users vs admin/access_scan staff). Upstream lionsgeek.ma
 * is still called with the server-side LIONSGEEK_MA_API_KEY — never a client key.
 *
 * Mounted under the global /api prefix => final paths are /api/events-info/*.
 */
Route::prefix('events-info')->group(function () {
    Route::middleware(['auth:sanctum', 'throttle:events-info-read'])->group(function () {
        Route::get('/events', [EventsInfoProxyController::class, 'events']);
        Route::get('/events/{event}', [EventsInfoProxyController::class, 'event'])->where('event', '[0-9]+');
        Route::get('/images/events/{cover}', [EventsInfoProxyController::class, 'eventCover'])->where('cover', '.*');
    });

    Route::middleware(['auth:sanctum', 'throttle:events-info-book'])->group(function () {
        Route::post('/booking/store', [EventsInfoProxyController::class, 'storeBooking']);
    });

    Route::middleware(['auth:sanctum', 'events.info.scan', 'throttle:events-info-scan'])->group(function () {
        Route::put('/validate-event-invitation', [EventsInfoProxyController::class, 'validateEventInvitation']);
        Route::put('/manual-event-checking', [EventsInfoProxyController::class, 'manualEventChecking']);
        Route::get('/lionsgate/infosessions', [EventsInfoProxyController::class, 'infoSessions']);
        Route::get('/session-data', [EventsInfoProxyController::class, 'sessionData']);
        Route::put('/validate-invitation', [EventsInfoProxyController::class, 'validateInvitation']);
        Route::put('/manual-checking', [EventsInfoProxyController::class, 'manualChecking']);
        Route::get('/profile-data', [EventsInfoProxyController::class, 'profileData']);
        Route::post('/session-photo', [EventsInfoProxyController::class, 'sessionPhoto']);
        Route::get('/images/participants/{photo}', [EventsInfoProxyController::class, 'participantPhoto'])->where('photo', '.*');
    });
});
