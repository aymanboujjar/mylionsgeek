<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class StudioReservationConflictService
{
    public const TIME_REGEX = '/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/';

    /**
     * Shared HH:MM validation used by web and API studio create paths.
     *
     * @return list<string>
     */
    public static function timeRules(): array
    {
        return ['required', 'string', 'regex:'.self::TIME_REGEX];
    }

    /**
     * Create a pending studio reservation after locking the studio/day
     * and rejecting overlapping non-canceled rows.
     *
     * Overlap: existing.start < new.end && existing.end > new.start
     * Canceled rows do not block. Pending (approved=0) rows do block.
     *
     * @param  array{
     *     studio_id: int,
     *     user_id: int,
     *     title: string,
     *     description?: string|null,
     *     day: string,
     *     start: string,
     *     end: string,
     *     type?: string,
     *     studio_responsable_approved?: int
     * }  $attributes
     */
    public function createPending(array $attributes): int
    {
        $studioId = (int) $attributes['studio_id'];
        $day = (string) $attributes['day'];
        $start = (string) $attributes['start'];
        $end = (string) $attributes['end'];

        $this->assertValidInterval($start, $end);

        $lockName = 'studio-reserve-'.$studioId.'-'.$day;
        $driver = DB::getDriverName();
        $usesNamedLock = in_array($driver, ['mysql', 'mariadb'], true);

        try {
            if ($usesNamedLock) {
                DB::select('SELECT GET_LOCK(?, 10) as acquired', [$lockName]);
            }

            return DB::transaction(function () use ($attributes, $studioId, $day, $start, $end) {
                if (Schema::hasTable('studios')) {
                    DB::table('studios')->where('id', $studioId)->lockForUpdate()->first();
                }

                $sameStudioDay = DB::table('reservations')
                    ->where('studio_id', $studioId)
                    ->where('day', $day)
                    ->lockForUpdate()
                    ->get();

                $hasOverlap = $sameStudioDay->contains(function ($row) use ($start, $end) {
                    if ((int) ($row->canceled ?? 0) === 1) {
                        return false;
                    }

                    return $row->start < $end && $row->end > $start;
                });

                if ($hasOverlap) {
                    throw ValidationException::withMessages([
                        'start' => ['This studio is already reserved for the selected time.'],
                    ]);
                }

                $lastId = (int) (DB::table('reservations')->max('id') ?? 0);
                $reservationId = $lastId + 1;

                $row = [
                    'id' => $reservationId,
                    'studio_id' => $studioId,
                    'user_id' => (int) $attributes['user_id'],
                    'title' => $attributes['title'],
                    'description' => $attributes['description'] ?? '',
                    'day' => $day,
                    'start' => $start,
                    'end' => $end,
                    'type' => $attributes['type'] ?? 'studio',
                    'approved' => 0,
                    'canceled' => 0,
                    'passed' => 0,
                    'start_signed' => 0,
                    'end_signed' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (Schema::hasColumn('reservations', 'studio_responsable_approved')) {
                    $row['studio_responsable_approved'] = (int) ($attributes['studio_responsable_approved'] ?? 0);
                }

                DB::table('reservations')->insert($row);

                return $reservationId;
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
