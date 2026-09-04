<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function studioConflictUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => ['student'],
        'status' => 'Studying',
        'email_verified_at' => now(),
        'access_cowork' => 0,
        'access_studio' => 0,
    ], $overrides));
}

function studioConflictSeedStudio(): int
{
    return (int) DB::table('studios')->insertGetId([
        'name' => 'Studio A',
        'state' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function studioConflictPayload(int $studioId, array $overrides = []): array
{
    return array_merge([
        'studio_id' => $studioId,
        'title' => 'Shoot',
        'description' => 'Test',
        'day' => '2026-09-10',
        'start' => '10:00',
        'end' => '12:00',
    ], $overrides);
}

function studioConflictReserve(User $user, array $payload)
{
    Auth::forgetGuards();

    return test()
        ->actingAs($user, 'sanctum')
        ->postJson('/api/reservations/store', $payload);
}

test('overlapping studio reservation is rejected and only one row exists', function () {
    $studioId = studioConflictSeedStudio();
    $userA = studioConflictUser(['access_studio' => 1]);
    $userB = studioConflictUser(['access_studio' => 1]);

    studioConflictReserve($userA, studioConflictPayload($studioId))
        ->assertOk()
        ->assertJsonPath('success', true);

    studioConflictReserve($userB, studioConflictPayload($studioId, [
        'start' => '11:00',
        'end' => '13:00',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('start');

    expect(DB::table('reservations')->where('studio_id', $studioId)->count())->toBe(1)
        ->and((int) DB::table('reservations')->value('user_id'))->toBe($userA->id);
});

test('adjacent non-overlapping studio slot succeeds', function () {
    $studioId = studioConflictSeedStudio();
    $user = studioConflictUser(['access_studio' => 1]);

    studioConflictReserve($user, studioConflictPayload($studioId, [
        'start' => '10:00',
        'end' => '12:00',
    ]))->assertOk();

    studioConflictReserve($user, studioConflictPayload($studioId, [
        'title' => 'Second',
        'start' => '12:00',
        'end' => '14:00',
    ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(DB::table('reservations')->where('studio_id', $studioId)->count())->toBe(2);
});

test('canceled studio reservation does not block a new booking', function () {
    $studioId = studioConflictSeedStudio();
    $user = studioConflictUser(['access_studio' => 1]);

    studioConflictReserve($user, studioConflictPayload($studioId))->assertOk();
    DB::table('reservations')->where('studio_id', $studioId)->update(['canceled' => 1]);

    studioConflictReserve($user, studioConflictPayload($studioId, [
        'title' => 'After cancel',
    ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(DB::table('reservations')->where('studio_id', $studioId)->where('canceled', 0)->count())->toBe(1);
});
