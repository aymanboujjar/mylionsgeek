<?php

namespace App\Services;

use App\Models\User;

/**
 * Owns every transition of the `program_status` column.
 *
 * The program lifecycle is deliberately independent of the `status` column,
 * which records life situation (Working, Studying…). Nothing here reads or
 * writes `status`.
 */
class ProgramStatusService
{
    /**
     * Mark a user as active in the program when they join a training.
     *
     * Only fills an empty value. Re-adding a former student to a new cohort must
     * not silently erase their 'left' or 'completed' record — promoting someone
     * back to active is a deliberate decision an admin makes in the edit modal.
     *
     * @return bool True when the status was written.
     */
    public function markActiveOnEnrollment(User $user): bool
    {
        if (filled($user->program_status)) {
            return false;
        }

        $user->program_status = User::PROGRAM_STATUS_ACTIVE;
        $user->save();

        return true;
    }

    /**
     * The program status a brand-new user should be created with.
     *
     * Users created without a training (staff, recruiters, coworkers) stay null —
     * the program lifecycle only describes students.
     */
    public function initialProgramStatusFor(?int $formationId): ?string
    {
        return filled($formationId) ? User::PROGRAM_STATUS_ACTIVE : null;
    }

    /**
     * Mark the students selected for a certificate print as laureates.
     *
     * Selection is treated as the staff's decision that the student earned the
     * certificate, so every selected student is marked even if their PDF failed
     * to generate. The modal surfaces those failures separately as warnings.
     *
     * @param  list<int>  $userIds
     * @return int Number of students updated.
     */
    public function markLaureates(array $userIds): int
    {
        return $this->transitionTo(User::PROGRAM_STATUS_LAUREATE, $userIds);
    }

    /**
     * Mark everyone left unselected in a certificate print as having completed the
     * training without a certificate.
     *
     * Only students sitting at 'active' are moved. That single guard is what keeps
     * the sweep safe: students who left, already completed, or are already
     * laureates are never rewritten, and neither is anyone whose lifecycle has not
     * been backfilled yet.
     *
     * @param  list<int>  $selectedUserIds  Students who were selected, and so excluded.
     * @return int Number of students updated.
     */
    public function markUnselectedAsCompleted(int $formationId, array $selectedUserIds): int
    {
        return User::query()
            ->where('formation_id', $formationId)
            ->when($selectedUserIds !== [], fn ($query) => $query->whereNotIn('id', $selectedUserIds))
            ->where('program_status', User::PROGRAM_STATUS_ACTIVE)
            ->update(['program_status' => User::PROGRAM_STATUS_COMPLETED]);
    }

    /**
     * Bulk-write a program status, never touching a student who has left.
     *
     * Callers already filter students who left, but the guard is repeated here so
     * the service is safe to call on its own. Note the explicit null check: in SQL
     * `program_status != 'left'` is unknown for null rows and would exclude them.
     *
     * @param  list<int>  $userIds
     */
    private function transitionTo(string $programStatus, array $userIds): int
    {
        if ($userIds === []) {
            return 0;
        }

        $updated = 0;

        foreach (array_chunk($userIds, 500) as $chunk) {
            $updated += User::query()
                ->whereIn('id', $chunk)
                ->where(function ($query) {
                    $query->whereNull('program_status')
                        ->orWhere('program_status', '!=', User::PROGRAM_STATUS_LEFT);
                })
                ->update(['program_status' => $programStatus]);
        }

        return $updated;
    }
}
