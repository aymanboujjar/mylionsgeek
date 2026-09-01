export const GENDER_OPTIONS = [
    { value: 'male', label: 'Male' },
    { value: 'female', label: 'Female' },
];

export const HANDICAP_OPTIONS = [
    { value: '1', label: 'Oui' },
    { value: '0', label: 'Non' },
];

/** LionsGEEK program lifecycle — separate from life status (Working, Studying…). */
export const PROGRAM_STATUS = {
    ACTIVE: 'active',
    CERTIFIED: 'certified',
    NOT_CERTIFIED: 'not_certified',
    LEFT: 'left',
};

export const PROGRAM_STATUS_OPTIONS = [
    { value: PROGRAM_STATUS.ACTIVE, label: 'Active' },
    { value: PROGRAM_STATUS.CERTIFIED, label: 'Certified' },
    { value: PROGRAM_STATUS.NOT_CERTIFIED, label: 'Not Certified' },
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
