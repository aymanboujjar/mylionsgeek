<?php

namespace App\Services;

use App\Models\Formation;
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
    public const ACTIVE = 'active';

    public const CERTIFIED = 'certified';

    public const NOT_CERTIFIED = 'not_certified';

    public const LEFT = 'left';

    /**
     * Admins and coaches may assign program status "left".
     * When a training is provided, coaches must be assigned to that training.
     */
    public function canAssignLeft(User $actor, ?Formation $training = null): bool
    {
        $roles = is_array($actor->role) ? $actor->role : array_filter([(string) $actor->role]);
        $rolesLower = array_map('strtolower', array_map('strval', $roles));

        if (count(array_intersect($rolesLower, ['admin', 'super_admin'])) > 0) {
            return true;
        }

        if (in_array('coach', $rolesLower, true)) {
            if ($training === null) {
                return true;
            }

            return (int) $training->user_id === (int) $actor->id;
        }

        return false;
    }

    public function isLeft(?string $programStatus, ?string $legacyLifeStatus = null): bool
    {
        if ($programStatus === self::LEFT) {
            return true;
        }

        if ($programStatus === null || $programStatus === '') {
            return strtolower(trim((string) $legacyLifeStatus)) === 'left';
        }

        return false;
    }

    public function isActiveOrUnset(?string $programStatus): bool
    {
        return $programStatus === null || $programStatus === '' || $programStatus === self::ACTIVE;
    }

    /**
     * Mark a user as active in the program when they join a training.
     *
     * Only fills an empty value. Re-adding a former student to a new cohort must
     * not silently erase their 'left' or 'not_certified' record.
     *
     * @return bool True when the status was written.
     */
    public function markActiveOnEnrollment(User $user): bool
    {
        if (filled($user->program_status)) {
            return false;
        }

        $user->program_status = self::ACTIVE;
        $user->save();

        return true;
    }

    /**
     * Set active on the in-memory model when enrolling (caller saves).
     *
     * Only fills an empty value — same guard as markActiveOnEnrollment().
     */
    public function applyEnrollmentStatus(User $user): void
    {
        if (filled($user->program_status)) {
            return;
        }

        $user->program_status = self::ACTIVE;
    }

    /**
     * The program status a brand-new user should be created with.
     *
     * Users created without a training (staff, recruiters, coworkers) stay null.
     */
    public function initialProgramStatusFor(?int $formationId): ?string
    {
        return filled($formationId) ? self::ACTIVE : null;
    }

    /**
     * Bulk-mark successful certificate recipients as certified.
     *
     * Idempotent: rows already certified are left unchanged.
     *
     * @param  list<int>  $userIds
     * @return int Number of rows updated.
     */
    public function markCertified(array $userIds): int
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        if ($userIds === []) {
            return 0;
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->where(function ($query) {
                $query->whereNull('program_status')
                    ->orWhere('program_status', '!=', self::CERTIFIED);
            })
            ->update(['program_status' => self::CERTIFIED]);
    }

    /**
     * After certificates are printed: unselected active students become not_certified.
     * Left, certified, and not_certified students are unchanged.
     *
     * @param  list<int>  $certifiedUserIds
     * @return int Number of rows updated.
     */
    public function markUnselectedActiveStudentsAsNotCertified(Formation $training, array $certifiedUserIds): int
    {
        $certifiedUserIds = array_values(array_unique(array_map('intval', $certifiedUserIds)));

        return User::query()
            ->where('formation_id', $training->id)
            ->when($certifiedUserIds !== [], fn ($query) => $query->whereNotIn('id', $certifiedUserIds))
            ->where(function ($query) {
                $query->whereNull('program_status')
                    ->orWhere('program_status', self::ACTIVE);
            })
            ->update(['program_status' => self::NOT_CERTIFIED]);
    }

    public function assertCanAssignLeft(User $actor, ?string $programStatus, ?Formation $training = null): void
    {
        if ($programStatus !== self::LEFT) {
            return;
        }

        if (! $this->canAssignLeft($actor, $training)) {
            abort(403, 'Only admins and coaches can set program status to Left.');
        }
    }
}
