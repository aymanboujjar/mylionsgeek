export const GENDER_OPTIONS = [
    { value: 'male', label: 'Male' },
    { value: 'female', label: 'Female' },
];

export const HANDICAP_OPTIONS = [
    { value: '1', label: 'Oui' },
    { value: '0', label: 'Non' },
];

/** LionsGEEK association / program lifecycle — separate from life status (Working, Studying…). */
export const PROGRAM_STATUS_OPTIONS = [
    { value: 'active', label: 'Active' },
    { value: 'laureate', label: 'Laureate' },
    { value: 'alumni', label: 'Alumni' },
    { value: 'left', label: 'Left' },
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
