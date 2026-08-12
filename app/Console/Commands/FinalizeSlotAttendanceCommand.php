<?php

namespace App\Console\Commands;

use App\Jobs\FinalizeSlotAttendance;
use App\Services\AttendanceSlotService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class FinalizeSlotAttendanceCommand extends Command
{
    protected $signature = 'attendance:finalize-closed-slots
                            {--slot= : Force a specific slot (morning|lunch|evening); defaults to latest closed slot}
                            {--date= : Attendance day Y-m-d; defaults to today}';

    protected $description = 'Finalize unresolved attendance slots closed as of now (runs synchronously)';

    public function handle(AttendanceSlotService $slotService): int
    {
        $now = Carbon::now();
        $date = $this->option('date') ?: $now->toDateString();
        $slotOption = $this->option('slot');

        if ($slotOption !== null && $slotOption !== '') {
            $slot = (string) $slotOption;
            if (! in_array($slot, $slotService->slotOrder(), true)) {
                $this->error("Unknown slot [{$slot}]. Expected one of: ".implode(', ', $slotService->slotOrder()));

                return self::FAILURE;
            }
        } else {
            $isPastDate = $date < $now->toDateString();
            $slot = $slotService->latestClosedSlot($now, $isPastDate);

            if ($slot === null) {
                $this->warn('No attendance slots have closed yet today — nothing to finalize.');

                return self::SUCCESS;
            }
        }

        // Sync: pure DB work — no queue. Per-student isolation lives in the job handle loop.
        FinalizeSlotAttendance::dispatchSync($slot, $date);

        $this->info("Finalized attendance for slot [{$slot}] on [{$date}]");

        return self::SUCCESS;
    }
}
