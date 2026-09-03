<?php

use App\Models\GameSession;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function n6User(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => ['student'],
        'status' => 'Studying',
        'email_verified_at' => now(),
    ], $overrides));
}

function n6CreateRoom(User $user, string $roomId, array $state = [])
{
    return test()
        ->actingAs($user)
        ->postJson('/api/games/state/'.$roomId, [
            'game_type' => 'tictactoe',
            'game_state' => array_merge([
                'board' => ['X', '', '', '', '', '', '', '', ''],
                'scores' => ['X' => 0],
            ], $state),
        ]);
}

function n6Participants(string $roomId): array
{
    $state = GameSession::query()->where('room_id', $roomId)->value('game_state');

    return array_map('intval', $state['participant_user_ids'] ?? []);
}

test('user A can create get update and reset their own room', function () {
    $userA = n6User(['name' => 'N6 Player A']);

    n6CreateRoom($userA, 'n6-own-room')
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(n6Participants('n6-own-room'))->toContain($userA->id);

    $this->actingAs($userA)
        ->getJson('/api/games/state/n6-own-room')
        ->assertOk()
        ->assertJsonPath('exists', true)
        ->assertJsonPath('game_state.board.0', 'X');

    $this->actingAs($userA)
        ->postJson('/api/games/state/n6-own-room', [
            'game_type' => 'tictactoe',
            'game_state' => [
                'board' => ['X', 'O', '', '', '', '', '', '', ''],
            ],
        ])
        ->assertOk();

    expect(GameSession::query()->where('room_id', 'n6-own-room')->value('game_state')['board'][1])->toBe('O');

    $this->actingAs($userA)
        ->postJson('/api/games/reset/n6-own-room', [
            'game_type' => 'tictactoe',
            'initial_state' => ['board' => array_fill(0, 9, '')],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);
});

test('user B cannot get user A existing room state', function () {
    $userA = n6User(['name' => 'N6 Owner A']);
    $userB = n6User(['name' => 'N6 Attacker B']);
    n6CreateRoom($userA, 'n6-secret-room')->assertOk();

    $this->actingAs($userB)
        ->getJson('/api/games/state/n6-secret-room')
        ->assertForbidden()
        ->assertJsonPath('error', 'Forbidden')
        ->assertJsonMissingPath('game_state');
});

test('user B cannot post or reset user A existing room and state stays unchanged', function () {
    $userA = n6User(['name' => 'N6 Owner A']);
    $userB = n6User(['name' => 'N6 Attacker B']);
    n6CreateRoom($userA, 'n6-locked-room', [
        'board' => ['X', 'X', 'X', '', '', '', '', '', ''],
        'scores' => ['X' => 3],
    ])->assertOk();

    $session = GameSession::query()->where('room_id', 'n6-locked-room')->first();
    $originalState = $session->game_state;
    $originalActivity = optional($session->last_activity)->toJSON();
    $originalUpdated = $session->updated_at?->toJSON();

    $this->actingAs($userB)
        ->postJson('/api/games/state/n6-locked-room', [
            'game_type' => 'tictactoe',
            'user_id' => $userA->id,
            'game_state' => [
                'board' => ['O', 'O', 'O', 'O', 'O', 'O', 'O', 'O', 'O'],
                'scores' => ['O' => 99],
                'participant_user_ids' => [$userB->id],
                'players' => [['id' => $userB->id, 'user_id' => $userB->id, 'name' => $userB->name]],
            ],
        ])
        ->assertForbidden()
        ->assertJsonPath('error', 'Forbidden');

    $this->actingAs($userB)
        ->postJson('/api/games/reset/n6-locked-room', [
            'game_type' => 'tictactoe',
            'initial_state' => ['board' => array_fill(0, 9, 'O')],
        ])
        ->assertForbidden()
        ->assertJsonPath('error', 'Forbidden');

    $fresh = GameSession::query()->where('room_id', 'n6-locked-room')->first();

    expect($fresh->game_state)->toEqual($originalState)
        ->and(optional($fresh->last_activity)->toJSON())->toBe($originalActivity)
        ->and($fresh->updated_at?->toJSON())->toBe($originalUpdated)
        ->and(n6Participants('n6-locked-room'))->toContain($userA->id)
        ->and(n6Participants('n6-locked-room'))->not->toContain($userB->id);
});

test('unauthenticated requests to game state endpoints are rejected', function () {
    $userA = n6User();
    n6CreateRoom($userA, 'n6-auth-room')->assertOk();

    Auth::forgetGuards();
    $this->flushSession();
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/games/state/n6-auth-room')->assertUnauthorized();
    $this->postJson('/api/games/state/n6-auth-room', [
        'game_type' => 'tictactoe',
        'game_state' => ['board' => []],
    ])->assertUnauthorized();
    $this->postJson('/api/games/reset/n6-auth-room', [
        'initial_state' => [],
    ])->assertUnauthorized();
});

test('client supplied participant ids and user id cannot grant access to another room', function () {
    $userA = n6User(['name' => 'N6 Real A']);
    $userB = n6User(['name' => 'N6 Forger B']);
    n6CreateRoom($userA, 'n6-forge-room')->assertOk();

    $this->actingAs($userB)
        ->postJson('/api/games/state/n6-forge-room', [
            'game_type' => 'tictactoe',
            'user_id' => $userA->id,
            'game_state' => [
                'participant_user_ids' => [$userB->id, $userA->id],
                'players' => [
                    ['id' => $userB->id, 'user_id' => $userB->id, 'userId' => $userB->id, 'name' => $userA->name],
                ],
            ],
        ])
        ->assertForbidden();

    $this->actingAs($userB)
        ->getJson('/api/games/state/n6-forge-room')
        ->assertForbidden()
        ->assertJsonMissingPath('game_state');

    expect(n6Participants('n6-forge-room'))->not->toContain($userB->id);
});

test('server-side participant ids authorize http access, player names do not', function () {
    $userA = n6User(['name' => 'N6 Named Player']);
    $userB = n6User(['name' => 'N6 Other']);

    GameSession::query()->create([
        'room_id' => 'n6-players-room',
        'game_type' => 'tictactoe',
        'game_state' => [
            'players' => [
                ['id' => $userA->id, 'name' => $userA->name, 'symbol' => 'X'],
            ],
            'participant_user_ids' => [$userA->id],
            'board' => ['hidden-card'],
        ],
        'last_activity' => now(),
    ]);

    $this->actingAs($userA)
        ->getJson('/api/games/state/n6-players-room')
        ->assertOk()
        ->assertJsonPath('exists', true)
        ->assertJsonPath('game_state.board.0', 'hidden-card');

    $this->actingAs($userB)
        ->getJson('/api/games/state/n6-players-room')
        ->assertForbidden()
        ->assertJsonMissingPath('game_state');
});

test('missing room get still returns exists false for an authenticated user', function () {
    $user = n6User();

    $this->actingAs($user)
        ->getJson('/api/games/state/n6-missing-room')
        ->assertOk()
        ->assertJsonPath('exists', false)
        ->assertJsonPath('game_state', null);
});

test('same display name cannot get post or reset another users room', function () {
    $userA = n6User(['name' => 'N10 Same Name']);
    $userB = n6User(['name' => 'N10 Same Name']);
    n6CreateRoom($userA, 'n10-http-collision', [
        'players' => [['name' => 'N10 Same Name', 'symbol' => 'X']],
        'board' => ['X', '', '', '', '', '', '', '', ''],
    ])->assertOk();

    $session = GameSession::query()->where('room_id', 'n10-http-collision')->first();
    $originalState = $session->game_state;
    $originalActivity = optional($session->last_activity)->toJSON();

    $this->actingAs($userB)
        ->getJson('/api/games/state/n10-http-collision')
        ->assertForbidden()
        ->assertJsonPath('error', 'Forbidden')
        ->assertJsonMissingPath('game_state');

    $this->actingAs($userB)
        ->postJson('/api/games/state/n10-http-collision', [
            'game_type' => 'tictactoe',
            'game_state' => [
                'board' => array_fill(0, 9, 'O'),
                'players' => [['name' => $userB->name, 'user_id' => $userB->id, 'id' => $userB->id]],
                'participant_user_ids' => [$userB->id],
            ],
        ])
        ->assertForbidden();

    $this->actingAs($userB)
        ->postJson('/api/games/reset/n10-http-collision', [
            'game_type' => 'tictactoe',
            'initial_state' => ['board' => array_fill(0, 9, 'O')],
        ])
        ->assertForbidden();

    $fresh = GameSession::query()->where('room_id', 'n10-http-collision')->first();
    expect($fresh->game_state)->toEqual($originalState)
        ->and(optional($fresh->last_activity)->toJSON())->toBe($originalActivity)
        ->and(n6Participants('n10-http-collision'))->toContain($userA->id)
        ->and(n6Participants('n10-http-collision'))->not->toContain($userB->id);
});

test('cosmetic player name matching another user does not grant access', function () {
    $userA = n6User(['name' => 'N10 Host']);
    $userB = n6User(['name' => 'N10 Guest']);

    GameSession::query()->create([
        'room_id' => 'n10-cosmetic-name',
        'game_type' => 'tictactoe',
        'game_state' => [
            'participant_user_ids' => [$userA->id],
            'players' => [
                ['name' => $userB->name, 'symbol' => 'O', 'user_id' => $userB->id, 'userId' => $userB->id, 'id' => $userB->id],
            ],
            'board' => ['secret'],
        ],
        'last_activity' => now(),
    ]);

    $this->actingAs($userB)
        ->getJson('/api/games/state/n10-cosmetic-name')
        ->assertForbidden()
        ->assertJsonMissingPath('game_state');

    $this->actingAs($userA)
        ->getJson('/api/games/state/n10-cosmetic-name')
        ->assertOk()
        ->assertJsonPath('game_state.board.0', 'secret');
});

test('renaming to another players name does not grant room access', function () {
    $userA = n6User(['name' => 'N10 Original Host']);
    $userB = n6User(['name' => 'N10 Original Guest']);
    n6CreateRoom($userA, 'n10-rename-room')->assertOk();

    Auth::forgetGuards();
    $this->actingAs($userB, 'sanctum')
        ->postJson('/api/mobile/profile/update', [
            'name' => 'N10 Original Host',
        ])
        ->assertOk();

    expect($userB->fresh()->name)->toBe('N10 Original Host');

    Auth::forgetGuards();
    $this->actingAs($userB)
        ->getJson('/api/games/state/n10-rename-room')
        ->assertForbidden()
        ->assertJsonMissingPath('game_state');

    $this->actingAs($userB)
        ->postJson('/api/games/state/n10-rename-room', [
            'game_type' => 'tictactoe',
            'game_state' => ['board' => array_fill(0, 9, 'O')],
        ])
        ->assertForbidden();

    expect(n6Participants('n10-rename-room'))->not->toContain($userB->id);
});
