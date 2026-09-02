<?php

use App\Models\User;
use Illuminate\Support\Facades\Config;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

const LEARNING_SECRET = 'test-learning-client-secret';
const WAKATIME_KEY = 'waka_secret_must_not_leak_publicly';

beforeEach(function () {
    Config::set('services.learning.secret', LEARNING_SECRET);
});

function academyUserWithWakaTime(): User
{
    return User::factory()->create([
        'role' => ['student'],
        'email_verified_at' => now(),
        'wakatime_api_key' => WAKATIME_KEY,
    ]);
}

test('missing configured secret and no header returns 401', function () {
    Config::set('services.learning.secret', null);
    academyUserWithWakaTime();

    $this->getJson('/api/academy/wakatime')
        ->assertUnauthorized()
        ->assertJson([
            'status' => 'error',
            'message' => 'unauthorized',
        ])
        ->assertJsonMissing(['wakatime_key' => WAKATIME_KEY]);
});

test('null configured secret via Config::set still fails closed', function () {
    Config::set('services.learning.secret', null);
    academyUserWithWakaTime();

    $this->withHeaders(['x-api-key' => LEARNING_SECRET])
        ->getJson('/api/academy/wakatime')
        ->assertUnauthorized()
        ->assertJsonMissing(['wakatime_key' => WAKATIME_KEY]);
});

test('empty configured secret and no header returns 401', function () {
    Config::set('services.learning.secret', '');
    academyUserWithWakaTime();

    $this->getJson('/api/academy/wakatime')
        ->assertUnauthorized()
        ->assertJsonMissing(['wakatime_key' => WAKATIME_KEY]);
});

test('empty configured secret via Config::set still fails closed', function () {
    Config::set('services.learning.secret', '');
    academyUserWithWakaTime();

    $this->withHeaders(['x-api-key' => ''])
        ->getJson('/api/academy/wakatime')
        ->assertUnauthorized()
        ->assertJsonMissing(['wakatime_key' => WAKATIME_KEY]);
});

test('empty x-api-key returns 401', function () {
    academyUserWithWakaTime();

    $this->withHeaders(['x-api-key' => ''])
        ->getJson('/api/academy/wakatime')
        ->assertUnauthorized()
        ->assertJsonMissing(['wakatime_key' => WAKATIME_KEY]);
});

test('wrong learning key returns 401', function () {
    academyUserWithWakaTime();

    $this->withHeaders(['x-api-key' => 'wrong-learning-key'])
        ->getJson('/api/academy/wakatime')
        ->assertUnauthorized()
        ->assertJsonMissing(['wakatime_key' => WAKATIME_KEY]);
});

test('correct learning key can still fetch the wakatime payload', function () {
    $user = academyUserWithWakaTime();

    $this->withHeaders(['x-api-key' => LEARNING_SECRET])
        ->getJson('/api/academy/wakatime')
        ->assertOk()
        ->assertJsonFragment([
            'central_user_id' => $user->id,
            'wakatime_key' => WAKATIME_KEY,
        ]);
});

test('academy classes also requires the learning secret', function () {
    $this->getJson('/api/academy/classes')
        ->assertUnauthorized();

    $this->withHeaders(['x-api-key' => 'wrong-learning-key'])
        ->getJson('/api/academy/classes')
        ->assertUnauthorized();

    $this->withHeaders(['x-api-key' => LEARNING_SECRET])
        ->getJson('/api/academy/classes')
        ->assertOk();
});

test('wakatime payload is not publicly accessible', function () {
    academyUserWithWakaTime();

    $this->getJson('/api/academy/wakatime')
        ->assertUnauthorized()
        ->assertJsonMissing(['wakatime_key' => WAKATIME_KEY]);

    $this->withHeaders(['x-api-key' => LEARNING_SECRET])
        ->getJson('/api/academy/wakatime')
        ->assertOk()
        ->assertJsonFragment(['wakatime_key' => WAKATIME_KEY]);
});
