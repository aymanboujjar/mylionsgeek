<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Mail::fake();
});

function m1User(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => ['student'],
        'status' => 'Studying',
        'email_verified_at' => now(),
        'account_state' => 0,
    ], $overrides));
}

function m1FailedLogin(string $email, array $server = [])
{
    return test()
        ->withServerVariables($server)
        ->postJson('/api/mobile/login', [
            'email' => $email,
            'password' => 'wrong-password',
        ]);
}

test('sixth failed login returns 429', function () {
    $user = m1User(['email' => 'm1.login@example.com']);

    for ($i = 0; $i < 5; $i++) {
        m1FailedLogin($user->email)->assertUnprocessable();
    }

    m1FailedLogin($user->email)
        ->assertTooManyRequests()
        ->assertJsonMissingPath('token');
});

test('valid login under the limit returns 200 and a token', function () {
    $user = m1User(['email' => 'm1.valid@example.com']);

    $this->postJson('/api/mobile/login', [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertOk()
        ->assertJsonStructure(['token', 'user'])
        ->assertJsonPath('user.email', $user->email);

    expect($this->postJson('/api/mobile/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->json('token'))->toBeString()->not->toBeEmpty();
});

test('login limiter is keyed per email and IP', function () {
    $target = m1User(['email' => 'm1.target@example.com']);
    $other = m1User(['email' => 'm1.other@example.com']);

    for ($i = 0; $i < 5; $i++) {
        m1FailedLogin($target->email)->assertUnprocessable();
    }

    m1FailedLogin($target->email)->assertTooManyRequests();

    m1FailedLogin($other->email)->assertUnprocessable();

    m1FailedLogin($target->email, ['REMOTE_ADDR' => '203.0.113.50'])
        ->assertUnprocessable();

    $this->postJson('/api/mobile/login', [
        'email' => $other->email,
        'password' => 'password',
    ])->assertOk()->assertJsonStructure(['token', 'user']);
});

test('forgot-password over the limit returns 429', function () {
    $user = m1User(['email' => 'm1.forgot.limit@example.com']);

    for ($i = 0; $i < 6; $i++) {
        $this->postJson('/api/mobile/forgot-password', [
            'email' => $user->email,
        ])->assertOk();
    }

    $this->postJson('/api/mobile/forgot-password', [
        'email' => $user->email,
    ])->assertTooManyRequests();
});

test('forgot-password under the limit still works', function () {
    $user = m1User(['email' => 'm1.forgot.ok@example.com']);

    $this->postJson('/api/mobile/forgot-password', [
        'email' => $user->email,
    ])
        ->assertOk()
        ->assertJsonStructure(['status', 'ok']);
});

test('login and forgot-password remain accessible anonymously', function () {
    $user = m1User(['email' => 'm1.anon@example.com']);

    $this->postJson('/api/mobile/login', [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertOk()
        ->assertJsonStructure(['token', 'user']);

    $this->postJson('/api/mobile/forgot-password', [
        'email' => $user->email,
    ])
        ->assertOk()
        ->assertJsonStructure(['status', 'ok']);
});
