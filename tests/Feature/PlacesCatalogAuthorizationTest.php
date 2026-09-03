<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function n8User(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => ['student'],
        'status' => 'Studying',
        'email_verified_at' => now(),
        'account_state' => 0,
    ], $overrides));
}

function n8Places(User $user)
{
    Auth::forgetGuards();

    return test()
        ->actingAs($user, 'sanctum')
        ->getJson('/api/places');
}

function n8Studio(string $name, int $state = 1): int
{
    return (int) DB::table('studios')->insertGetId([
        'name' => $name,
        'state' => $state,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function n8Cowork(int $table, int $state = 1): int
{
    return (int) DB::table('coworks')->insertGetId([
        'table' => $table,
        'state' => $state,
        'image' => 'storage/img/cowork/cowork.jpg',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function n8MeetingRoom(string $name, int $state = 1): int
{
    return (int) DB::table('meeting_rooms')->insertGetId([
        'name' => $name,
        'state' => $state,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('guest cannot read the places catalog', function () {
    n8Studio('N8 Secret Studio');
    n8Cowork(3);
    n8MeetingRoom('N8 Secret Room');

    $response = $this->getJson('/api/places')->assertUnauthorized();

    $encoded = json_encode($response->json());
    expect($encoded)->not->toContain('N8 Secret Studio')
        ->and($encoded)->not->toContain('Table 3')
        ->and($encoded)->not->toContain('N8 Secret Room')
        ->and($response->json('studios'))->toBeNull()
        ->and($response->json('coworks'))->toBeNull()
        ->and($response->json('meeting_rooms'))->toBeNull();
});

test('authenticated user receives the places catalog shape', function () {
    $user = n8User();
    $studioId = n8Studio('N8 Open Studio');
    $coworkId = n8Cowork(4);
    $roomId = n8MeetingRoom('N8 Open Room');

    $response = n8Places($user)->assertOk();

    $response->assertJsonStructure([
        'studios' => [['id', 'name', 'image']],
        'coworks' => [['id', 'name', 'image']],
        'meeting_rooms' => [['id', 'name', 'image']],
    ]);

    $studios = collect($response->json('studios'));
    $coworks = collect($response->json('coworks'));
    $rooms = collect($response->json('meeting_rooms'));

    expect($studios->firstWhere('id', $studioId)['name'])->toBe('N8 Open Studio')
        ->and($coworks->firstWhere('id', $coworkId)['name'])->toBe('Table 4')
        ->and($rooms->firstWhere('id', $roomId)['name'])->toBe('N8 Open Room');
});

test('places catalog omits disabled spaces', function () {
    $user = n8User();
    $enabledStudio = n8Studio('N8 Enabled Studio', 1);
    $disabledStudio = n8Studio('N8 Disabled Studio', 0);
    $enabledCowork = n8Cowork(8, 1);
    $disabledCowork = n8Cowork(9, 0);
    $enabledRoom = n8MeetingRoom('N8 Enabled Room', 1);
    $disabledRoom = n8MeetingRoom('N8 Disabled Room', 0);

    $response = n8Places($user)->assertOk();
    $encoded = json_encode($response->json());

    $studioIds = collect($response->json('studios'))->pluck('id');
    $coworkIds = collect($response->json('coworks'))->pluck('id');
    $roomIds = collect($response->json('meeting_rooms'))->pluck('id');

    expect($studioIds)->toContain($enabledStudio)
        ->and($studioIds)->not->toContain($disabledStudio)
        ->and($coworkIds)->toContain($enabledCowork)
        ->and($coworkIds)->not->toContain($disabledCowork)
        ->and($roomIds)->toContain($enabledRoom)
        ->and($roomIds)->not->toContain($disabledRoom)
        ->and($encoded)->not->toContain('N8 Disabled Studio')
        ->and($encoded)->not->toContain('Table 9')
        ->and($encoded)->not->toContain('N8 Disabled Room');
});

test('public occupancy endpoint remains public and strips reservation identity', function () {
    $owner = n8User([
        'name' => 'N8OccupancyOwner',
        'email' => 'n8.occupancy.owner@example.com',
        'phone' => '0611223344',
    ]);
    $studioId = n8Studio('N8 Occupancy Studio');

    DB::table('reservations')->insert([
        'title' => 'N8 Private Booking Title',
        'description' => 'Private studio booking',
        'day' => '2026-09-10',
        'start' => '10:00',
        'end' => '12:00',
        'canceled' => 0,
        'passed' => 0,
        'approved' => 0,
        'user_id' => $owner->id,
        'studio_id' => $studioId,
        'start_signed' => 0,
        'end_signed' => 0,
        'type' => 'studio',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->getJson('/reservations/public-place/studio/'.$studioId)
        ->assertOk();

    $payload = $response->json();
    expect($payload)->not->toBeEmpty();

    $encoded = json_encode($payload);
    expect($encoded)->not->toContain($owner->name)
        ->and($encoded)->not->toContain($owner->email)
        ->and($encoded)->not->toContain($owner->phone)
        ->and($encoded)->not->toContain('N8 Private Booking Title')
        ->and($encoded)->not->toContain('user_id')
        ->and($encoded)->not->toContain('user_name');

    foreach ($payload as $slot) {
        expect($slot)->toHaveKeys(['start', 'end'])
            ->and($slot)->not->toHaveKeys(['id', 'user_id', 'user_name', 'email', 'phone', 'title']);
    }
});
