<?php

use App\Services\AttendanceSlotService;
use Carbon\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2025-06-22 09:00:00', 'Africa/Casablanca'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function slotService(): AttendanceSlotService
{
    return new AttendanceSlotService;
}

function atTime(string $time): Carbon
{
    return Carbon::parse("2025-06-22 {$time}", 'Africa/Casablanca');
}

test('11:00 justClosedSlot is morning', function () {
    expect(slotService()->justClosedSlot(atTime('11:00:00')))->toBe('morning');
    expect(slotService()->justClosedSlot(atTime('11:00:59')))->toBe('morning');
});

test('13:00 justClosedSlot is lunch', function () {
    expect(slotService()->justClosedSlot(atTime('13:00:00')))->toBe('lunch');
});

test('17:00 justClosedSlot is evening', function () {
    expect(slotService()->justClosedSlot(atTime('17:00:00')))->toBe('evening');
});

test('11:05 is not a just-closed minute', function () {
    expect(slotService()->justClosedSlot(atTime('11:05:00')))->toBeNull();
});

test('latestClosedSlot after morning close is morning', function () {
    expect(slotService()->latestClosedSlot(atTime('11:05:00')))->toBe('morning');
});

test('latestClosedSlot after lunch close is lunch', function () {
    expect(slotService()->latestClosedSlot(atTime('13:00:00')))->toBe('lunch');
    expect(slotService()->latestClosedSlot(atTime('13:30:00')))->toBe('lunch');
});

test('latestClosedSlot after evening close is evening', function () {
    expect(slotService()->latestClosedSlot(atTime('17:00:00')))->toBe('evening');
    expect(slotService()->latestClosedSlot(atTime('17:05:00')))->toBe('evening');
});

test('latestClosedSlot before any close is null', function () {
    expect(slotService()->latestClosedSlot(atTime('09:00:00')))->toBeNull();
    expect(slotService()->latestClosedSlot(atTime('10:59:00')))->toBeNull();
});

test('latestClosedSlot with allClosed returns evening', function () {
    expect(slotService()->latestClosedSlot(atTime('09:00:00'), true))->toBe('evening');
});

test('09:42 is morning present', function () {
    $now = atTime('09:42:00');
    expect(slotService()->currentSlot($now))->toBe('morning');
    expect(slotService()->gradeStatus($now, 'morning'))->toBe('present');
});

test('09:46 is morning late', function () {
    $now = atTime('09:46:00');
    expect(slotService()->currentSlot($now))->toBe('morning');
    expect(slotService()->gradeStatus($now, 'morning'))->toBe('late');
});

test('11:05 is a gap with no active slot', function () {
    $now = atTime('11:05:00');
    expect(slotService()->currentSlot($now))->toBeNull();
    expect(slotService()->phase($now))->toBe('gap');
});

test('08:00 is outside school hours', function () {
    $now = atTime('08:00:00');
    expect(slotService()->currentSlot($now))->toBeNull();
    expect(slotService()->phase($now))->toBe('closed');
});

test('16:59 is evening late', function () {
    $now = atTime('16:59:00');
    expect(slotService()->currentSlot($now))->toBe('evening');
    expect(slotService()->gradeStatus($now, 'evening'))->toBe('late');
});

test('17:30 is outside school hours', function () {
    $now = atTime('17:30:00');
    expect(slotService()->currentSlot($now))->toBeNull();
    expect(slotService()->phase($now))->toBe('closed');
});

test('11:35 is lunch present', function () {
    $now = atTime('11:35:00');
    expect(slotService()->currentSlot($now))->toBe('lunch');
    expect(slotService()->gradeStatus($now, 'lunch'))->toBe('present');
});

test('12:59 is still lunch', function () {
    $now = atTime('12:59:59');
    expect(slotService()->currentSlot($now))->toBe('lunch');
});

test('13:00 is a gap between lunch and evening', function () {
    $now = atTime('13:00:00');
    expect(slotService()->currentSlot($now))->toBeNull();
    expect(slotService()->phase($now))->toBe('gap');
});

