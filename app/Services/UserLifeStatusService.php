<?php

namespace App\Services;

class UserLifeStatusService
{
    public const STUDYING = 'Studying';

    public const WORKING = 'Working';

    /** Life-status values users may pick manually (certificate/left live in program_status). */
    public const ALLOWED_VALUES = [
        self::STUDYING,
        self::WORKING,
        'Internship',
        'Unemployed',
        'Freelancing',
    ];

    public function defaultForRoles(array $roles): ?string
    {
        $normalized = array_map(static fn ($role) => strtolower((string) $role), $roles);

        if (in_array('recruiter', $normalized, true)) {
            return self::WORKING;
        }

        if (in_array('student', $normalized, true)) {
            return self::STUDYING;
        }

        return null;
    }
}
