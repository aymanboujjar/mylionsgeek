<?php

use App\Http\Controllers\API\SearchController as ApiSearchController;
use App\Http\Controllers\ReservationsController;
use App\Http\Controllers\UsersController;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('/users/{user}/resume', [UsersController::class, 'viewResume'])->name('users.resume.view');

    Route::post('/presence/ping', function (Request $request) {
        $user = $request->user();
        if (! $user) {
            return response()->json(['ok' => false], 401);
        }

        $shouldUpdate =
            ! $user->last_online
            || Carbon::parse($user->last_online)->lt(now()->subSeconds(55));

        if ($shouldUpdate) {
            $user->forceFill(['last_online' => now()])->save();
        }

        return response()->json(['ok' => true]);
    })->name('presence.ping');

    Route::post('/reservations/check-availability', [ReservationsController::class, 'checkStudioAvailability'])->name('reservations.check-availability');
    Route::post('/reservations/available-equipment', [ReservationsController::class, 'availableEquipment'])->name('reservations.available-equipment');
    Route::post('/appointments/book', [ReservationsController::class, 'bookAppointment'])->name('appointments.book');
    Route::post('/access-requests', [ReservationsController::class, 'requestAccess'])->name('access-requests.create');

    Route::get('/api/search', [ApiSearchController::class, 'index']);

    Route::get('/api/notifications/pending-reservations', function (Request $request) {
        $user = $request->user();
        if (! $user) {
            return response()->json(['pending_reservations' => []]);
        }

        $roles = is_array($user->role) ? $user->role : [$user->role];
        $isAdmin = in_array('admin', $roles);
        $isStudioResponsable = in_array('studio_responsable', $roles);

        if (! $isAdmin && ! $isStudioResponsable) {
            return response()->json(['pending_reservations' => []]);
        }

        try {
            $pendingReservations = DB::table('reservations')
                ->leftJoin('users', 'users.id', '=', 'reservations.user_id')
                ->leftJoin('studios', 'studios.id', '=', 'reservations.studio_id')
                ->where('reservations.canceled', 0)
                ->where('reservations.approved', 0)
                ->where('reservations.created_at', '>=', now()->subDays(7))
                ->orderByDesc('reservations.created_at')
                ->limit(10)
                ->select(
                    'reservations.id',
                    'reservations.title',
                    'reservations.day',
                    'reservations.start',
                    'reservations.end',
                    'reservations.type',
                    'reservations.created_at',
                    'users.name as user_name',
                    'studios.name as studio_name'
                )
                ->get()
                ->map(function ($r) {
                    return [
                        'id' => $r->id,
                        'title' => $r->title ?? "Reservation #{$r->id}",
                        'user_name' => $r->user_name ?? 'Unknown',
                        'studio_name' => $r->studio_name ?? 'N/A',
                        'type' => $r->type ?? 'N/A',
                        'date' => $r->day ?? 'N/A',
                        'time' => ($r->start && $r->end) ? "{$r->start} - {$r->end}" : 'N/A',
                        'created_at' => $r->created_at,
                    ];
                });

            return response()->json(['pending_reservations' => $pendingReservations]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch pending reservations: '.$e->getMessage());

            return response()->json(['pending_reservations' => []], 500);
        }
    })->name('api.notifications.pending-reservations');
});
