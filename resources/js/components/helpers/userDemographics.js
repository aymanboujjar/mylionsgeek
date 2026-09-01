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
    { value: PROGRAM_STATUS.LEFT, label: 'Left' },
    { value: PROGRAM_STATUS.CERTIFIED, label: 'Certificate' },
    { value: PROGRAM_STATUS.NOT_CERTIFIED, label: 'Not Certificate' },
];

/** Filter-only: match students who finished with or without a certificate. */
export const PROGRAM_STATUS_FILTER_CERTIFICATE_OUTCOMES = 'certificate_outcomes';

export const PROGRAM_STATUS_CERTIFICATE_OUTCOME_OPTIONS = [
    { value: PROGRAM_STATUS.CERTIFIED, label: 'Certificate' },
    { value: PROGRAM_STATUS.NOT_CERTIFIED, label: 'Not Certificate' },
];

export const PROGRAM_STATUS_FILTER_OPTIONS = [
    { value: PROGRAM_STATUS.ACTIVE, label: 'Active' },
    { value: PROGRAM_STATUS.LEFT, label: 'Left' },
    { value: PROGRAM_STATUS_FILTER_CERTIFICATE_OUTCOMES, label: 'Certificate & Not Certificate' },
    ...PROGRAM_STATUS_CERTIFICATE_OUTCOME_OPTIONS,
];

export const matchesProgramStatusFilter = (userProgramStatus, filterValue) => {
    if (!filterValue || filterValue === 'all') {
        return true;
    }

    if (filterValue === PROGRAM_STATUS_FILTER_CERTIFICATE_OUTCOMES) {
        return userProgramStatus === PROGRAM_STATUS.CERTIFIED || userProgramStatus === PROGRAM_STATUS.NOT_CERTIFIED;
    }

    return (userProgramStatus || '') === filterValue;
};

const normalizeRoles = (roles) => {
    const list = Array.isArray(roles) ? roles : [roles];
    return list.filter(Boolean).map((role) => String(role).toLowerCase());
};

/** Health data (handicap) — admin / super_admin only. */
export const canViewHealthData = (roles) => {
    const normalized = normalizeRoles(roles);
    return normalized.some((role) => ['admin', 'super_admin'].includes(role));
};

/** Only admins and coaches may assign program status "left". */
export const canAssignProgramStatusLeft = (roles, { isTrainingCoach = false, requireTrainingCoach = false } = {}) => {
    const normalized = normalizeRoles(roles);

    if (normalized.some((role) => ['admin', 'super_admin'].includes(role))) {
        return true;
    }

    if (normalized.includes('coach')) {
        return requireTrainingCoach ? isTrainingCoach : true;
    }

    return false;
};

/** Program status options for edit/bulk forms; keeps Left visible when already set. */
export const resolveProgramStatusEditOptions = (currentProgramStatus, roles, options = {}) => {
    const canAssignLeft =
        canAssignProgramStatusLeft(roles, options) || currentProgramStatus === PROGRAM_STATUS.LEFT;

    return PROGRAM_STATUS_OPTIONS.filter((option) => option.value !== PROGRAM_STATUS.LEFT || canAssignLeft);
};

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
