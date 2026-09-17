<?php

use App\Models\Call;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\AgoraTokenService;
use Illuminate\Support\Facades\Schema;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function callUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => ['student'],
        'status' => 'Studying',
        'email_verified_at' => now(),
    ], $overrides));
}

function fakeAgoraTokens(): void
{
    $mock = Mockery::mock(AgoraTokenService::class);
    $mock->shouldReceive('generateRtcToken')->andReturnUsing(function ($channel, $uid) {
        return 'agora-token-'.$uid.'-'.$channel;
    });
    app()->instance(AgoraTokenService::class, $mock);
}

beforeEach(function () {
    fakeAgoraTokens();
});

test('unauthenticated cannot create call', function () {
    $this->postJson('/api/mobile/calls/initiate', [
        'callee_id' => 1,
        'type' => 'audio',
    ])->assertUnauthorized();
});

test('authenticated user can create audio call', function () {
    $caller = callUser();
    $callee = callUser();

    $response = $this->actingAs($caller, 'sanctum')
        ->postJson('/api/mobile/calls/initiate', [
            'callee_id' => $callee->id,
            'type' => 'audio',
        ])
        ->assertCreated()
        ->assertJsonPath('type', 'audio')
        ->assertJsonStructure(['call_id', 'channel_name', 'token', 'uid', 'call']);

    expect($response->json('token'))->toStartWith('agora-token-'.$caller->id);
    expect(Call::query()->where('caller_id', $caller->id)->where('status', Call::STATUS_RINGING)->exists())->toBeTrue();
});

test('authenticated user can create video call', function () {
    $caller = callUser();
    $callee = callUser();

    $this->actingAs($caller, 'sanctum')
        ->postJson('/api/mobile/calls/initiate', [
            'receiver_id' => $callee->id,
            'type' => 'video',
        ])
        ->assertCreated()
        ->assertJsonPath('type', 'video');
});

test('self-call is rejected', function () {
    $user = callUser();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/mobile/calls/initiate', [
            'callee_id' => $user->id,
            'type' => 'audio',
        ])
        ->assertStatus(422);
});

test('invalid receiver is rejected', function () {
    $user = callUser();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/mobile/calls/initiate', [
            'callee_id' => 999999,
            'type' => 'audio',
        ])
        ->assertStatus(422);
});

test('invalid type is rejected', function () {
    $caller = callUser();
    $callee = callUser();

    $this->actingAs($caller, 'sanctum')
        ->postJson('/api/mobile/calls/initiate', [
            'callee_id' => $callee->id,
            'type' => 'fax',
        ])
        ->assertStatus(422);
});

test('blocked users cannot call each other', function () {
    if (! Schema::hasTable('user_blocks')) {
        $this->markTestSkipped('user_blocks table missing');
    }

    $caller = callUser();
    $callee = callUser();
    UserBlock::query()->create([
        'blocker_id' => $callee->id,
        'blocked_id' => $caller->id,
    ]);

    $this->actingAs($caller, 'sanctum')
        ->postJson('/api/mobile/calls/initiate', [
            'callee_id' => $callee->id,
            'type' => 'audio',
        ])
        ->assertStatus(422);
});

test('duplicate active call is rejected', function () {
    $caller = callUser();
    $callee = callUser();

    $this->actingAs($caller, 'sanctum')
        ->postJson('/api/mobile/calls/initiate', [
            'callee_id' => $callee->id,
            'type' => 'audio',
        ])
        ->assertCreated();

    $this->actingAs($caller, 'sanctum')
        ->postJson('/api/mobile/calls/initiate', [
            'callee_id' => $callee->id,
            'type' => 'video',
        ])
        ->assertStatus(422);
});

test('receiver accepts call', function () {
    $caller = callUser();
    $callee = callUser();

    $create = $this->actingAs($caller, 'sanctum')
        ->postJson('/api/mobile/calls/initiate', [
            'callee_id' => $callee->id,
            'type' => 'audio',
        ])
        ->assertCreated();

    $callId = $create->json('call_id');

    $this->actingAs($callee, 'sanctum')
        ->postJson("/api/mobile/calls/{$callId}/accept")
        ->assertOk()
        ->assertJsonPath('call.status', Call::STATUS_ACCEPTED);
});

