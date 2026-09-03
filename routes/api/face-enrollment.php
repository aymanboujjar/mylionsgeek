<?php

use App\Http\Controllers\FaceEnrollmentController;
use Illuminate\Support\Facades\Route;

Route::middleware('attendance.staff')->group(function () {
    Route::post('/users/{user}/face-enrollment', [FaceEnrollmentController::class, 'store']);
});
