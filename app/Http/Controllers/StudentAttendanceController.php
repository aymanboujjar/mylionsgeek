<?php

namespace App\Http\Controllers;

use App\Exceptions\FaceVerificationException;
use App\Services\AttendanceCheckInService;
use App\Services\FaceVerificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class StudentAttendanceController extends Controller
{
    public function index(AttendanceCheckInService $checkInService)
    {
        $user = Auth::user();
        $formation = $checkInService->resolvePrimaryFormation($user);
        $attendanceDay = Carbon::now()->toDateString();

        $slotStatus = null;
        if ($formation !== null) {
            $slotStatus = $checkInService->slotStatus($user, $formation['id'], $attendanceDay);
        }

        return Inertia::render('students/attendance/index', [
            'formation' => $formation,
            'attendance_day' => $attendanceDay,
            'slot_status' => $slotStatus,
        ]);
    }

    public function checkIn(
        Request $request,
        AttendanceCheckInService $checkInService,
        FaceVerificationService $faceService,
    ) {
        $validated = $request->validate([
            'formation_id' => 'required|integer|exists:formations,id',
            'attendance_day' => 'nullable|date',
            'live_photo' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $user = Auth::user();
        $attendanceDay = $checkInService->resolveAttendanceDay($validated['attendance_day'] ?? null);

        if ($faceService->shouldBypass($user)) {
            $verificationResult = [
                'passed' => true,
                'confidence' => null,
                'method' => 'staff-bypass',
            ];
        } else {
            try {
                $verificationResult = $faceService->verify(
                    $user,
                    $request->file('live_photo'),
                );
            } catch (FaceVerificationException $e) {
                return response()->json([
                    'message' => 'Verification service unavailable. Please contact your teacher.',
                ], 503);
            }

            if (! $verificationResult['passed']) {
                return response()->json([
                    'message' => 'Face not recognized. Please try again.',
                    'error_code' => 'FACE_NOT_RECOGNIZED',
                ], 422);
            }
        }

        $result = $checkInService->checkIn(
            $user,
            (int) $validated['formation_id'],
            $attendanceDay,
            $verificationResult,
        );

        return response()->json($result);
    }

    public function slotStatus(Request $request, AttendanceCheckInService $checkInService)
    {
        $validated = $request->validate([
            'formation_id' => 'required|integer|exists:formations,id',
            'attendance_day' => 'nullable|date',
        ]);

        $attendanceDay = $checkInService->resolveAttendanceDay($validated['attendance_day'] ?? null);

        return response()->json($checkInService->slotStatus(
            Auth::user(),
            (int) $validated['formation_id'],
            $attendanceDay,
        ));
    }

    public function homeSlotStatus(AttendanceCheckInService $checkInService)
    {
        $user = Auth::user();
        $formation = $checkInService->resolvePrimaryFormation($user);

        if ($formation === null) {
            return response()->json([
                'formation' => null,
                'slot_status' => null,
            ]);
        }

        $attendanceDay = Carbon::now()->toDateString();

        return response()->json([
            'formation' => $formation,
            'slot_status' => $checkInService->slotStatus($user, $formation['id'], $attendanceDay),
        ]);
    }

    /**
     * Lightweight probe for the home banner — school.network middleware supplies 403/503.
     */
    public function networkCheck()
    {
        return response()->json(['ok' => true]);
    }
}
