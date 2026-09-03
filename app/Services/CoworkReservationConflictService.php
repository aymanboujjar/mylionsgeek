<?php

namespace App\Services;

use App\Models\ReservationCowork;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CoworkReservationConflictService
{
    public const TIME_REGEX = '/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/';

    /**
     * Shared HH:MM validation used by web and API cowork create paths.
     */
    public static function timeRules(): array
    {
        return ['required', 'string', 'regex:'.self::TIME_REGEX];
    }

    /**
     * Create an auto-approved cowork reservation after locking the table/day
     * and rejecting overlapping non-canceled rows.
     *
     * Overlap: existing.start < new.end && existing.end > new.start
     * Canceled rows do not block.
     */
    public function createApproved(int $userId, int $table, int $seats, string $day, string $start, string $end): ReservationCowork
    {
        $this->assertValidInterval($start, $end);

        $lockName = 'cowork-reserve-'.$table.'-'.$day;
        $driver = DB::getDriverName();
        $usesNamedLock = in_array($driver, ['mysql', 'mariadb'], true);

        try {
            // Serialize first-insert races on MySQL/MariaDB when no reservation rows exist yet.
            if ($usesNamedLock) {
                DB::select('SELECT GET_LOCK(?, 10) as acquired', [$lockName]);
            }

            return DB::transaction(function () use ($userId, $table, $seats, $day, $start, $end) {
                if (Schema::hasTable('coworks')) {
                    DB::table('coworks')->where('id', $table)->lockForUpdate()->first();
                }

                $sameTableDay = ReservationCowork::query()
                    ->where('table', $table)
                    ->where('day', $day)
                    ->lockForUpdate()
                    ->get();

                $hasOverlap = $sameTableDay->contains(function ($row) use ($start, $end) {
                    if ($row->canceled) {
                        return false;
                    }

                    return $row->start < $end && $row->end > $start;
                });

                if ($hasOverlap) {
                    throw ValidationException::withMessages([
                        'start' => ['This table is already reserved for the selected time.'],
                    ]);
                }

                return ReservationCowork::create([
                    'table' => $table,
                    'seats' => $seats,
                    'day' => $day,
                    'start' => $start,
                    'end' => $end,
                    'user_id' => $userId,
                    'approved' => 1,
                    'canceled' => 0,
                    'passed' => 0,
                ]);
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
