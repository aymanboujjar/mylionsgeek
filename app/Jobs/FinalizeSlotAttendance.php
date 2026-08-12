<?php

namespace App\Jobs;

use App\Models\Attendance;
use App\Models\AttendanceListe;
use App\Models\User;
use App\Services\ActiveFormationEnrollmentService;
use App\Services\AttendanceLegacyIdService;
use App\Services\AttendanceSlotService;
use App\Services\DisciplineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FinalizeSlotAttendance implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  string  $slot  morning|lunch|evening — captured at dispatch time
     * @param  string  $date  Y-m-d attendance day — captured at dispatch time
     */
    public function __construct(
        public string $slot,
        public string $date,
    ) {}

    public function handle(
        AttendanceSlotService $slotService,
        ActiveFormationEnrollmentService $enrollmentService,
        AttendanceLegacyIdService $legacyIdService,
        DisciplineService $disciplineService,
    ): void {
        if (! in_array($this->slot, $slotService->slotOrder(), true)) {
            Log::warning('FinalizeSlotAttendance: unknown slot', [
                'slot' => $this->slot,
                'date' => $this->date,
            ]);

            return;
        }

        $activeFormationIds = $enrollmentService->activeFormationIds();
        if ($activeFormationIds === []) {
            Log::info('FinalizeSlotAttendance: no active formations', [
                'slot' => $this->slot,
                'date' => $this->date,
            ]);

            return;
        }

        $finalized = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($enrollmentService->eachEnrolledStudent($activeFormationIds) as $candidate) {
            /** @var User $user */
            $user = $candidate['user'];
            $formationId = $candidate['formation_id'];

            try {
                $outcome = DB::transaction(function () use (
                    $user,
                    $formationId,
                    $slotService,
                    $legacyIdService,
                    $disciplineService,
                ) {
                    $attendance = Attendance::query()
                        ->where('formation_id', $formationId)
                        ->whereDate('attendance_day', $this->date)
                        ->lockForUpdate()
                        ->first();

                    if (! $attendance) {
                        $attendance = Attendance::create([
                            'formation_id' => $formationId,
                            'attendance_day' => $this->date,
                            'staff_name' => 'System',
                        ]);
                    } else {
                        $attendance = $legacyIdService->ensureNumericId($attendance, $attendance->staff_name ?? 'System');
                    }

                    $existingRow = AttendanceListe::query()
                        ->where('attendance_id', $attendance->id)
                        ->where('user_id', $user->id)
                        ->lockForUpdate()
                        ->first();

                    $existingSlots = $existingRow
                        ? [
                            'morning' => $existingRow->morning,
                            'lunch' => $existingRow->lunch,
                            'evening' => $existingRow->evening,
                        ]
                        : null;

                    $mergedSlots = $slotService->buildFinalizeSlots($this->slot, $existingSlots);

                    // Idempotent: skip only when merge changes nothing (still backfills earlier pendings
                    // even if the closed slot itself is already present/late/excused/absent)
                    if ($existingSlots !== null
                        && ($existingSlots['morning'] ?? null) === $mergedSlots['morning']
                        && ($existingSlots['lunch'] ?? null) === $mergedSlots['lunch']
                        && ($existingSlots['evening'] ?? null) === $mergedSlots['evening']
                    ) {
                        return 'skipped';
                    }

                    $oldDiscipline = $disciplineService->calculateDisciplineScore($user);

                    AttendanceListe::updateOrCreate(
                        [
                            'attendance_id' => $attendance->id,
                            'user_id' => $user->id,
                        ],
                        [
                            'attendance_day' => $this->date,
                            'morning' => $mergedSlots['morning'],
                            'lunch' => $mergedSlots['lunch'],
                            'evening' => $mergedSlots['evening'],
                        ],
                    );

                    $disciplineService->processDisciplineChange($user, $oldDiscipline);

                    return 'finalized';
                });

                if ($outcome === 'finalized') {
                    $finalized++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::error('FinalizeSlotAttendance: student finalize failed', [
                    'user_id' => $user->id,
                    'formation_id' => $formationId,
                    'slot' => $this->slot,
                    'date' => $this->date,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        Log::info('FinalizeSlotAttendance: completed', [
            'slot' => $this->slot,
            'date' => $this->date,
            'finalized' => $finalized,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);
    }
}
