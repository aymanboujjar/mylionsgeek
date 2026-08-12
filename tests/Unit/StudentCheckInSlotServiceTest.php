<?php

use App\Services\AttendanceSlotService;
use App\Services\StudentCheckInSlotService;

test('student marked slots are inferred from check-in note time', function () {
    $service = new StudentCheckInSlotService(new AttendanceSlotService);

    $marked = $service->studentMarkedSlots(
        '2025-06-22',
        'Check-in at 09:42',
        ['morning' => 'present', 'lunch' => 'absent', 'evening' => 'absent'],
    );

    expect($marked)->toBe(['morning']);
});

test('student marked slots include both check-ins when multiple notes exist', function () {
    $service = new StudentCheckInSlotService(new AttendanceSlotService);

    $marked = $service->studentMarkedSlots(
        '2025-06-22',
        'Check-in at 09:42 | Check-in at 11:35',
        ['morning' => 'present', 'lunch' => 'late', 'evening' => 'absent'],
    );

    expect($marked)->toBe(['morning', 'lunch']);
});

test('past absent and future pending slots are not student marked when only morning was checked in', function () {
    $service = new StudentCheckInSlotService(new AttendanceSlotService);

    $marked = $service->studentMarkedSlots(
        '2025-06-22',
        'Check-in at 09:42',
        ['morning' => 'present', 'lunch' => 'absent', 'evening' => 'absent'],
    );

    expect($marked)->not->toContain('lunch');
    expect($marked)->not->toContain('evening');
});

test('legacy rows without check-in notes are never student marked', function () {
    $service = new StudentCheckInSlotService(new AttendanceSlotService);

    $marked = $service->studentMarkedSlots(
        '2025-06-22',
        null,
        ['morning' => 'present', 'lunch' => 'present', 'evening' => 'present'],
    );

    expect($marked)->toBe([]);
});

test('coach-set present or late slots are not student marked when only another slot was checked in', function () {
    $service = new StudentCheckInSlotService(new AttendanceSlotService);

    $marked = $service->studentMarkedSlots(
        '2025-06-22',
        'Check-in at 09:42',
        ['morning' => 'present', 'lunch' => 'absent', 'evening' => 'late'],
    );

    expect($marked)->toBe(['morning']);
    expect($marked)->not->toContain('evening');
});

test('check-in note time in a gap does not mark any slot', function () {
    $service = new StudentCheckInSlotService(new AttendanceSlotService);

    $marked = $service->studentMarkedSlots(
        '2025-06-22',
        'Check-in at 11:05',
        ['morning' => 'late', 'lunch' => 'absent', 'evening' => 'absent'],
    );

    expect($marked)->toBe([]);
});
