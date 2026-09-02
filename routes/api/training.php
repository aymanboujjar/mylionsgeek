<?php

use App\Http\Controllers\API\TrainingController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // Training CRUD operations
    Route::get('/trainings', [TrainingController::class, 'index']);
    Route::get('/trainings/enrolled', [TrainingController::class, 'enrolled']);
    Route::get('/trainings/{id}', [TrainingController::class, 'show']);

    Route::middleware('training.manage')->group(function () {
        Route::post('/trainings', [TrainingController::class, 'store']);
        Route::put('/trainings/{id}', [TrainingController::class, 'update']);
        Route::delete('/trainings/{id}', [TrainingController::class, 'destroy']);

        Route::post('/trainings/{id}/students', [TrainingController::class, 'addStudent']);
        Route::delete('/trainings/{id}/students/{userId}', [TrainingController::class, 'removeStudent']);
        Route::post('/trainings/{id}/bulk-update-users', [TrainingController::class, 'bulkUpdateUsers']);
    });
    
    Route::middleware('attendance.staff')->group(function () {
        Route::post('/attendances', [TrainingController::class, 'attendance']);
        Route::post('/attendance/save', [TrainingController::class, 'save'])
            ->middleware('school.network');
        Route::get('/trainings/{id}/attendance-events', [TrainingController::class, 'attendanceEvents']);
    });

    Route::post('/attendance/check-in', [TrainingController::class, 'checkIn'])
        ->middleware('school.network');
    Route::get('/attendance/slot-status', [TrainingController::class, 'slotStatus'])
        ->middleware('school.network');
    Route::get('/attendance/network-check', function () {
        return response()->json(['ok' => true]);
    })->middleware('school.network');
    Route::get('/trainings/{id}/attendance-history', [TrainingController::class, 'attendanceHistory']);
});
