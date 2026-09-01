<?php

namespace App\Services;

use App\Models\Formation;
use App\Models\User;

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
     * New enrollments start as active in the program lifecycle.
     */
    public function applyEnrollmentStatus(User $user): void
    {
        $user->program_status = self::ACTIVE;
    }

    /**
     * After certificates are printed: unselected active students become not_certified.
     * Left, certified, and not_certified students are unchanged.
     *
     * @param  list<int>  $certifiedUserIds
     */
    public function markUnselectedActiveStudentsAsNotCertified(Formation $training, array $certifiedUserIds): void
    {
        $certifiedUserIds = array_values(array_unique(array_map('intval', $certifiedUserIds)));

        User::query()
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
