<?php

use App\Http\Controllers\StudentAttendanceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role:student'])->group(function () {
    Route::get('/students/attendance/home-slot-status', [StudentAttendanceController::class, 'homeSlotStatus'])
        ->name('student.attendance.home-slot-status');
    Route::get('/students/attendance/slot-status', [StudentAttendanceController::class, 'slotStatus'])
        ->name('student.attendance.slot-status');
});

Route::middleware(['auth', 'verified', 'role:student', 'school.network'])->group(function () {
    Route::get('/students/attendance/network-check', [StudentAttendanceController::class, 'networkCheck'])
        ->name('student.attendance.network-check');
    Route::get('/students/attendance', [StudentAttendanceController::class, 'index'])
        ->name('student.attendance.index');
    Route::post('/students/attendance/check-in', [StudentAttendanceController::class, 'checkIn'])
        ->name('student.attendance.check-in');
});
