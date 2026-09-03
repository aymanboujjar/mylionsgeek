<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureAttendanceStaffRole;
use App\Models\User;
use App\Services\FaceEnrollmentService;
use App\Services\FaceVerification\FaceEnrollmentRejectedException;
use App\Services\FaceVerification\FaceVerificationProviderException;
use Illuminate\Http\Request;

class FaceEnrollmentController extends Controller
{
    public function store(Request $request, User $user, FaceEnrollmentService $enrollments)
    {
        $staff = $request->user();
        if (! $staff instanceof User || ! EnsureAttendanceStaffRole::allows($staff)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate([
            'reference_photo' => FaceEnrollmentService::referencePhotoRules(),
        ]);

        try {
            $enrollment = $enrollments->enroll($user, $staff, $request->file('reference_photo'));
        } catch (FaceEnrollmentRejectedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (FaceVerificationProviderException) {
            return response()->json(['message' => 'Unable to enroll a face right now.'], 503);
        }

        return response()->json([
            'enrolled' => true,
            'enrolled_at' => $enrollment->enrolled_at?->toIso8601String(),
        ]);
    }
}
