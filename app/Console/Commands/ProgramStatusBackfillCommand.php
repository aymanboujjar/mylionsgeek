<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ProgramStatusService;
use Illuminate\Console\Command;

class ProgramStatusBackfillCommand extends Command
{
    protected $signature = 'program-status:backfill {--apply : Persist changes (default is dry-run)}';

    protected $description = 'Backfill null program_status values for enrolled students (sets active).';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $users = User::query()
            ->whereNotNull('formation_id')
            ->where(function ($query) {
                $query->whereNull('program_status')
                    ->orWhere('program_status', '');
            })
            ->orderBy('id')
            ->get();

        if ($users->isEmpty()) {
            $this->info('No enrolled users with null program_status found.');

            return self::SUCCESS;
        }

        $this->info(($apply ? 'Applying' : 'Dry-run').': '.$users->count().' user(s) would be set to '.ProgramStatusService::ACTIVE.'.');

        foreach ($users as $user) {
            $this->line(sprintf(
                '  #%d %s (formation_id=%s, current=%s)',
                $user->id,
                $user->name,
                $user->formation_id,
                $user->program_status ?? 'null'
            ));
        }

        if (! $apply) {
            $this->warn('Dry-run only. Re-run with --apply to persist.');

            return self::SUCCESS;
        }

        $updated = User::query()
            ->whereIn('id', $users->pluck('id'))
            ->update(['program_status' => ProgramStatusService::ACTIVE]);

        $this->info("Updated {$updated} user(s) to ".ProgramStatusService::ACTIVE.'.');

        return self::SUCCESS;
    }
}
