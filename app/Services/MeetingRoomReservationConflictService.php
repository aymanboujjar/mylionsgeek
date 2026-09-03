<?php

namespace App\Services;

use App\Models\ReservationMeetingRoom;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class MeetingRoomReservationConflictService
{
    public const TIME_REGEX = '/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/';

    /**
     * Shared HH:MM validation used by the web meeting-room create path.
     */
    public static function timeRules(): array
    {
        return ['required', 'string', 'regex:'.self::TIME_REGEX];
    }

    /**
     * Create an auto-approved meeting-room reservation after locking the room/day
     * and rejecting overlapping non-canceled rows.
     *
     * Overlap: existing.start < new.end && existing.end > new.start
     * Canceled rows do not block.
     */
    public function createApproved(int $userId, int $meetingRoomId, string $day, string $start, string $end): ReservationMeetingRoom
    {
        $this->assertValidInterval($start, $end);

        $lockName = 'meeting-room-reserve-'.$meetingRoomId.'-'.$day;
        $driver = DB::getDriverName();
        $usesNamedLock = in_array($driver, ['mysql', 'mariadb'], true);

        try {
            // Serialize first-insert races on MySQL/MariaDB when no reservation rows exist yet.
            if ($usesNamedLock) {
                DB::select('SELECT GET_LOCK(?, 10) as acquired', [$lockName]);
            }

            return DB::transaction(function () use ($userId, $meetingRoomId, $day, $start, $end) {
                if (Schema::hasTable('meeting_rooms')) {
                    DB::table('meeting_rooms')->where('id', $meetingRoomId)->lockForUpdate()->first();
                }

                $sameRoomDay = ReservationMeetingRoom::query()
                    ->where('meeting_room_id', $meetingRoomId)
                    ->where('day', $day)
                    ->lockForUpdate()
                    ->get();

                $hasOverlap = $sameRoomDay->contains(function ($row) use ($start, $end) {
                    if ($row->canceled) {
                        return false;
                    }

                    return $row->start < $end && $row->end > $start;
                });

                if ($hasOverlap) {
                    throw ValidationException::withMessages([
                        'start' => ['This meeting room is already reserved for the selected time.'],
                    ]);
                }

                $lastId = (int) (DB::table('reservation_meeting_rooms')->max('id') ?? 0);
                $reservationId = $lastId + 1;

                DB::table('reservation_meeting_rooms')->insert([
                    'id' => $reservationId,
                    'meeting_room_id' => $meetingRoomId,
                    'user_id' => $userId,
                    'day' => $day,
                    'start' => $start,
                    'end' => $end,
                    'passed' => 0,
                    'approved' => 1,
                    'canceled' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return ReservationMeetingRoom::query()->findOrFail($reservationId);
            });
        } finally {
            if ($usesNamedLock) {
                DB::select('SELECT RELEASE_LOCK(?)', [$lockName]);
            }
        }
    }

    private function assertValidInterval(string $start, string $end): void
    {
        if (strtotime($start) >= strtotime($end)) {
            throw ValidationException::withMessages([
                'end' => ['End time must be after start time.'],
            ]);
        }
    }
}
