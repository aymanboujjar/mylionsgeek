import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    buildButtonLabel,
    buildHelperText,
    isCheckInDisabled,
} from './attendance-check-in-ui.js';
import {
    alreadyMarkedSlots,
    shouldShowHomeReminderBanner,
} from './attendance-slots.js';

const activeMorning = {
    current_slot: 'morning',
    phase: 'active',
    minutes_into_slot: 5,
    present_minutes: 15,
};

describe('alreadyMarkedSlots / shouldShowHomeReminderBanner', () => {
    it('defaults missing already_marked_slots to [] and does not throw', () => {
        assert.deepEqual(alreadyMarkedSlots({ ...activeMorning }), []);
        assert.deepEqual(alreadyMarkedSlots({ ...activeMorning, already_marked_slots: undefined }), []);
        assert.deepEqual(alreadyMarkedSlots({ ...activeMorning, already_marked_slots: null }), []);
        assert.equal(shouldShowHomeReminderBanner({ ...activeMorning }), true);
    });

    it('returns false when current slot is already marked', () => {
        assert.equal(
            shouldShowHomeReminderBanner({
                ...activeMorning,
                already_marked_slots: ['morning'],
            }),
            false,
        );
    });

    it('returns true when already_marked_slots is populated without current slot', () => {
        assert.equal(
            shouldShowHomeReminderBanner({
                ...activeMorning,
                already_marked_slots: ['lunch'],
            }),
            true,
        );
    });
});

describe('AttendanceCheckInPanel helpers without already_marked_slots', () => {
    it('buildButtonLabel does not throw and treats slot as unmarked', () => {
        assert.equal(buildButtonLabel({ ...activeMorning }), 'Check in — Morning');
    });

    it('buildHelperText does not throw and treats slot as unmarked', () => {
        assert.match(buildHelperText({ ...activeMorning }), /Mark within/);
    });

    it('isCheckInDisabled does not throw and allows check-in when unmarked', () => {
        assert.equal(isCheckInDisabled({ ...activeMorning }, false), false);
    });

    it('still treats populated already_marked_slots as marked', () => {
        const marked = { ...activeMorning, already_marked_slots: ['morning'] };
        assert.equal(buildButtonLabel(marked), 'Morning ✓');
        assert.equal(isCheckInDisabled(marked, false), true);
    });
});
