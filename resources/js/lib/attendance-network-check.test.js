import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { ATTENDANCE_ERROR_CODES, ATTENDANCE_ERROR_MESSAGES } from './attendance-error-codes.js';
import { flashFromNetworkCheckFailure, NETWORK_CHECK_GENERIC_ERROR } from './attendance-network-check.js';

describe('flashFromNetworkCheckFailure', () => {
    it('maps NOT_ON_SCHOOL_NETWORK via error_code, not status', () => {
        const flash = flashFromNetworkCheckFailure(403, {
            error_code: ATTENDANCE_ERROR_CODES.NOT_ON_SCHOOL_NETWORK,
            message: 'server-specific wifi copy',
        });

        assert.deepEqual(flash, {
            message: ATTENDANCE_ERROR_MESSAGES[ATTENDANCE_ERROR_CODES.NOT_ON_SCHOOL_NETWORK],
            type: 'error',
        });
    });

    it('maps NETWORK_NOT_CONFIGURED via error_code so it does not collide with other 503s', () => {
        const flash = flashFromNetworkCheckFailure(503, {
            error_code: ATTENDANCE_ERROR_CODES.NETWORK_NOT_CONFIGURED,
            message: 'Attendance network is not configured.',
        });

        assert.equal(flash.message, ATTENDANCE_ERROR_MESSAGES[ATTENDANCE_ERROR_CODES.NETWORK_NOT_CONFIGURED]);
        assert.equal(flash.type, 'error');
    });

    it('preserves 403 message from school.network', () => {
        const flash = flashFromNetworkCheckFailure(403, {
            message: 'You must be connected to the school WiFi to check in.',
        });

        assert.deepEqual(flash, {
            message: 'You must be connected to the school WiFi to check in.',
            type: 'error',
        });
    });

    it('preserves 503 message when present', () => {
        const flash = flashFromNetworkCheckFailure(503, {
            message: 'Attendance network is not configured.',
        });

        assert.equal(flash.message, 'Attendance network is not configured.');
        assert.equal(flash.type, 'error');
    });

    it('uses generic fallback for 401', () => {
        assert.deepEqual(flashFromNetworkCheckFailure(401, {}), {
            message: NETWORK_CHECK_GENERIC_ERROR,
            type: 'error',
        });
    });

    it('uses generic fallback for 500', () => {
        assert.deepEqual(flashFromNetworkCheckFailure(500, { error: 'boom' }), {
            message: NETWORK_CHECK_GENERIC_ERROR,
            type: 'error',
        });
    });

    it('uses generic fallback for 403 with empty/missing message', () => {
        assert.deepEqual(flashFromNetworkCheckFailure(403, {}), {
            message: NETWORK_CHECK_GENERIC_ERROR,
            type: 'error',
        });
        assert.deepEqual(flashFromNetworkCheckFailure(403, null), {
            message: NETWORK_CHECK_GENERIC_ERROR,
            type: 'error',
        });
        assert.deepEqual(flashFromNetworkCheckFailure(503, { message: '' }), {
            message: NETWORK_CHECK_GENERIC_ERROR,
            type: 'error',
        });
    });

    it('uses generic fallback for network/fetch failure (status 0)', () => {
        assert.deepEqual(flashFromNetworkCheckFailure(0, null), {
            message: NETWORK_CHECK_GENERIC_ERROR,
            type: 'error',
        });
    });
});
