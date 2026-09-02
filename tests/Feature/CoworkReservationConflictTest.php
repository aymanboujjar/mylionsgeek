<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function m6User(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => ['student'],
        'status' => 'Studying',
        'email_verified_at' => now(),
        'access_cowork' => 0,
        'access_studio' => 0,
    ], $overrides));
}

function m6Payload(array $overrides = []): array
{
    return array_merge([
        'table' => 7,
        'seats' => 1,
        'day' => '2026-09-10',
        'start' => '10:00',
        'end' => '12:00',
    ], $overrides);
}

function m6Reserve(User $user, array $payload)
{
    Auth::forgetGuards();

    return test()
        ->actingAs($user, 'sanctum')
        ->postJson('/api/cowork/reserve', $payload);
}

test('anonymous cannot reserve a cowork table', function () {
    $this->postJson('/api/cowork/reserve', m6Payload())
        ->assertUnauthorized();

    expect(DB::table('reservation_coworks')->count())->toBe(0);
});

test('authenticated user without access_cowork cannot reserve', function () {
    $user = m6User(['access_cowork' => 0]);

    m6Reserve($user, m6Payload())->assertForbidden();

    expect(DB::table('reservation_coworks')->count())->toBe(0);
});

test('first reservation succeeds as approved for the caller even if user_id is forged', function () {
    $caller = m6User(['access_cowork' => 1]);
    $other = m6User(['access_cowork' => 1]);

    $response = m6Reserve($caller, m6Payload([
        'user_id' => $other->id,
    ]));

    $response
        ->assertCreated()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('message', 'Cowork reservation created and approved automatically')
        ->assertJsonPath('reservation.user_id', $caller->id)
        ->assertJsonPath('reservation.approved', true)
        ->assertJsonPath('reservation.table', 7);

    expect(DB::table('reservation_coworks')->count())->toBe(1)
        ->and((int) DB::table('reservation_coworks')->value('user_id'))->toBe($caller->id)
        ->and((int) DB::table('reservation_coworks')->value('approved'))->toBe(1)
        ->and(DB::table('reservation_coworks')->where('user_id', $other->id)->exists())->toBeFalse();
});

test('overlapping reservation on the same table is rejected and only one row exists', function () {
    $userA = m6User(['access_cowork' => 1]);
    $userB = m6User(['access_cowork' => 1]);

    m6Reserve($userA, m6Payload())->assertCreated();

    m6Reserve($userB, m6Payload([
        'start' => '11:00',
        'end' => '13:00',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('start');

    expect(DB::table('reservation_coworks')->count())->toBe(1)
        ->and((int) DB::table('reservation_coworks')->value('user_id'))->toBe($userA->id);
});

test('same user overlapping themselves is rejected', function () {
    $user = m6User(['access_cowork' => 1]);

    m6Reserve($user, m6Payload())->assertCreated();

    m6Reserve($user, m6Payload([
        'start' => '11:00',
        'end' => '13:00',
    ]))->assertUnprocessable();

    expect(DB::table('reservation_coworks')->where('user_id', $user->id)->count())->toBe(1);
});

test('adjacent non-overlapping slot on the same table succeeds', function () {
    $user = m6User(['access_cowork' => 1]);

    m6Reserve($user, m6Payload([
        'start' => '10:00',
        'end' => '12:00',
    ]))->assertCreated();

    m6Reserve($user, m6Payload([
        'start' => '12:00',
        'end' => '14:00',
    ]))
        ->assertCreated()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('reservation.approved', true)
        ->assertJsonPath('reservation.user_id', $user->id);

    expect(DB::table('reservation_coworks')->count())->toBe(2);
});

test('overlapping slot on a different table succeeds', function () {
    $user = m6User(['access_cowork' => 1]);

    m6Reserve($user, m6Payload([
        'table' => 7,
        'start' => '10:00',
        'end' => '12:00',
    ]))->assertCreated();

    m6Reserve($user, m6Payload([
        'table' => 8,
        'start' => '10:00',
        'end' => '12:00',
    ]))
        ->assertCreated()
        ->assertJsonPath('reservation.table', 8)
        ->assertJsonPath('reservation.approved', true)
        ->assertJsonPath('reservation.user_id', $user->id);

    expect(DB::table('reservation_coworks')->count())->toBe(2);
});

test('overlapping canceled reservation does not block a new booking', function () {
    $previous = m6User(['access_cowork' => 1]);
    $caller = m6User(['access_cowork' => 1]);

    DB::table('reservation_coworks')->insert([
        'table' => 7,
        'seats' => 1,
        'day' => '2026-09-10',
        'start' => '10:00',
        'end' => '12:00',
        'canceled' => 1,
        'passed' => 0,
        'approved' => 0,
        'user_id' => $previous->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    m6Reserve($caller, m6Payload())
        ->assertCreated()
        ->assertJsonPath('reservation.approved', true)
        ->assertJsonPath('reservation.user_id', $caller->id);

    expect(DB::table('reservation_coworks')->count())->toBe(2)
        ->and(DB::table('reservation_coworks')->where('canceled', 0)->count())->toBe(1);
});
