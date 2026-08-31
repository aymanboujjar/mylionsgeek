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
}