test('13:30 is a gap before evening opens', function () {
    $now = atTime('13:30:00');
    expect(slotService()->currentSlot($now))->toBeNull();
    expect(slotService()->phase($now))->toBe('gap');
});

test('09:44:59 is present boundary', function () {
    $now = atTime('09:44:59');
    expect(slotService()->gradeStatus($now, 'morning'))->toBe('present');
});

test('09:45:00 is late boundary', function () {
    $now = atTime('09:45:00');
    expect(slotService()->gradeStatus($now, 'morning'))->toBe('late');
});

test('buildCheckInSlots preserves earlier marks and defaults future to pending', function () {
    $slots = slotService()->buildCheckInSlots(
        ['morning' => 'present', 'lunch' => null, 'evening' => null],
        'lunch',
        'late',
    );

    expect($slots)->toBe([
        'morning' => 'present',
        'lunch' => 'late',
        'evening' => 'pending',
    ]);
});

test('buildCheckInSlots marks past unscanned slots absent and future pending', function () {
    $slots = slotService()->buildCheckInSlots(
        null,
        'evening',
        'present',
    );

    expect($slots)->toBe([
        'morning' => 'absent',
        'lunch' => 'absent',
        'evening' => 'present',
    ]);
});

test('isSlotMarked treats pending as unmarked and absent as marked', function () {
    expect(slotService()->isSlotMarked(null))->toBeFalse();
    expect(slotService()->isSlotMarked(''))->toBeFalse();
    expect(slotService()->isSlotMarked('pending'))->toBeFalse();
    expect(slotService()->isSlotMarked('Pending'))->toBeFalse();
    expect(slotService()->isSlotMarked('absent'))->toBeTrue();
    expect(slotService()->isSlotMarked('Absent'))->toBeTrue();
    expect(slotService()->isSlotMarked('present'))->toBeTrue();
    expect(slotService()->isSlotMarked('late'))->toBeTrue();
    expect(slotService()->isSlotMarked('excused'))->toBeTrue();
});

test('markedSlots includes absent and excludes pending', function () {
    $marked = slotService()->markedSlots([
        'morning' => 'absent',
        'lunch' => 'present',
        'evening' => 'pending',
    ]);

    expect($marked)->toBe(['morning', 'lunch']);
});

test('buildCheckInSlots does not overwrite a coach absent on later check-in of another slot', function () {
    $slots = slotService()->buildCheckInSlots(
        ['morning' => 'absent', 'lunch' => 'present', 'evening' => 'pending'],
        'evening',
        'late',
    );

    expect($slots)->toBe([
        'morning' => 'absent',
        'lunch' => 'present',
        'evening' => 'late',
    ]);
});

test('buildCheckInSlots overwrites pending on later check-in', function () {
    $slots = slotService()->buildCheckInSlots(
        ['morning' => 'present', 'lunch' => 'pending', 'evening' => 'pending'],
        'lunch',
        'late',
    );

    expect($slots)->toBe([
        'morning' => 'present',
        'lunch' => 'late',
        'evening' => 'pending',
    ]);
});

test('buildFinalizeSlots writes absent for closed and past, pending for future', function () {
    expect(slotService()->buildFinalizeSlots('morning', null))->toBe([
        'morning' => 'absent',
        'lunch' => 'pending',
        'evening' => 'pending',
    ]);

    expect(slotService()->buildFinalizeSlots('lunch', [
        'morning' => 'present',
        'lunch' => 'pending',
        'evening' => null,
    ]))->toBe([
        'morning' => 'present',
        'lunch' => 'absent',
        'evening' => 'pending',
    ]);
});

test('buildFinalizeSlots skips already resolved closed slot', function () {
    expect(slotService()->buildFinalizeSlots('morning', [
        'morning' => 'late',
        'lunch' => 'pending',
        'evening' => 'pending',
    ]))->toBe([
        'morning' => 'late',
        'lunch' => 'pending',
        'evening' => 'pending',
    ]);
});
