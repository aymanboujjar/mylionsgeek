import { ATTENDANCE_ERROR_CODES, ATTENDANCE_ERROR_MESSAGES } from './attendance-error-codes.js';
import { alreadyMarkedSlots, slotLabel } from './attendance-slots.js';

/** @param {object} slotStatus */
export function buildButtonLabel(slotStatus) {
    const { current_slot, phase } = slotStatus;
    const marked = alreadyMarkedSlots(slotStatus);

    if (current_slot && marked.includes(current_slot)) {
        return `${slotLabel(current_slot)} ✓`;
    }

    if (phase === 'closed') {
        return 'Attendance closed';
    }

    if (phase === 'gap') {
        return 'Post-coffee break';
    }

    if (current_slot) {
        return `Check in — ${slotLabel(current_slot)}`;
    }

    return 'Check in';
}

/** @param {object} slotStatus */
export function buildHelperText(slotStatus) {
    const { current_slot, phase, minutes_into_slot, next_slot, present_minutes } = slotStatus;
    const marked = alreadyMarkedSlots(slotStatus);
    const presentWindow = present_minutes ?? 15;

    if (current_slot && marked.includes(current_slot)) {
        return `You have already marked ${slotLabel(current_slot).toLowerCase()} attendance today.`;
    }

    if (phase === 'closed') {
        if (next_slot) {
            return `Attendance opens at ${next_slot.opens_at} (${slotLabel(next_slot.slot)}).`;
        }
        return 'Attendance is only available between 09:30 and 17:00.';
    }

    if (phase === 'gap') {
        if (next_slot) {
            return `Next slot: ${slotLabel(next_slot.slot)} at ${next_slot.opens_at}.`;
        }
        return 'No attendance slot is active right now.';
    }

    if (current_slot && minutes_into_slot !== null && minutes_into_slot < presentWindow) {
        const remaining = presentWindow - minutes_into_slot;
        return `Mark within ${remaining} min to count as present (not late).`;
    }

    if (current_slot) {
        return 'You are in the late window for this slot.';
    }

    return '';
}

/** @param {object} slotStatus @param {boolean} submitting */
export function isCheckInDisabled(slotStatus, submitting) {
    if (submitting) {
        return true;
    }

    const { current_slot, phase } = slotStatus;

    if (phase !== 'active' || !current_slot) {
        return true;
    }

    return alreadyMarkedSlots(slotStatus).includes(current_slot);
}

/**
 * Resolves a failed check-in response into a structured result the UI can act on.
 *
 * error_code is checked first — before HTTP status — so 503 FACE_SERVICE_UNAVAILABLE
 * and 503 NETWORK_NOT_CONFIGURED never collide, and FACE_NOT_RECOGNIZED always
 * reopens the face dialog regardless of status.
 *
 * @param {number} status
 * @param {{ error_code?: string, message?: string } | null | undefined} data
 * @returns {{ type: 'face_error' | 'inline_error', message: string, errorCode: string | null }}
 */
export function resolveCheckInError(status, data) {
    const errorCode = data?.error_code ?? null;

    if (errorCode === ATTENDANCE_ERROR_CODES.FACE_NOT_RECOGNIZED) {
        return {
            type: 'face_error',
            message: "Hmm, we couldn't tell it was you",
            errorCode,
        };
    }

    const message = ATTENDANCE_ERROR_MESSAGES[errorCode] ?? data?.message ?? ATTENDANCE_ERROR_MESSAGES.fallback;

    return {
        type: 'inline_error',
        message,
        errorCode,
    };
}
