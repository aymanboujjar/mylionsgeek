import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { resolveCheckInError } from './attendance-check-in-ui.js';
import { ATTENDANCE_ERROR_CODES, ATTENDANCE_ERROR_MESSAGES } from './attendance-error-codes.js';

describe('resolveCheckInError', () => {
    it('sends FACE_NOT_RECOGNIZED to the face dialog regardless of HTTP status', () => {
        const resolved = resolveCheckInError(503, {
            error_code: ATTENDANCE_ERROR_CODES.FACE_NOT_RECOGNIZED,
            message: 'Face not recognized. Please try again.',
        });

        assert.deepEqual(resolved, {
            type: 'face_error',
            message: "Hmm, we couldn't tell it was you",
            errorCode: ATTENDANCE_ERROR_CODES.FACE_NOT_RECOGNIZED,
        });
    });

    it('does not treat other 422 codes as a face error', () => {
        const resolved = resolveCheckInError(422, {
            error_code: ATTENDANCE_ERROR_CODES.NO_ACTIVE_SLOT,
            message: 'No active slot',
        });

        assert.equal(resolved.type, 'inline_error');
        assert.equal(resolved.message, ATTENDANCE_ERROR_MESSAGES[ATTENDANCE_ERROR_CODES.NO_ACTIVE_SLOT]);
        assert.equal(resolved.errorCode, ATTENDANCE_ERROR_CODES.NO_ACTIVE_SLOT);
    });

    it('distinguishes the two 503 codes by error_code', () => {
        const network = resolveCheckInError(503, {
            error_code: ATTENDANCE_ERROR_CODES.NETWORK_NOT_CONFIGURED,
        });
        const face = resolveCheckInError(503, {
            error_code: ATTENDANCE_ERROR_CODES.FACE_SERVICE_UNAVAILABLE,
        });

        assert.equal(network.type, 'inline_error');
        assert.equal(face.type, 'inline_error');
        assert.equal(network.message, ATTENDANCE_ERROR_MESSAGES[ATTENDANCE_ERROR_CODES.NETWORK_NOT_CONFIGURED]);
        assert.equal(face.message, ATTENDANCE_ERROR_MESSAGES[ATTENDANCE_ERROR_CODES.FACE_SERVICE_UNAVAILABLE]);
        assert.notEqual(network.message, face.message);
    });

    it('uses the shared copy for ALREADY_CHECKED_IN and NOT_ON_SCHOOL_NETWORK', () => {
        assert.equal(
            resolveCheckInError(409, { error_code: ATTENDANCE_ERROR_CODES.ALREADY_CHECKED_IN }).message,
            ATTENDANCE_ERROR_MESSAGES[ATTENDANCE_ERROR_CODES.ALREADY_CHECKED_IN],
        );
        assert.equal(
            resolveCheckInError(403, { error_code: ATTENDANCE_ERROR_CODES.NOT_ON_SCHOOL_NETWORK }).message,
            ATTENDANCE_ERROR_MESSAGES[ATTENDANCE_ERROR_CODES.NOT_ON_SCHOOL_NETWORK],
        );
    });

    it('falls back to data.message then the generic copy for unknown codes', () => {
        assert.equal(resolveCheckInError(500, { error_code: 'UNKNOWN', message: 'Server said this' }).message, 'Server said this');
        assert.equal(resolveCheckInError(500, {}).message, ATTENDANCE_ERROR_MESSAGES.fallback);
        assert.equal(resolveCheckInError(422, null).message, ATTENDANCE_ERROR_MESSAGES.fallback);
    });
});
