<?php

namespace App\Services;

use Carbon\Carbon;

class AttendanceSlotService
{
    /**
     * @return list<string>
     */
    public function slotOrder(): array
    {
        return config('attendance.slot_order', ['morning', 'lunch', 'evening']);
    }

    /**
     * Active slot at the given instant, or null when in a gap or outside school hours.
     */
    public function currentSlot(Carbon $now): ?string
    {
        $seconds = $this->secondsSinceMidnight($now);

        foreach ($this->slotOrder() as $slot) {
            $window = config("attendance.slots.{$slot}");
            if ($window === null) {
                continue;
            }

            $opens = (int) $window['opens'] * 60;
            $closes = (int) $window['closes'] * 60;

            if ($seconds >= $opens && $seconds < $closes) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Slot whose window just closed at the given clock minute, or null otherwise.
     * Matches scheduler close times (11:00 / 13:00 / 17:00) the way currentSlot matches opens.
     */
    public function justClosedSlot(Carbon $now): ?string
    {
        $minutes = $now->hour * 60 + $now->minute;

        foreach ($this->slotOrder() as $slot) {
            $window = config("attendance.slots.{$slot}");
            if ($window === null) {
                continue;
            }

            if ($minutes === (int) $window['closes']) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Latest slot whose window has already closed at $now (closes <= current minute).
     * When $allClosed is true (e.g. past attendance day), returns the last slot in order.
     */
    public function latestClosedSlot(Carbon $now, bool $allClosed = false): ?string
    {
        $order = $this->slotOrder();
        if ($order === []) {
            return null;
        }

        if ($allClosed) {
            return $order[array_key_last($order)];
        }

        $minutes = $now->hour * 60 + $now->minute;
        $latest = null;

        foreach ($order as $slot) {
            $window = config("attendance.slots.{$slot}");
            if ($window === null) {
                continue;
            }

            if ($minutes >= (int) $window['closes']) {
                $latest = $slot;
            }
        }

        return $latest;
    }

    /**
     * Grade present/late/absent for a slot at the given instant.
     * Call only when $now falls inside the slot window.
     */
    public function gradeStatus(Carbon $now, string $slot): string
    {
        $window = config("attendance.slots.{$slot}");
        if ($window === null) {
            return 'absent';
        }

        $seconds = $this->secondsSinceMidnight($now);
        $opens = (int) $window['opens'] * 60;
        $closes = (int) $window['closes'] * 60;
        $presentMinutes = (int) config('attendance.present_minutes', 15);
        $presentUntil = $opens + ($presentMinutes * 60) - 1;

        if ($seconds <= $presentUntil) {
            return 'present';
        }

        if ($seconds < $closes) {
            return 'late';
        }

        return 'absent';
    }

    /**
     * @return 'active'|'gap'|'closed'
     */
    public function phase(Carbon $now): string
    {
        if ($this->currentSlot($now) !== null) {
            return 'active';
        }

        $seconds = $this->secondsSinceMidnight($now);
        $dayOpens = (int) config('attendance.day_opens', 570) * 60;
        $dayCloses = (int) config('attendance.day_closes', 1020) * 60;

        if ($seconds < $dayOpens || $seconds >= $dayCloses) {
            return 'closed';
        }

        return 'gap';
    }

    public function minutesIntoSlot(Carbon $now, string $slot): int
    {
        $window = config("attendance.slots.{$slot}");
        if ($window === null) {
            return 0;
        }

        $opens = (int) $window['opens'];

        return max(0, ($now->hour * 60 + $now->minute) - $opens);
    }

    public function labelKey(Carbon $now, ?string $currentSlot, string $phase): string
    {
        if ($phase === 'closed') {
            return 'attendance.closed';
        }

        if ($phase === 'gap') {
            return 'attendance.gap';
        }

        if ($currentSlot === null) {
            return 'attendance.gap';
        }

        $status = $this->gradeStatus($now, $currentSlot);

        return match ($status) {
            'present' => 'attendance.check_in.present',
            'late' => 'attendance.check_in.late',
            default => 'attendance.check_in.absent',
        };
    }

    /**
     * @return array{slot: string, opens_at: string}|null
     */
    public function nextSlot(Carbon $now): ?array
    {
        $seconds = $this->secondsSinceMidnight($now);

        foreach ($this->slotOrder() as $slot) {
            $window = config("attendance.slots.{$slot}");
            if ($window === null) {
                continue;
            }

            $opens = (int) $window['opens'] * 60;
            if ($seconds < $opens) {
                $opensMinutes = (int) $window['opens'];

                return [
                    'slot' => $slot,
                    'opens_at' => sprintf('%02d:%02d', intdiv($opensMinutes, 60), $opensMinutes % 60),
                ];
            }
        }

        return null;
    }

    /**
     * Build merged slot values for a server-authoritative check-in.
     *
     * Past slots (index < current) get a finalized 'absent' when still unresolved — the window
     * has already closed with no action. Future slots get 'pending' (unresolved, still overwritable).
     *
     * @return array{morning: string, lunch: string, evening: string}
     */
    public function buildCheckInSlots(?array $existingSlots, string $currentSlot, string $currentStatus): array
    {
        $order = $this->slotOrder();
        $currentIndex = array_search($currentSlot, $order, true);
        if ($currentIndex === false) {
            throw new \InvalidArgumentException("Unknown slot: {$currentSlot}");
        }

        $result = [];
        foreach ($order as $index => $slot) {
            $existing = $existingSlots[$slot] ?? null;
            if ($this->isSlotMarked($existing)) {
                $result[$slot] = $existing;

                continue;
            }

            if ($index < $currentIndex) {
                // Window already closed with no resolution — finalized absence
                $result[$slot] = 'absent';
            } elseif ($index === $currentIndex) {
                $result[$slot] = $currentStatus;
            } else {
                // Window has not opened yet — unresolved, must stay overwritable
                $result[$slot] = 'pending';
            }
        }

        return $result;
    }

    /**
     * Build slot values when a slot window closes with no student/coach action.
     *
     * Just-closed and earlier unresolved slots → absent (finalized).
     * Still-future unresolved slots → pending. Already-resolved slots are preserved.
     *
     * @param  array{morning?: string|null, lunch?: string|null, evening?: string|null}|null  $existingSlots
     * @return array{morning: string, lunch: string, evening: string}
     */
    public function buildFinalizeSlots(string $closedSlot, ?array $existingSlots = null): array
    {
        $order = $this->slotOrder();
        $closedIndex = array_search($closedSlot, $order, true);
        if ($closedIndex === false) {
            throw new \InvalidArgumentException("Unknown slot: {$closedSlot}");
        }

        $result = [];
        foreach ($order as $index => $slot) {
            $existing = $existingSlots[$slot] ?? null;
            if ($this->isSlotMarked($existing)) {
                $result[$slot] = $existing;

                continue;
            }

            $result[$slot] = $index <= $closedIndex ? 'absent' : 'pending';
        }

        return $result;
    }

    /**
     * Whether a slot has a real attendance determination (student check-in, coach mark,
     * or finalized absence after window close).
     *
     * 'pending' (and null/empty) means unresolved — not marked. 'absent' is always a real
     * determination and is marked.
     */
    public function isSlotMarked(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        $normalized = strtolower(trim($value));

        return $normalized !== '' && $normalized !== 'pending';
    }

    /**
     * Valid attendance slot status values (student check-in, coach save, finalize).
     *
     * @return list<string>
     */
    public function validStatuses(): array
    {
        return ['present', 'absent', 'late', 'excused', 'pending'];
    }

    /**
     * @return list<string>
     */
    public function markedSlots(?array $existingSlots): array
    {
        $marked = [];
        foreach ($this->slotOrder() as $slot) {
            $value = $existingSlots[$slot] ?? null;
            if ($this->isSlotMarked($value)) {
                $marked[] = $slot;
            }
        }

        return $marked;
    }

    private function secondsSinceMidnight(Carbon $now): int
    {
        return ($now->hour * 3600) + ($now->minute * 60) + $now->second;
    }
}
