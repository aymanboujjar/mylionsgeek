<?php

use App\Http\Controllers\FaceEnrollmentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'attendance.staff'])->prefix('admin')->group(function () {
    Route::post('/users/{user}/face-enrollment', [FaceEnrollmentController::class, 'store'])
        ->name('admin.users.face-enrollment.store');
});
