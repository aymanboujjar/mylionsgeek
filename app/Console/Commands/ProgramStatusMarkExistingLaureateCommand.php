<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ProgramStatusService;
use Illuminate\Console\Command;

class ProgramStatusMarkExistingLaureateCommand extends Command
{
    protected $signature = 'program-status:mark-existing-laureate {--apply : Persist changes (default is dry-run)}';

    protected $description = 'Mark users with certificate PDFs as certified (destructive — dry-run first).';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $users = User::query()
            ->where(function ($query) {
                $query->whereNotNull('certificate_pdf_path')
                    ->orWhereNotNull('certified_at');
            })
            ->where(function ($query) {
                $query->whereNull('program_status')
                    ->orWhere('program_status', '!=', ProgramStatusService::CERTIFIED);
            })
            ->orderBy('id')
            ->get();

        if ($users->isEmpty()) {
            $this->info('No users need program_status certified backfill.');

            return self::SUCCESS;
        }

        $this->warn(($apply ? 'Applying' : 'Dry-run').': '.$users->count().' user(s) would be set to '.ProgramStatusService::CERTIFIED.'.');

        foreach ($users as $user) {
            $this->line(sprintf(
                '  #%d %s (current=%s, certified_at=%s)',
                $user->id,
                $user->name,
                $user->program_status ?? 'null',
                $user->certified_at ?? 'null'
            ));
        }

        if (! $apply) {
            $this->warn('Dry-run only. Re-run with --apply to persist.');

            return self::SUCCESS;
        }

        if (! $this->confirm('This will overwrite program_status for the users listed above. Continue?')) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $updated = User::query()
            ->whereIn('id', $users->pluck('id'))
            ->update(['program_status' => ProgramStatusService::CERTIFIED]);

        $this->info("Updated {$updated} user(s) to ".ProgramStatusService::CERTIFIED.'.');

        return self::SUCCESS;
    }
}
