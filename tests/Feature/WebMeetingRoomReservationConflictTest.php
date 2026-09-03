<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    $this->withoutVite();
});

function n4User(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => ['student'],
        'status' => 'Studying',
        'email_verified_at' => now(),
        'access_cowork' => 0,
        'access_studio' => 0,
    ], $overrides));
}

function n4MeetingRoom(array $overrides = []): int
{
    return (int) DB::table('meeting_rooms')->insertGetId(array_merge([
        'name' => 'N4 Meeting Room',
        'state' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

function n4Payload(int $roomId, array $overrides = []): array
{
    return array_merge([
        'meeting_room_id' => $roomId,
        'day' => '2026-09-10',
        'start' => '10:00',
        'end' => '12:00',
    ], $overrides);
}

function n4WebReserve(User $user, array $payload)
{
    return test()
        ->from('/students/spaces')
        ->actingAs($user)
        ->post('/admin/reservations/storeReservationMeetingRoom', $payload);
}

function n4SeedReservation(int $roomId, array $overrides = []): int
{
    return (int) DB::table('reservation_meeting_rooms')->insertGetId(array_merge([
        'meeting_room_id' => $roomId,
        'day' => '2026-09-10',
        'start' => '10:00',
        'end' => '12:00',
        'canceled' => 0,
        'passed' => 0,
        'approved' => 1,
        'user_id' => n4User()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

test('web overlapping 10:00-11:00 vs 10:30-11:30 is rejected', function () {
    $roomId = n4MeetingRoom();
    $owner = n4User();
    n4SeedReservation($roomId, [
        'user_id' => $owner->id,
        'start' => '10:00',
        'end' => '11:00',
    ]);

    n4WebReserve(n4User(), n4Payload($roomId, [
        'start' => '10:30',
        'end' => '11:30',
    ]))->assertSessionHasErrors('start');

    expect(DB::table('reservation_meeting_rooms')->count())->toBe(1)
        ->and((int) DB::table('reservation_meeting_rooms')->value('user_id'))->toBe($owner->id);
});

test('web overlapping 10:00-12:00 vs contained 10:30-11:00 is rejected', function () {
    $roomId = n4MeetingRoom();
    n4SeedReservation($roomId, [
        'start' => '10:00',
        'end' => '12:00',
    ]);

    n4WebReserve(n4User(), n4Payload($roomId, [
        'start' => '10:30',
        'end' => '11:00',
    ]))->assertSessionHasErrors('start');

    expect(DB::table('reservation_meeting_rooms')->count())->toBe(1);
});

test('web overlapping 10:30-11:00 vs wrapping 10:00-12:00 is rejected', function () {
    $roomId = n4MeetingRoom();
    n4SeedReservation($roomId, [
        'start' => '10:30',
        'end' => '11:00',
    ]);

    n4WebReserve(n4User(), n4Payload($roomId, [
        'start' => '10:00',
        'end' => '12:00',
    ]))->assertSessionHasErrors('start');

    expect(DB::table('reservation_meeting_rooms')->count())->toBe(1);
});

test('web identical interval is rejected', function () {
    $roomId = n4MeetingRoom();
    $owner = n4User();
    n4SeedReservation($roomId, [
        'user_id' => $owner->id,
        'start' => '10:00',
        'end' => '12:00',
    ]);

    n4WebReserve(n4User(), n4Payload($roomId, [
        'start' => '10:00',
        'end' => '12:00',
        'user_id' => 999999,
    ]))->assertSessionHasErrors('start');

    expect(DB::table('reservation_meeting_rooms')->count())->toBe(1)
        ->and((int) DB::table('reservation_meeting_rooms')->value('user_id'))->toBe($owner->id);
});

test('web adjacent 10:00-11:00 then 11:00-12:00 is allowed', function () {
    $roomId = n4MeetingRoom();
    n4SeedReservation($roomId, [
        'start' => '10:00',
        'end' => '11:00',
    ]);

    n4WebReserve(n4User(), n4Payload($roomId, [
        'start' => '11:00',
        'end' => '12:00',
    ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(DB::table('reservation_meeting_rooms')->count())->toBe(2);
});

test('web overlapping slot on a different meeting room is allowed', function () {
    $roomA = n4MeetingRoom(['name' => 'N4 Room A']);
    $roomB = n4MeetingRoom(['name' => 'N4 Room B']);

    n4SeedReservation($roomA, [
        'start' => '10:00',
        'end' => '12:00',
    ]);

    n4WebReserve(n4User(), n4Payload($roomB, [
        'start' => '10:30',
        'end' => '11:30',
    ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(DB::table('reservation_meeting_rooms')->count())->toBe(2)
        ->and(DB::table('reservation_meeting_rooms')->where('meeting_room_id', $roomB)->exists())->toBeTrue();
});

test('web canceled reservation does not block a new booking', function () {
    $roomId = n4MeetingRoom();
    n4SeedReservation($roomId, [
        'start' => '10:00',
        'end' => '12:00',
        'canceled' => 1,
        'approved' => 0,
    ]);

    n4WebReserve(n4User(), n4Payload($roomId, [
        'start' => '10:30',
        'end' => '11:30',
    ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(DB::table('reservation_meeting_rooms')->count())->toBe(2)
        ->and(DB::table('reservation_meeting_rooms')->where('canceled', 0)->count())->toBe(1);
});

test('web overlap rejection does not overwrite or cancel the original reservation', function () {
    $roomId = n4MeetingRoom();
    $owner = n4User();
    $originalId = n4SeedReservation($roomId, [
        'user_id' => $owner->id,
        'start' => '10:00',
        'end' => '12:00',
        'canceled' => 0,
        'approved' => 1,
    ]);

    n4WebReserve(n4User(), n4Payload($roomId, [
        'start' => '10:30',
        'end' => '11:30',
    ]))->assertSessionHasErrors('start');

    $row = DB::table('reservation_meeting_rooms')->find($originalId);

    expect(DB::table('reservation_meeting_rooms')->count())->toBe(1)
        ->and((int) $row->id)->toBe($originalId)
        ->and((int) $row->user_id)->toBe($owner->id)
        ->and((int) $row->canceled)->toBe(0)
        ->and((int) $row->approved)->toBe(1)
        ->and($row->start)->toBe('10:00')
        ->and($row->end)->toBe('12:00');
});

test('web meeting room create uses Auth id and ignores client user_id', function () {
    $roomId = n4MeetingRoom();
    $caller = n4User();
    $other = n4User();

    n4WebReserve($caller, n4Payload($roomId, [
        'user_id' => $other->id,
        'start' => '10:00',
        'end' => '11:00',
    ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(DB::table('reservation_meeting_rooms')->count())->toBe(1)
        ->and((int) DB::table('reservation_meeting_rooms')->value('user_id'))->toBe($caller->id)
        ->and(DB::table('reservation_meeting_rooms')->where('user_id', $other->id)->exists())->toBeFalse();
});

test('web invalid time format is rejected', function () {
    $roomId = n4MeetingRoom();

    n4WebReserve(n4User(), n4Payload($roomId, [
        'start' => '10',
        'end' => 'noon',
    ]))->assertSessionHasErrors(['start', 'end']);

    expect(DB::table('reservation_meeting_rooms')->count())->toBe(0);
});

test('web start after end is rejected', function () {
    $roomId = n4MeetingRoom();

    n4WebReserve(n4User(), n4Payload($roomId, [
        'start' => '12:00',
        'end' => '10:00',
    ]))->assertSessionHasErrors('end');

    expect(DB::table('reservation_meeting_rooms')->count())->toBe(0);
});
