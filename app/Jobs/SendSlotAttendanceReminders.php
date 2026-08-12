<?php

namespace App\Jobs;

use App\Models\AttendanceReminderNotification;
use App\Services\ActiveFormationEnrollmentService;
use App\Services\AttendanceCheckInService;
use App\Services\AttendanceSlotService;
use App\Services\ExpoPushNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendSlotAttendanceReminders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const SLOT_LABELS = [
        'morning' => 'Morning',
        'lunch' => 'Coffee break',
        'evening' => 'Lunch',
    ];

    /**
     * @param  string  $slot  morning|lunch|evening — captured at dispatch time
     * @param  string  $date  Y-m-d — captured at dispatch time
     */
    public function __construct(
        public string $slot,
        public string $date,
    ) {}

    public function handle(
        AttendanceSlotService $slotService,
        AttendanceCheckInService $checkInService,
        ExpoPushNotificationService $pushService,
        ActiveFormationEnrollmentService $enrollmentService,
    ): void {
        if (! in_array($this->slot, $slotService->slotOrder(), true)) {
            Log::warning('SendSlotAttendanceReminders: unknown slot', [
                'slot' => $this->slot,
                'date' => $this->date,
            ]);

            return;
        }

        $activeFormationIds = $enrollmentService->activeFormationIds();

        if ($activeFormationIds === []) {
            Log::info('SendSlotAttendanceReminders: no active formations', [
                'slot' => $this->slot,
                'date' => $this->date,
            ]);

            return;
        }

        $recipients = [];

        foreach ($enrollmentService->eachEnrolledStudent($activeFormationIds) as $candidate) {
            $user = $candidate['user'];
            $formationId = $candidate['formation_id'];

            if (AttendanceReminderNotification::query()
                ->where('user_id', $user->id)
                ->whereDate('date', $this->date)
                ->where('slot', $this->slot)
                ->exists()) {
                continue;
            }

            try {
                $slotStatus = $checkInService->slotStatus($user, $formationId, $this->date);
            } catch (HttpResponseException) {
                continue;
            }

            if (in_array($this->slot, $slotStatus['already_marked_slots'] ?? [], true)) {
                continue;
            }

            $token = trim((string) ($user->expo_push_token ?? ''));
            if ($token === '') {
                continue;
            }

            $recipients[] = [
                'user_id' => (int) $user->id,
                'token' => $token,
            ];
        }

        if ($recipients === []) {
            Log::info('SendSlotAttendanceReminders: no recipients', [
                'slot' => $this->slot,
                'date' => $this->date,
            ]);

            return;
        }

        $slotLabel = self::SLOT_LABELS[$this->slot] ?? $this->slot;
        $title = 'Attendance Reminder';
        $body = "Check in for {$slotLabel}";
        $tokens = array_values(array_unique(array_column($recipients, 'token')));

        $sent = $pushService->send($tokens, $title, $body, [
            'type' => 'attendance_reminder',
            'slot' => $this->slot,
        ]);

        if (! $sent) {
            Log::warning('SendSlotAttendanceReminders: Expo push batch failed', [
                'slot' => $this->slot,
                'date' => $this->date,
                'recipient_count' => count($recipients),
            ]);

            return;
        }

        foreach ($recipients as $recipient) {
            try {
                AttendanceReminderNotification::create([
                    'user_id' => $recipient['user_id'],
                    'date' => $this->date,
                    'slot' => $this->slot,
                    'message_notification' => $body,
                    'path' => '/students/attendance',
                ]);
            } catch (UniqueConstraintViolationException) {
                // Unique (user_id, date, slot) — concurrent run or race; safe to skip
                continue;
            }
        }

        Log::info('SendSlotAttendanceReminders: completed', [
            'slot' => $this->slot,
            'date' => $this->date,
            'recipient_count' => count($recipients),
        ]);
    }
}
