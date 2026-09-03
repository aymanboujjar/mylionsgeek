import { ATTENDANCE_ERROR_CODES, ATTENDANCE_ERROR_MESSAGES } from './attendance-error-codes.js';

/**
 * Generic fallback when network-check fails without a server message.
 * Matches AttendanceCheckInPanel catch-copy convention.
 */
export const NETWORK_CHECK_GENERIC_ERROR = 'Network error. Please try again.';

/**
 * Map a failed network-check HTTP response to FlashMessage state.
 * Discriminates on error_code first so 403/503 responses with different
 * machine codes never share the same copy.
 *
 * @param {number} status
 * @param {{ error_code?: string, message?: string } | null | undefined} body
 * @returns {{ message: string, type: 'error' }}
 */
export function flashFromNetworkCheckFailure(status, body) {
    const errorCode = body?.error_code ?? null;

    if (errorCode === ATTENDANCE_ERROR_CODES.NOT_ON_SCHOOL_NETWORK) {
        return {
            message: ATTENDANCE_ERROR_MESSAGES[errorCode],
            type: 'error',
        };
    }

    if (errorCode === ATTENDANCE_ERROR_CODES.NETWORK_NOT_CONFIGURED) {
        return {
            message: ATTENDANCE_ERROR_MESSAGES[errorCode],
            type: 'error',
        };
    }

    return {
        message: body?.message || NETWORK_CHECK_GENERIC_ERROR,
        type: 'error',
    };
}
