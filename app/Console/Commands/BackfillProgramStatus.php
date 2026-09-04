<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * One-time backfill for the program_status column.
 *
 * program_status was added after most users already existed, so nearly every row
 * is null. Every later lifecycle transition only moves users out of 'active',
 * which means nothing works until this has run at least once.
 *
 * Dry-run by default; re-runnable because it only ever fills a null value.
 */
class BackfillProgramStatus extends Command
{
    protected $signature = 'program-status:backfill
        {--apply : Write the changes (default is a dry run)}
        {--limit= : Only process the first N users without a program status}';

    protected $description = 'Infer program_status for users who do not have one yet.';

    /** Life-status values that mean the student is no longer in training. */
    private const POST_TRAINING_STATUSES = ['working', 'freelancing', 'internship', 'unemployed'];

    public function handle(): int
    {
        $users = $this->candidates();

        if ($users->isEmpty()) {
            $this->info('Every user already has a program status. Nothing to do.');

            return self::SUCCESS;
        }

        $resolved = $users
            ->map(fn (User $user) => [
                'user' => $user,
                'status' => $this->resolveProgramStatus($user),
            ])
            ->filter(fn (array $row) => $row['status'] !== null);

        $this->report($users, $resolved);

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn('Dry run only. Re-run with --apply to write these changes.');

            return self::SUCCESS;
        }

        $written = $this->apply($resolved);

        $this->newLine();
        $this->info("Applied {$written} update(s).");

        return self::SUCCESS;
    }

    /** @return Collection<int, User> */
    private function candidates(): Collection
    {
        $query = User::query()
            ->where(function ($q) {
                $q->whereNull('program_status')->orWhere('program_status', '');
            })
            ->orderBy('id');

        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        return $query->get();
    }

    /**
     * Infer the lifecycle position from the strongest available signal.
     *
     * Certification outranks everything: holding a certificate is a fact, whatever
     * the student is doing now. Users with no training at all (staff, recruiters,
     * coworkers) stay null, because the program lifecycle only describes students.
     */
    private function resolveProgramStatus(User $user): ?string
    {
        if ($user->certified_at !== null) {
            return User::PROGRAM_STATUS_CERTIFIED;
        }

        $lifeStatus = strtolower(trim((string) $user->status));

        if ($lifeStatus === 'left') {
            return User::PROGRAM_STATUS_LEFT;
        }

        if (blank($user->formation_id)) {
            return null;
        }

        if ($lifeStatus === 'studying') {
            return User::PROGRAM_STATUS_ACTIVE;
        }

        if (in_array($lifeStatus, self::POST_TRAINING_STATUSES, true)) {
            return User::PROGRAM_STATUS_NOT_CERTIFIED;
        }

        // Enrolled but the life status says nothing useful — assume still training.
        return User::PROGRAM_STATUS_ACTIVE;
    }

    /**
     * @param  Collection<int, User>  $candidates
     * @param  Collection<int, array{user: User, status: string}>  $resolved
     */
    private function report(Collection $candidates, Collection $resolved): void
    {
        foreach ($resolved->groupBy('status') as $status => $rows) {
            $this->newLine();
            $this->line("<info>{$status}</info> — ".$rows->count().' user(s)');

            foreach ($rows as $row) {
                /** @var User $user */
                $user = $row['user'];
                $this->line(sprintf(
                    '  id=%s  %s  (life status: %s, training: %s, certified: %s)',
                    $user->id,
                    $user->name,
                    $user->status ?: '—',
                    $user->formation_id ?: '—',
                    $user->certified_at ? 'yes' : 'no',
                ));
            }
        }

        $untouched = $candidates->count() - $resolved->count();

        $this->newLine();
        $this->line('Users without a program status: '.$candidates->count());
        $this->line('Will be set: '.$resolved->count());
        $this->line("Left as null (no training, not a student): {$untouched}");
    }

    /**
     * @param  Collection<int, array{user: User, status: string}>  $resolved
     */
    private function apply(Collection $resolved): int
    {
        $written = 0;

        foreach ($resolved->groupBy('status') as $status => $rows) {
            $ids = $rows->map(fn (array $row) => $row['user']->id)->all();

            // Repeat the null guard so a value set by hand between the read and the
            // write is never clobbered.
            foreach (array_chunk($ids, 500) as $chunk) {
                $written += User::query()
                    ->whereIn('id', $chunk)
                    ->where(function ($q) {
                        $q->whereNull('program_status')->orWhere('program_status', '');
                    })
                    ->update(['program_status' => $status]);
            }
        }

        return $written;
    }
}