test('receiver rejects call', function () {
    $caller = callUser();
    $callee = callUser();

    $callId = $this->actingAs($caller, 'sanctum')
        ->postJson('/api/mobile/calls/initiate', ['callee_id' => $callee->id])
        ->json('call_id');

    $this->actingAs($callee, 'sanctum')
        ->postJson("/api/mobile/calls/{$callId}/reject")
        ->assertOk();

    expect(Call::find($callId)->status)->toBe(Call::STATUS_REJECTED);
});

test('caller cancels ringing call', function () {
    $caller = callUser();
    $callee = callUser();

    $callId = $this->actingAs($caller, 'sanctum')
        ->postJson('/api/mobile/calls/initiate', ['callee_id' => $callee->id])
        ->json('call_id');

    $this->actingAs($caller, 'sanctum')
        ->postJson("/api/mobile/calls/{$callId}/cancel")
        ->assertOk();

    expect(Call::find($callId)->status)->toBe(Call::STATUS_CANCELLED);
});

test('participant can end accepted call', function () {
    $caller = callUser();
    $callee = callUser();

    $callId = $this->actingAs($caller, 'sanctum')
        ->postJson('/api/mobile/calls/initiate', ['callee_id' => $callee->id])
        ->json('call_id');

    $this->actingAs($callee, 'sanctum')
        ->postJson("/api/mobile/calls/{$callId}/accept")
        ->assertOk();

    $this->actingAs($caller, 'sanctum')
        ->postJson("/api/mobile/calls/{$callId}/end")
        ->assertOk();

    $call = Call::find($callId);
    expect($call->status)->toBe(Call::STATUS_ENDED)
        ->and($call->duration)->not->toBeNull();
});

test('unauthorized user cannot access call', function () {
    $caller = callUser();
    $callee = callUser();
    $stranger = callUser();

    $callId = $this->actingAs($caller, 'sanctum')
        ->postJson('/api/mobile/calls/initiate', ['callee_id' => $callee->id])
        ->json('call_id');

    $this->actingAs($stranger, 'sanctum')
        ->getJson("/api/mobile/calls/{$callId}")
        ->assertForbidden();

    $this->actingAs($stranger, 'sanctum')
        ->postJson("/api/mobile/calls/{$callId}/accept")
        ->assertForbidden();

    $this->actingAs($stranger, 'sanctum')
        ->postJson("/api/mobile/calls/{$callId}/reject")
        ->assertForbidden();

    $this->actingAs($stranger, 'sanctum')
        ->postJson("/api/mobile/calls/{$callId}/cancel")
        ->assertForbidden();

    $this->actingAs($stranger, 'sanctum')
        ->postJson("/api/mobile/calls/{$callId}/end")
        ->assertForbidden();

    $this->actingAs($stranger, 'sanctum')
        ->postJson("/api/mobile/calls/{$callId}/token")
        ->assertForbidden();
});

test('unauthorized token request is forbidden', function () {
    $caller = callUser();
    $callee = callUser();
    $stranger = callUser();

    $callId = $this->actingAs($caller, 'sanctum')
        ->postJson('/api/mobile/calls/initiate', ['callee_id' => $callee->id])
        ->json('call_id');

    $this->actingAs($stranger, 'sanctum')
        ->postJson("/api/mobile/calls/{$callId}/token")
        ->assertForbidden();
});

test('ended call token is rejected', function () {
    $caller = callUser();
    $callee = callUser();

    $callId = $this->actingAs($caller, 'sanctum')
        ->postJson('/api/mobile/calls/initiate', ['callee_id' => $callee->id])
        ->json('call_id');

    $this->actingAs($callee, 'sanctum')->postJson("/api/mobile/calls/{$callId}/accept")->assertOk();
    $this->actingAs($caller, 'sanctum')->postJson("/api/mobile/calls/{$callId}/end")->assertOk();

    $this->actingAs($caller, 'sanctum')
        ->postJson("/api/mobile/calls/{$callId}/token")
        ->assertForbidden();
});

test('participant can get token for active call', function () {
    $caller = callUser();
    $callee = callUser();

    $callId = $this->actingAs($caller, 'sanctum')
        ->postJson('/api/mobile/calls/initiate', ['callee_id' => $callee->id])
        ->json('call_id');

    $this->actingAs($caller, 'sanctum')
        ->postJson("/api/mobile/calls/{$callId}/token")
        ->assertOk()
        ->assertJsonStructure(['token', 'channel_name', 'uid']);
});
