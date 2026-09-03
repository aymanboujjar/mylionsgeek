<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * One-off data operation: treat the existing population as a finished cohort.
 *
 * Unlike program-status:backfill, this OVERWRITES values that are already set —
 * it is a deliberate reset of historical records, not an inference for empty
 * ones. It is not part of the normal lifecycle and should not be run on a
 * routine basis.
 *
 * Users whose life status is Left keep that outcome as 'left'. Staff-only
 * accounts are skipped, because the program lifecycle only describes students.
 */
class MarkExistingUsersAsCertified extends Command
{
    protected $signature = 'program-status:mark-existing-certified
        {--apply : Write the changes (default is a dry run)}
        {--limit= : Only process the first N users}';

    protected $description = 'One-off: set existing users to certified, or left when their life status is Left.';

    /** Accounts that are staff-only and never part of the student lifecycle. */
    private const EXCLUDED_ROLES = ['admin', 'super_admin', 'moderateur', 'recruiter'];

    public function handle(): int
    {
        $users = $this->candidates();

        if ($users->isEmpty()) {
            $this->info('No users to process.');

            return self::SUCCESS;
        }

        $resolved = $users->map(fn (User $user) => [
            'user' => $user,
            'status' => $this->resolveProgramStatus($user),
        ]);

        $this->report($resolved);

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn('Dry run only. Re-run with --apply to write these changes.');
            $this->warn('This overwrites existing program statuses — review the counts above first.');

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
        $query = User::query()->orderBy('id');

        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        return $query->get()->reject(fn (User $user) => $this->isExcludedStaff($user))->values();
    }

    private function isExcludedStaff(User $user): bool
    {
        $roles = is_array($user->role) ? $user->role : array_filter([(string) $user->role]);
        $roles = array_map(fn ($role) => strtolower(trim((string) $role)), $roles);

        return array_intersect($roles, self::EXCLUDED_ROLES) !== [];
    }

    /**
     * Everyone is treated as certified except students who left, whose outcome is
     * already known and must not be rewritten as a graduation.
     */
    private function resolveProgramStatus(User $user): string
    {
        return strtolower(trim((string) $user->status)) === 'left'
            ? User::PROGRAM_STATUS_LEFT
            : User::PROGRAM_STATUS_CERTIFIED;
    }

    /**
     * @param  Collection<int, array{user: User, status: string}>  $resolved
     */
    private function report(Collection $resolved): void
    {
        foreach ($resolved->groupBy('status') as $status => $rows) {
            $this->newLine();
            $this->line("<info>{$status}</info> — ".$rows->count().' user(s)');
        }

        $changing = $resolved->filter(fn (array $row) => $row['user']->program_status !== $row['status']);

        $this->newLine();
        $this->line('Users in scope: '.$resolved->count());
        $this->line('Values that will change: '.$changing->count());
        $this->line('Already correct: '.($resolved->count() - $changing->count()));

        if ($changing->isNotEmpty()) {
            $this->newLine();
            $this->line('<comment>Overwrites by previous value:</comment>');

            $byPrevious = $changing->groupBy(fn (array $row) => $row['user']->program_status ?? '(null)');

            foreach ($byPrevious as $previous => $rows) {
                $targets = $rows->groupBy('status')
                    ->map(fn (Collection $group, string $target) => $target.' × '.$group->count())
                    ->implode(', ');

                $this->line("  {$previous} → {$targets}");
            }
        }
    }

    /**
     * @param  Collection<int, array{user: User, status: string}>  $resolved
     */
    private function apply(Collection $resolved): int
    {
        $written = 0;

        foreach ($resolved->groupBy('status') as $status => $rows) {
            $ids = $rows->map(fn (array $row) => $row['user']->id)->all();

            foreach (array_chunk($ids, 500) as $chunk) {
                $written += User::query()
                    ->whereIn('id', $chunk)
                    ->where(function ($query) use ($status) {
                        $query->whereNull('program_status')->orWhere('program_status', '!=', $status);
                    })
                    ->update(['program_status' => $status]);
            }
        }

        return $written;
    }
}
