<?php

use App\Models\User;
use App\Services\ExpoPushNotificationService;
use Illuminate\Support\Facades\Auth;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

const M3_EXPO_TOKEN = 'ExponentPushToken[m3-secret-full-expo-token-do-not-leak]';
const M3_LOG_SECRET = 'M3_LARAVEL_LOG_SECRET_MUST_NOT_APPEAR';

beforeEach(function () {
    $log = storage_path('logs/laravel.log');
    if (! is_dir(dirname($log))) {
        mkdir(dirname($log), 0777, true);
    }
    file_put_contents($log, M3_LOG_SECRET." Expo push notification error\n", FILE_APPEND);
});

function m3User(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => ['student'],
        'status' => 'Studying',
        'email_verified_at' => now(),
        'account_state' => 0,
        'expo_push_token' => M3_EXPO_TOKEN,
    ], $overrides));
}

function m3Auth(User $user)
{
    Auth::forgetGuards();

    return test()->actingAs($user, 'sanctum');
}

function m3AssertNoPushDisclosure($response, string $fullToken = M3_EXPO_TOKEN): void
{
    $response
        ->assertJsonMissingPath('full_token')
        ->assertJsonMissingPath('recent_log_errors')
        ->assertJsonMissingPath('error')
        ->assertJsonMissingPath('file')
        ->assertJsonMissingPath('line');

    $body = $response->getContent();
    expect($body)->not->toContain($fullToken)
        ->and($body)->not->toContain(M3_LOG_SECRET)
        ->and($body)->not->toContain('storage/logs/laravel.log');
}

test('anonymous cannot send a test push', function () {
    $this->postJson('/api/mobile/test-push')->assertUnauthorized();
});

test('anonymous cannot read push status', function () {
    $this->getJson('/api/mobile/push-status')->assertUnauthorized();
});

test('authenticated push-status does not expose the full token or a log dump', function () {
    $user = m3User();

    $response = m3Auth($user)->getJson('/api/mobile/push-status');

    $response
        ->assertOk()
        ->assertJsonPath('has_token', true)
        ->assertJsonPath('user_id', $user->id)
        ->assertJsonStructure(['has_token', 'token_preview', 'user_id', 'user_email']);

    m3AssertNoPushDisclosure($response);
    expect($response->json('token_preview'))->toStartWith(substr(M3_EXPO_TOKEN, 0, 30));
});

test('failed test-push without a registered token does not leak secrets', function () {
    $user = m3User(['expo_push_token' => null]);

    $response = m3Auth($user)->postJson('/api/mobile/test-push');

    $response
        ->assertStatus(400)
        ->assertJsonPath('success', false);

    m3AssertNoPushDisclosure($response, M3_EXPO_TOKEN);
});

test('failed test-push send does not return full_token, log lines, or exception details', function () {
    $user = m3User();

    $this->mock(ExpoPushNotificationService::class, function ($mock) {
        $mock->shouldReceive('sendToUser')->once()->andReturn(false);
    });

    $response = m3Auth($user)->postJson('/api/mobile/test-push', [
        'title' => 'Test',
        'body' => 'Body',
    ]);

    $response
        ->assertStatus(500)
        ->assertJsonPath('success', false);

    m3AssertNoPushDisclosure($response);
});

test('test-push exception path does not return raw exception file or line details', function () {
    $user = m3User();

    $this->mock(ExpoPushNotificationService::class, function ($mock) {
        $mock->shouldReceive('sendToUser')->once()->andThrow(
            new RuntimeException('secret-exception-message-from-expo')
        );
    });

    $response = m3Auth($user)->postJson('/api/mobile/test-push');

    $response
        ->assertStatus(500)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Unable to send the test notification. Please try again later.');

    m3AssertNoPushDisclosure($response);
    expect($response->getContent())->not->toContain('secret-exception-message-from-expo')
        ->and($response->getContent())->not->toContain(__FILE__);
});

test('successful test-push still works and does not expose the full token', function () {
    $user = m3User();

    $this->mock(ExpoPushNotificationService::class, function ($mock) {
        $mock->shouldReceive('sendToUser')->once()->andReturn(true);
    });

    $response = m3Auth($user)->postJson('/api/mobile/test-push', [
        'title' => '🧪 Test Push Notification',
        'body' => 'This is a test push notification from LionsGeek! If you see this, push notifications are working! 🎉',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('user_id', $user->id)
        ->assertJsonStructure(['success', 'message', 'token_preview', 'title', 'body']);

    m3AssertNoPushDisclosure($response);
});
