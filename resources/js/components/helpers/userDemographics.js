export const GENDER_OPTIONS = [
    { value: 'male', label: 'Male' },
    { value: 'female', label: 'Female' },
];

export const HANDICAP_OPTIONS = [
    { value: '1', label: 'Yes' },
    { value: '0', label: 'No' },
];

/**
 * LionsGEEK program lifecycle. Independent of `status`, which tracks life situation
 * (Working, Studying, Freelancing…) and must never be derived from these values.
 *
 *   active    — currently following the training
 *   laureate  — finished and received a certificate
 *   completed — finished but received no certificate
 *   left      — did not finish; set by hand by an admin or coach
 */
export const PROGRAM_STATUS = {
    ACTIVE: 'active',
    LAUREATE: 'laureate',
    COMPLETED: 'completed',
    LEFT: 'left',
};

export const PROGRAM_STATUS_OPTIONS = [
    { value: PROGRAM_STATUS.ACTIVE, label: 'Active' },
    { value: PROGRAM_STATUS.LAUREATE, label: 'Laureate' },
    { value: PROGRAM_STATUS.COMPLETED, label: 'Completed' },
    { value: PROGRAM_STATUS.LEFT, label: 'Left' },
];

export const genderLabel = (gender) => {
    if (gender === 'male') return 'Male';
    if (gender === 'female') return 'Female';
    return null;
};

export const programStatusLabel = (programStatus) => {
    const match = PROGRAM_STATUS_OPTIONS.find((option) => option.value === programStatus);
    return match?.label ?? null;
};

export const handicapSelectValue = (hasHandicap) => {
    if (hasHandicap === true || hasHandicap === 1 || hasHandicap === '1') return '1';
    if (hasHandicap === false || hasHandicap === 0 || hasHandicap === '0') return '0';
    return 'none';
};
