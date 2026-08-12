<?php

namespace App\Services;

use Carbon\Carbon;

class StudentCheckInSlotService
{
    public function __construct(
        private readonly AttendanceSlotService $slotService,
    ) {}

    /**
     * Whether the note list contains at least one student check-in note.
     */
    public function hasCheckInNote(?string $pipeJoinedNotes): bool
    {
        return $this->containsCheckInNote($this->parseNoteTexts($pipeJoinedNotes));
    }

    /**
     * Infer which slots were actively marked by a student check-in (Approach 1).
     *
     * Check-in notes are per-day ("Check-in at HH:mm") and do not name a slot. Rules:
     *
     * 1. Map each check-in note time to the slot active at that instant (AttendanceSlotService).
     * 2. Times that fall in a gap or outside school hours do not mark any slot (no row-wide fallback).
     *
     * Past unresolved slots become finalized 'absent'; future slots stay 'pending'. Neither is
     * student-marked — only slots whose check-in note time maps into that slot window are.
     *
     * Legacy rows without check-in notes are never student-marked (coach-editable).
     *
     * @param  array<string, string|null>|null  $existingSlots
     * @return list<string>
     */
    public function studentMarkedSlots(string $attendanceDay, ?string $pipeJoinedNotes, ?array $existingSlots): array
    {
        $noteTexts = $this->parseNoteTexts($pipeJoinedNotes);
        $checkInTimes = $this->parseCheckInTimes($noteTexts);

        if ($checkInTimes === [] && ! $this->containsCheckInNote($noteTexts)) {
            return [];
        }

        $marked = [];

        foreach ($checkInTimes as $time) {
            $slot = $this->slotForCheckInTime($attendanceDay, $time);
            if ($slot !== null) {
                $marked[$slot] = true;
            }
        }

        return array_values(array_intersect($this->slotService->slotOrder(), array_keys($marked)));
    }

    /**
     * @return list<string>
     */
    private function parseNoteTexts(?string $pipeJoinedNotes): array
    {
        if ($pipeJoinedNotes === null || trim($pipeJoinedNotes) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(' | ', $pipeJoinedNotes))));
    }

    /**
     * @param  list<string>  $noteTexts
     */
    private function containsCheckInNote(array $noteTexts): bool
    {
        foreach ($noteTexts as $note) {
            if (preg_match('/^Check-in at \d{2}:\d{2}$/', $note) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $noteTexts
     * @return list<string>  Times in H:i format
     */
    private function parseCheckInTimes(array $noteTexts): array
    {
        $times = [];

        foreach ($noteTexts as $note) {
            if (preg_match('/^Check-in at (\d{2}:\d{2})$/', $note, $matches) === 1) {
                $times[] = $matches[1];
            }
        }

        return $times;
    }

    private function slotForCheckInTime(string $attendanceDay, string $time): ?string
    {
        if ($attendanceDay === '') {
            return null;
        }

        try {
            $instant = Carbon::parse("{$attendanceDay} {$time}");
        } catch (\Throwable) {
            return null;
        }

        return $this->slotService->currentSlot($instant);
    }
}
