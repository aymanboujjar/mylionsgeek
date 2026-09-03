<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    $this->withoutVite();
});

function n3User(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => ['student'],
        'status' => 'Studying',
        'email_verified_at' => now(),
        'access_cowork' => 1,
        'access_studio' => 0,
    ], $overrides));
}

function n3Payload(array $overrides = []): array
{
    return array_merge([
        'table' => 7,
        'seats' => 1,
        'day' => '2026-09-10',
        'start' => '10:00',
        'end' => '12:00',
    ], $overrides);
}

function n3WebReserve(User $user, array $payload)
{
    return test()
        ->from('/students/spaces')
        ->actingAs($user)
        ->post('/admin/reservations/storeReservationCowork', $payload);
}

function n3SeedCowork(array $overrides = []): int
{
    return (int) DB::table('reservation_coworks')->insertGetId(array_merge([
        'table' => 7,
        'seats' => 1,
        'day' => '2026-09-10',
        'start' => '10:00',
        'end' => '12:00',
        'canceled' => 0,
        'passed' => 0,
        'approved' => 1,
        'user_id' => n3User()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

test('web overlapping 10:00-11:00 vs 10:30-11:30 is rejected', function () {
    $owner = n3User();
    n3SeedCowork([
        'user_id' => $owner->id,
        'start' => '10:00',
        'end' => '11:00',
    ]);

    n3WebReserve(n3User(), n3Payload([
        'start' => '10:30',
        'end' => '11:30',
    ]))->assertSessionHasErrors('start');

    expect(DB::table('reservation_coworks')->count())->toBe(1)
        ->and((int) DB::table('reservation_coworks')->value('user_id'))->toBe($owner->id);
});

test('web overlapping 10:00-12:00 vs contained 10:30-11:00 is rejected', function () {
    $owner = n3User();
    n3SeedCowork([
        'user_id' => $owner->id,
        'start' => '10:00',
        'end' => '12:00',
    ]);

    n3WebReserve(n3User(), n3Payload([
        'start' => '10:30',
        'end' => '11:00',
    ]))->assertSessionHasErrors('start');

    expect(DB::table('reservation_coworks')->count())->toBe(1);
});

test('web overlapping 10:30-11:00 vs wrapping 10:00-12:00 is rejected', function () {
    $owner = n3User();
    n3SeedCowork([
        'user_id' => $owner->id,
        'start' => '10:30',
        'end' => '11:00',
    ]);

    n3WebReserve(n3User(), n3Payload([
        'start' => '10:00',
        'end' => '12:00',
    ]))->assertSessionHasErrors('start');

    expect(DB::table('reservation_coworks')->count())->toBe(1);
});

test('web identical interval is rejected', function () {
    $owner = n3User();
    n3SeedCowork([
        'user_id' => $owner->id,
        'start' => '10:00',
        'end' => '12:00',
    ]);

    n3WebReserve(n3User(), n3Payload([
        'start' => '10:00',
        'end' => '12:00',
        'user_id' => 999999,
    ]))->assertSessionHasErrors('start');

    expect(DB::table('reservation_coworks')->count())->toBe(1)
        ->and((int) DB::table('reservation_coworks')->value('user_id'))->toBe($owner->id);
});

test('web adjacent 10:00-11:00 then 11:00-12:00 is allowed', function () {
    n3SeedCowork([
        'start' => '10:00',
        'end' => '11:00',
    ]);

    n3WebReserve(n3User(), n3Payload([
        'start' => '11:00',
        'end' => '12:00',
    ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(DB::table('reservation_coworks')->count())->toBe(2);
});

test('web overlapping slot on a different table is allowed', function () {
    n3SeedCowork([
        'table' => 7,
        'start' => '10:00',
        'end' => '12:00',
    ]);

    n3WebReserve(n3User(), n3Payload([
        'table' => 8,
        'start' => '10:30',
        'end' => '11:30',
    ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(DB::table('reservation_coworks')->count())->toBe(2)
        ->and(DB::table('reservation_coworks')->where('table', 8)->exists())->toBeTrue();
});

test('web canceled reservation does not block a new booking', function () {
    n3SeedCowork([
        'start' => '10:00',
        'end' => '12:00',
        'canceled' => 1,
        'approved' => 0,
    ]);

    n3WebReserve(n3User(), n3Payload([
        'start' => '10:30',
        'end' => '11:30',
    ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(DB::table('reservation_coworks')->count())->toBe(2)
        ->and(DB::table('reservation_coworks')->where('canceled', 0)->count())->toBe(1);
});

test('web overlap rejection does not overwrite or cancel the original reservation', function () {
    $owner = n3User();
    $originalId = n3SeedCowork([
        'user_id' => $owner->id,
        'start' => '10:00',
        'end' => '12:00',
        'canceled' => 0,
        'approved' => 1,
    ]);

    n3WebReserve(n3User(), n3Payload([
        'start' => '10:30',
        'end' => '11:30',
    ]))->assertSessionHasErrors('start');

    $row = DB::table('reservation_coworks')->find($originalId);

    expect(DB::table('reservation_coworks')->count())->toBe(1)
        ->and((int) $row->id)->toBe($originalId)
        ->and((int) $row->user_id)->toBe($owner->id)
        ->and((int) $row->canceled)->toBe(0)
        ->and((int) $row->approved)->toBe(1)
        ->and($row->start)->toBe('10:00')
        ->and($row->end)->toBe('12:00');
});

test('web cowork create uses Auth id and ignores client user_id', function () {
    $caller = n3User();
    $other = n3User();

    n3WebReserve($caller, n3Payload([
        'user_id' => $other->id,
        'start' => '10:00',
        'end' => '11:00',
    ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(DB::table('reservation_coworks')->count())->toBe(1)
        ->and((int) DB::table('reservation_coworks')->value('user_id'))->toBe($caller->id)
        ->and(DB::table('reservation_coworks')->where('user_id', $other->id)->exists())->toBeFalse();
});
