<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceListe;
use App\Models\Formation;
use App\Models\Note;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

class AttendanceCheckInService
{
    public function __construct(
        private readonly AttendanceSlotService $slotService,
        private readonly AttendancePersistenceService $persistence,
        private readonly AttendanceLegacyIdService $legacyIdService,
    ) {}

    /**
     * Resolve attendance day from client input or server clock (app timezone).
     */
    public function resolveAttendanceDay(?string $attendanceDay): string
    {
        return $attendanceDay ?? Carbon::now()->toDateString();
    }

    /**
     * @return array{
     *     attendance_day: string,
     *     current_slot: string|null,
     *     phase: string,
     *     minutes_into_slot: int|null,
     *     present_minutes: int,
     *     already_marked_slots: list<string>,
     *     label_key: string,
     *     row: array<string, mixed>|null
     * }
     */
    public function slotStatus(User $user, int $formationId, string $attendanceDay): array
    {
        $this->assertEnrolled($user, $formationId);

        $now = Carbon::now();
        $currentSlot = $this->slotService->currentSlot($now);
        $phase = $this->slotService->phase($now);

        $attendance = Attendance::query()
            ->where('formation_id', $formationId)
            ->whereDate('attendance_day', $attendanceDay)
            ->first();

        $existingSlots = null;
        $row = null;

        if ($attendance) {
            $existingRow = AttendanceListe::query()
                ->where('attendance_id', $attendance->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existingRow) {
                $existingSlots = [
                    'morning' => $existingRow->morning,
                    'lunch' => $existingRow->lunch,
                    'evening' => $existingRow->evening,
                ];
                $row = [
                    'morning' => $existingRow->morning,
                    'lunch' => $existingRow->lunch,
                    'evening' => $existingRow->evening,
                ];
            }
        }

        $alreadyMarked = $this->slotService->markedSlots($existingSlots);

        return [
            'attendance_day' => $attendanceDay,
            'current_slot' => $currentSlot,
            'phase' => $phase,
            'minutes_into_slot' => $currentSlot !== null
                ? $this->slotService->minutesIntoSlot($now, $currentSlot)
                : null,
            'present_minutes' => (int) config('attendance.present_minutes', 15),
            'already_marked_slots' => $alreadyMarked,
            'label_key' => $this->slotService->labelKey($now, $currentSlot, $phase),
            'next_slot' => $this->slotService->nextSlot($now),
            'row' => $row,
        ];
    }

    /**
     * @param  array{passed?: bool, confidence?: float|null, method?: string}|null  $verificationResult
     * @return array{
     *     slot: string,
     *     status: string,
     *     row: array<string, mixed>
     * }
     */
    public function checkIn(
        User $user,
        int $formationId,
        string $attendanceDay,
        ?array $verificationResult = null,
    ): array {
        $this->assertEnrolled($user, $formationId);

        $today = Carbon::now()->toDateString();
        if ($attendanceDay !== $today) {
            $this->failJson('Attendance can only be marked for today.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $now = Carbon::now();
        $currentSlot = $this->slotService->currentSlot($now);

        if ($currentSlot === null) {
            $this->failJson('No attendance to mark right now.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $attendance = $this->findOrCreateAttendance($formationId, $attendanceDay, $user->name ?? 'Student');

        $existingRow = AttendanceListe::query()
            ->where('attendance_id', $attendance->id)
            ->where('user_id', $user->id)
            ->first();

        $existingSlots = $existingRow
            ? [
                'morning' => $existingRow->morning,
                'lunch' => $existingRow->lunch,
                'evening' => $existingRow->evening,
            ]
            : null;

        if ($this->slotService->isSlotMarked($existingSlots[$currentSlot] ?? null)) {
            $this->failJson("You've already marked attendance for this slot.", Response::HTTP_CONFLICT);
        }

        $status = $this->slotService->gradeStatus($now, $currentSlot);
        $mergedSlots = $this->slotService->buildCheckInSlots($existingSlots, $currentSlot, $status);

        $noteText = 'Check-in at '.$now->format('H:i');
        $existingNotes = Note::query()
            ->where('user_id', $user->id)
            ->where('attendance_id', $attendance->id)
            ->orderBy('id')
            ->pluck('note')
            ->all();
        $pipeJoinedNotes = $existingNotes === []
            ? $noteText
            : implode(' | ', $existingNotes).' | '.$noteText;

        $row = $this->persistence->persistStudentRow(
            (int) $attendance->id,
            (int) $user->id,
            $attendanceDay,
            $mergedSlots,
            $pipeJoinedNotes,
            $user->name ?? 'Student',
        );

        if ($verificationResult !== null) {
            try {
                $row->face_verification_method = $verificationResult['method'] ?? null;
                $row->face_match_confidence = $verificationResult['confidence'] ?? null;
                $row->save();
            } catch (\Throwable) {
                // Audit columns must never block attendance.
            }
        }

        return [
            'slot' => $currentSlot,
            'status' => $status,
            'row' => [
                'attendance_id' => $attendance->id,
                'user_id' => $row->user_id,
                'attendance_day' => $row->attendance_day,
                'morning' => $row->morning,
                'lunch' => $row->lunch,
                'evening' => $row->evening,
            ],
        ];
    }

    /**
     * @return array{id: int, name: string|null}|null
     */
    public function resolvePrimaryFormation(User $user): ?array
    {
        $formationId = $user->primaryFormationId();
        if ($formationId === null) {
            return null;
        }

        $formation = Formation::query()->find($formationId);
        if (! $formation) {
            return null;
        }

        return [
            'id' => (int) $formation->id,
            'name' => $formation->name,
        ];
    }

    private function assertEnrolled(User $user, int $formationId): void
    {
        if (! $user->isEnrolledInFormation($formationId)) {
            $this->failJson('Forbidden', Response::HTTP_FORBIDDEN);
        }
    }

    private function failJson(string $message, int $status): never
    {
        throw new HttpResponseException(response()->json(['message' => $message], $status));
    }

    private function findOrCreateAttendance(int $formationId, string $attendanceDay, string $staffName): Attendance
    {
        $attendance = Attendance::where('formation_id', $formationId)
            ->whereDate('attendance_day', $attendanceDay)
            ->first();

        if (! $attendance) {
            return Attendance::create([
                'formation_id' => $formationId,
                'attendance_day' => $attendanceDay,
                'staff_name' => $staffName,
            ]);
        }

        return $this->legacyIdService->ensureNumericId($attendance, $staffName);
    }
}
