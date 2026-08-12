import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    flashFromNetworkCheckFailure,
    NETWORK_CHECK_GENERIC_ERROR,
} from './attendance-network-check.js';

describe('flashFromNetworkCheckFailure', () => {
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
