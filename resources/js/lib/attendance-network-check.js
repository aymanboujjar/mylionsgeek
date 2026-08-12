/**
 * Generic fallback when network-check fails without a server message.
 * Matches AttendanceCheckInPanel catch-copy convention.
 */
export const NETWORK_CHECK_GENERIC_ERROR = 'Network error. Please try again.';

/**
 * Map a failed network-check HTTP response to FlashMessage state.
 * Preserves school.network 403/503 messages when present; otherwise generic fallback.
 *
 * @param {number} status
 * @param {{ message?: string } | null | undefined} body
 * @returns {{ message: string, type: 'error' }}
 */
export function flashFromNetworkCheckFailure(status, body) {
    if ((status === 403 || status === 503) && body?.message) {
        return { message: body.message, type: 'error' };
    }

    return { message: NETWORK_CHECK_GENERIC_ERROR, type: 'error' };
}
