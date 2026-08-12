/** Student-facing labels — identifiers (morning/lunch/evening) are internal only. */
export const SLOT_LABELS = {
    morning: 'Morning',
    lunch: 'Coffee break',
    evening: 'Lunch',
};

/**
 * Slot windows in minutes-from-midnight — keep in sync with config/attendance.php.
 */
export const SLOT_WINDOWS = {
    morning: { opens: 9 * 60 + 30, closes: 11 * 60 },
    lunch: { opens: 11 * 60 + 30, closes: 13 * 60 },
    evening: { opens: 14 * 60, closes: 17 * 60 },
};

export const SLOT_ORDER = ['morning', 'lunch', 'evening'];

export function slotLabel(slot) {
    return SLOT_LABELS[slot] ?? slot;
}

/**
 * Default value for a slot with no DB data on the selected calendar date.
 * Always pending — absent is only shown after finalize/check-in/coach actually writes it.
 */
export function defaultUnresolvedSlotValue() {
    return 'pending';
}

/**
 * Present vs late sub-phase within an active slot (derived from server minutes_into_slot).
 */
export function attendanceSubPhase(slotStatus) {
    if (!slotStatus || slotStatus.phase !== 'active' || !slotStatus.current_slot) {
        return null;
    }

    if (slotStatus.minutes_into_slot === null) {
        return null;
    }

    const presentWindow = slotStatus.present_minutes ?? 15;

    return slotStatus.minutes_into_slot < presentWindow ? 'present' : 'late';
}

/**
 * Per-slot-per-phase dismiss key: `{slot}-{phase}-{date}`.
 */
export function reminderDismissKey(slotStatus) {
    const subPhase = attendanceSubPhase(slotStatus);

    if (!slotStatus?.attendance_day || !slotStatus?.current_slot || !subPhase) {
        return null;
    }

    return `${slotStatus.current_slot}-${subPhase}-${slotStatus.attendance_day}`;
}

/**
 * Safe read of already_marked_slots — missing/partial payloads default to [].
 *
 * @param {{ already_marked_slots?: string[] } | null | undefined} slotStatus
 * @returns {string[]}
 */
export function alreadyMarkedSlots(slotStatus) {
    const slots = slotStatus?.already_marked_slots;

    return Array.isArray(slots) ? slots : [];
}

/**
 * Home feed banner: show for the entire active slot (present and late).
 * Relies on already_marked_slots (pending/null are unmarked).
 */
export function shouldShowHomeReminderBanner(slotStatus) {
    if (!slotStatus) {
        return false;
    }

    const { current_slot, phase } = slotStatus;

    if (phase !== 'active' || !current_slot) {
        return false;
    }

    return !alreadyMarkedSlots(slotStatus).includes(current_slot);
}

/**
 * Attendance page inline reminder: first present window only.
 */
export function shouldShowReminderBanner(slotStatus) {
    if (!shouldShowHomeReminderBanner(slotStatus)) {
        return false;
    }

    const { minutes_into_slot, present_minutes } = slotStatus;
    const presentWindow = present_minutes ?? 15;

    return minutes_into_slot !== null && minutes_into_slot < presentWindow;
}

export function homeReminderBannerText(slotStatus) {
    const label = slotLabel(slotStatus?.current_slot);
    const subPhase = attendanceSubPhase(slotStatus);

    if (subPhase === 'late') {
        return `You're late for ${label} — click to mark before the window closes.`;
    }

    return `${label} attendance is open — click to mark your presence.`;
}
