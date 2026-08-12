<?php

namespace App\Services;

use App\Models\User;
use App\Models\Formation;
use App\Models\AttendanceListe;
use App\Models\DisciplineNotification;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class DisciplineService
{
    /**
     * Weight map for attendance statuses
     */
    private const WEIGHT_MAP = [
        'present' => 1.0,
        'pending' => 1.0, // unresolved — no score loss until finalized
        'excused' => 0.9,
        'late' => 0.7,
        'absent' => 0.0,
    ];

    /**
     * Discipline thresholds for notifications (100, 95, 90, 85, ...)
     */
    private const THRESHOLDS = [100, 95, 90, 85, 80, 75, 70, 65, 60, 55, 50, 45, 40, 35, 30, 25, 20, 15, 10, 5, 0];

    /**
     * Calculate the number of working days (excluding weekends) for a formation
     */
    public function getFormationWorkingDays(Formation $formation): int
    {
        $startDate = $formation->start_time ? Carbon::parse($formation->start_time) : null;
        $endDate = $formation->end_time ? Carbon::parse($formation->end_time) : null;

        // If no dates, return default 120 days (4 months * 30 days)
        if (!$startDate) {
            return 120;
        }

        // If no end date, calculate 6 months from start
        if (!$endDate) {
            $endDate = $startDate->copy()->addMonths(6);
        }

        // Count business days (excluding weekends)
        $workingDays = 0;
        $period = CarbonPeriod::create($startDate, $endDate);

        foreach ($period as $date) {
            if (!$date->isWeekend()) {
                $workingDays++;
            }
        }

        return max($workingDays, 1); // Avoid division by zero
    }

    /**
     * Calculate total slots for a formation (working days * 3)
     */
    public function getTotalSlots(Formation $formation): int
    {
        return $this->getFormationWorkingDays($formation) * 3;
    }

    /**
     * Count absent slots for a user
     * Returns the weighted score lost (not raw count)
     */
    public function countAbsentSlots(User $user): float
    {
        $attendance = AttendanceListe::where('user_id', $user->id)
            ->get(['morning', 'lunch', 'evening']);

        if ($attendance->isEmpty()) {
            return 0;
        }

        $lostScore = 0;

        foreach ($attendance as $row) {
            // Each slot that isn't 'present' loses some score
            $lostScore += (1.0 - (self::WEIGHT_MAP[strtolower($row->morning ?? 'present')] ?? 0.7));
            $lostScore += (1.0 - (self::WEIGHT_MAP[strtolower($row->lunch ?? 'present')] ?? 0.7));
            $lostScore += (1.0 - (self::WEIGHT_MAP[strtolower($row->evening ?? 'present')] ?? 0.7));
        }

        return $lostScore;
    }

    /**
     * Calculate discipline score for a user
     * Formula: 100 - (lostSlots / totalSlots * 100)
     */
    public function calculateDisciplineScore(User $user): float
    {
        // If user has no formation, return 100%
        if (!$user->formation_id) {
            return 100.0;
        }

        $formation = Formation::find($user->formation_id);
        if (!$formation) {
            return 100.0;
        }

        $totalSlots = $this->getTotalSlots($formation);
        $lostScore = $this->countAbsentSlots($user);

        // Calculate discipline: 100 - (lost percentage)
        $discipline = 100 - (($lostScore / $totalSlots) * 100);

        // Clamp between 0 and 100
        return max(0, min(100, round($discipline, 2)));
    }

    /**
     * Get the last stored discipline threshold for a user
     */
    public function getLastStoredThreshold(User $user): ?float
    {
        $lastNotification = DisciplineNotification::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->first();

        return $lastNotification ? (float) $lastNotification->discipline_change : null;
    }

    /**
     * Find which threshold a discipline score is AT or ABOVE
     * e.g., 97.5 returns 95, 94.5 returns 90, 89.2 returns 85
     */
    public function findThreshold(float $discipline): int
    {
        foreach (self::THRESHOLDS as $threshold) {
            if ($discipline >= $threshold) {
                return $threshold;
            }
        }
        return 0;
    }

    /**
     * Check if we should create a notification based on crossing a threshold
     * Returns: [shouldNotify, type ('increase'|'decrease'|null), crossedThreshold, fromThreshold]
     */
    public function shouldNotify(User $user, float $oldDiscipline, float $newDiscipline): array
    {
        // DECREASE: Check if crossed BELOW any threshold
        // e.g., 100% → 94.5% = crossed below 95%
        // e.g., 100% → 99.5% = NO notification (still above 95%)
        if ($newDiscipline < $oldDiscipline) {
            foreach (self::THRESHOLDS as $index => $threshold) {
                if ($threshold >= 100) continue;

                // Check if old was >= threshold AND new is < threshold
                if ($oldDiscipline >= $threshold && $newDiscipline < $threshold) {
                    $fromThreshold = $index > 0 ? self::THRESHOLDS[$index - 1] : 100;

                    // Check if already notified
                    $existing = DisciplineNotification::where('user_id', $user->id)
                        ->where('discipline_change', '<', $threshold)
                        ->where('discipline_change', '>=', $threshold - 5)
                        ->where('message_notification', 'like', '%decreased%')
                        ->exists();

                    if (!$existing) {
                        return [true, 'decrease', $threshold, $fromThreshold];
                    }
                }
            }
        }

        // INCREASE: Check if crossed ABOVE any threshold (including 100%)
        // e.g., 94% → 96% = crossed above 95%
        // e.g., 94% → 100% = crossed to 100%
        if ($newDiscipline > $oldDiscipline) {
            // Special case: reached 100%
            if ($newDiscipline >= 100 && $oldDiscipline < 100) {
                $oldThreshold = $this->findThreshold($oldDiscipline);

                $existing = DisciplineNotification::where('user_id', $user->id)
                    ->where('discipline_change', '>=', 100)
                    ->where('message_notification', 'like', '%increased%')
                    ->exists();

                if (!$existing) {
                    return [true, 'increase', 100, $oldThreshold];
                }
            }

            // Check other thresholds
            foreach (self::THRESHOLDS as $index => $threshold) {
                if ($threshold >= 100) continue;

                // Check if old was < threshold AND new is >= threshold
                if ($oldDiscipline < $threshold && $newDiscipline >= $threshold) {
                    $oldThreshold = $this->findThreshold($oldDiscipline);

                    // Check if already notified
                    $existing = DisciplineNotification::where('user_id', $user->id)
                        ->where('discipline_change', '>=', $threshold)
                        ->where('discipline_change', '<', $threshold + 5)
                        ->where('message_notification', 'like', '%increased%')
                        ->exists();

                    if (!$existing) {
                        return [true, 'increase', $threshold, $oldThreshold];
                    }
                }
            }
        }

        return [false, null, null, null];
    }

    /**
     * Create a discipline notification
     */
    public function createNotification(User $user, float $newDiscipline, string $type, int $fromThreshold): DisciplineNotification
    {
        $direction = $type === 'increase' ? 'increased' : 'decreased';

        // Message with user name: from THRESHOLD to actual value
        $message = "{$user->name} - discipline {$direction} 5%";

        if ($newDiscipline <= 0) {
            $message = "{$user->name} - discipline reached 0%";
        }

        $notification = DisciplineNotification::create([
            'user_id' => $user->id,
            'message_notification' => $message,
            'discipline_change' => round($newDiscipline, 2), // Store actual value with decimals
            'path' => null,
            'type' => null,
        ]);

        // Send Expo push notification
        if ($notification) {
            try {
                // Refresh user to get latest expo_push_token
                $user->refresh();
                
                if ($user->expo_push_token) {
                    $pushService = app(\App\Services\ExpoPushNotificationService::class);
                    
                    \Illuminate\Support\Facades\Log::info('Sending push notification for discipline change', [
                        'user_id' => $user->id,
                    ]);
                    
                    $success = $pushService->sendToUser($user, 'Discipline Update', $message, [
                        'type' => 'discipline_change',
                        'notification_id' => $notification->id,
                        'discipline_value' => round($newDiscipline, 2),
                        'change_type' => $type,
                    ]);
                    
                    if (!$success) {
                        \Illuminate\Support\Facades\Log::warning('Push notification send returned false for discipline change', [
                            'user_id' => $user->id,
                            'notification_id' => $notification->id,
                        ]);
                    }
                } else {
                    \Illuminate\Support\Facades\Log::info('User does not have Expo push token, skipping push notification', [
                        'user_id' => $user->id,
                    ]);
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to send Expo push notification for discipline change', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'notification_id' => $notification->id ?? null,
                ]);
                // Don't fail notification creation if push fails
            }
        }

        return $notification;
    }

    /**
     * Process discipline change and create notification if needed
     * This is the main method to call after attendance update
     */
    public function processDisciplineChange(User $user, float $oldDiscipline): ?DisciplineNotification
    {
        // Skip notifications for users with status == "left"
        // if (strtolower($user->status ?? '') === 'left') {
        //     return null;
        // }

        $newDiscipline = $this->calculateDisciplineScore($user);

        [$shouldNotify, $type, $crossedThreshold, $fromThreshold] = $this->shouldNotify($user, $oldDiscipline, $newDiscipline);

        if ($shouldNotify) {
            return $this->createNotification($user, $newDiscipline, $type, $fromThreshold);
        }

        return null;
    }
}

