<?php

// use App\Http\Controllers\CompleteProfile;

use App\Http\Controllers\Admin\CertificateController;
use App\Http\Controllers\CompleteProfileController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\UsersController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role:admin,super_admin,moderateur,coach,pro,studio_responsable'])->prefix('admin')->group(function () {

    Route::get('/users', [UsersController::class, 'index']);
    Route::get('/users/export', [UsersController::class, 'export']);
    Route::get('/users/{user}', [UsersController::class, 'show']);
    Route::get('/users/{user}/attendance-chart', [UsersController::class, 'UserAttendanceChart']); //! chart route
    Route::get('/users/{user}/attendance-summary', [UsersController::class, 'attendanceSummary']);
    Route::get('/users/{user}/notes', [UsersController::class, 'notes']);
    Route::post('/users/{user}/notes', [UsersController::class, 'storeNote']);
    Route::get('/users/{user}/documents', [UsersController::class, 'documents']);
    Route::post('/users/{user}/documents', [UsersController::class, 'uploadDocument']);
    Route::get('/users/{user}/documents/{kind}/{doc}', [UsersController::class, 'viewDocument'])->where(['kind' => 'contract|medical', 'doc' => '[0-9]+'])->name('admin.users.documents.view');
    Route::post('/users/store', [UsersController::class, 'store']);
    Route::put('/users/update/{user}/account-state', [UsersController::class, 'updateAccountStatus']);
    Route::post('/users/send-email', [UsersController::class, 'sendEmail']);
    Route::post('/users/{id}/resend-link', [CompleteProfileController::class, 'resendActivationLink']);
    Route::post('/users/{id}/reset-password', [CompleteProfileController::class, 'resetPassword']);
    Route::post('/users/{user}/certificate/download', [CertificateController::class, 'download'])->name('admin.users.certificate.download');
});
Route::post('/complete-profile/update/{token}', [CompleteProfileController::class, 'submitCompleteProfile'])
    ->name('user.complete-profile.update');
Route::get('/complete-profile/{token}', [CompleteProfileController::class, 'goToCompleteProfile'])
    ->name('user.complete-profile');
