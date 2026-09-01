export const ATTENDANCE_ERROR_CODES = {
    NOT_ON_SCHOOL_NETWORK: 'NOT_ON_SCHOOL_NETWORK',
    NETWORK_NOT_CONFIGURED: 'NETWORK_NOT_CONFIGURED',
    FACE_SERVICE_UNAVAILABLE: 'FACE_SERVICE_UNAVAILABLE',
    FACE_NOT_RECOGNIZED: 'FACE_NOT_RECOGNIZED',
    NO_ACTIVE_SLOT: 'NO_ACTIVE_SLOT',
    ALREADY_CHECKED_IN: 'ALREADY_CHECKED_IN',
};

export const ATTENDANCE_ERROR_MESSAGES = {
    [ATTENDANCE_ERROR_CODES.NOT_ON_SCHOOL_NETWORK]: 'You must be connected to the school WiFi to check in.',
    [ATTENDANCE_ERROR_CODES.NETWORK_NOT_CONFIGURED]: "Attendance isn't configured on this server yet. Please contact a staff member.",
    [ATTENDANCE_ERROR_CODES.FACE_SERVICE_UNAVAILABLE]: 'Face verification is temporarily unavailable. Your teacher can mark you in manually.',
    [ATTENDANCE_ERROR_CODES.NO_ACTIVE_SLOT]: "There's no active attendance slot right now.",
    [ATTENDANCE_ERROR_CODES.ALREADY_CHECKED_IN]: "You've already checked in for this slot.",
    fallback: 'Unable to check in right now. Please try again.',
};
