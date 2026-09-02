<?php

use App\Models\Formation;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

const H5_WAKA = 'h5-waka-secret-key-xyz';
const H5_EXPO = 'ExponentPushToken[h5-expo-secret]';
const H5_EMAIL = 'h5.secret.owner@example.com';
const H5_PHONE = '0699988877';
const H5_CIN = 'H5CINSECRET1';
const H5_RESUME = 'h5-private-resume.pdf';
const H5_ALLOWED_USER_KEYS = ['id', 'name', 'image'];
const H5_FORBIDDEN_USER_KEYS = [
    'email',
    'phone',
    'cin',
    'password',
    'remember_token',
    'activation_token',
    'activation_token_expires_at',
    'wakatime_api_key',
    'expo_push_token',
    'role',
    'roles',
    'access_studio',
    'access_cowork',
    'access_scan',
    'resume',
    'cover',
    'about',
    'speciality',
    'socials',
    'must_change_password',
    'account_state',
    'formation_id',
    'invite_source',
    'email_verified_at',
];

function h5User(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => ['student'],
        'status' => 'Studying',
        'email_verified_at' => now(),
        'access_studio' => 0,
        'access_cowork' => 0,
        'access_scan' => 0,
    ], $overrides));
}

function h5SensitiveUser(array $overrides = []): User
{
    return h5User(array_merge([
        'name' => 'H5 Directory Student',
        'email' => H5_EMAIL,
        'phone' => H5_PHONE,
        'cin' => H5_CIN,
        'resume' => H5_RESUME,
        'wakatime_api_key' => H5_WAKA,
        'expo_push_token' => H5_EXPO,
        'access_studio' => 1,
        'access_cowork' => 1,
        'access_scan' => 1,
    ], $overrides));
}

function h5AssertPublicUserShape(array $user): void
{
    expect(array_keys($user))->toEqualCanonicalizing(H5_ALLOWED_USER_KEYS);

    foreach (H5_FORBIDDEN_USER_KEYS as $key) {
        expect($user)->not->toHaveKey($key);
    }
}

function h5AssertNoSecrets(array $payload): void
{
    $encoded = json_encode($payload);

    expect($encoded)->not->toContain(H5_WAKA)
        ->and($encoded)->not->toContain(H5_EXPO)
        ->and($encoded)->not->toContain(H5_EMAIL)
        ->and($encoded)->not->toContain(H5_PHONE)
        ->and($encoded)->not->toContain(H5_CIN)
        ->and($encoded)->not->toContain(H5_RESUME)
        ->and($encoded)->not->toContain('wakatime_api_key')
        ->and($encoded)->not->toContain('expo_push_token')
        ->and($encoded)->not->toContain('activation_token')
        ->and($encoded)->not->toContain('remember_token')
        ->and($encoded)->not->toContain('access_studio')
        ->and($encoded)->not->toContain('access_cowork')
        ->and($encoded)->not->toContain('access_scan');
}

test('anonymous cannot list mobile users', function () {
    $this->getJson('/api/mobile/users')->assertUnauthorized();
});

test('authenticated student receives only public directory user fields', function () {
    $student = h5SensitiveUser();
    h5User(['name' => 'H5 Other Student']);

    $response = $this->actingAs($student, 'sanctum')
        ->getJson('/api/mobile/users')
        ->assertOk()
        ->assertJsonMissingPath('formations');

    $payload = $response->json();
    expect($payload)->toHaveKey('users')
        ->and($payload)->not->toHaveKey('formations');

    $users = $payload['users'];
    expect($users)->toBeArray()->not->toBeEmpty();

    foreach ($users as $user) {
        h5AssertPublicUserShape($user);
    }

    h5AssertNoSecrets($payload);
});

test('json admin users are not dumped with secrets or private attributes', function () {
    $student = h5User(['name' => 'H5 Viewer']);
    $admin = h5SensitiveUser([
        'name' => 'H5 Json Admin',
        'email' => 'h5.json.admin@example.com',
        'role' => ['admin'],
        'wakatime_api_key' => H5_WAKA,
        'expo_push_token' => H5_EXPO,
    ]);

    $payload = $this->actingAs($student, 'sanctum')
        ->getJson('/api/mobile/users')
        ->assertOk()
        ->json();

    expect($payload)->toHaveKey('users')->not->toHaveKey('formations');

    $ids = collect($payload['users'])->pluck('id')->all();
    expect($ids)->not->toContain($admin->id);

    foreach ($payload['users'] as $user) {
        h5AssertPublicUserShape($user);
    }

    h5AssertNoSecrets($payload);
    expect(json_encode($payload))->not->toContain($admin->email);
});

test('formation records are not returned', function () {
    $student = h5User();

    Formation::query()->create([
        'name' => 'H5 Secret Formation',
        'img' => 'h5-private-formation.jpg',
        'start_time' => '2026-09-01',
        'end_time' => '2026-12-01',
    ]);

    $payload = $this->actingAs($student, 'sanctum')
        ->getJson('/api/mobile/users')
        ->assertOk()
        ->json();

    expect($payload)->not->toHaveKey('formations');

    $encoded = json_encode($payload);
    expect($encoded)->not->toContain('H5 Secret Formation')
        ->and($encoded)->not->toContain('h5-private-formation.jpg');
});

test('existing get api users directory remains a public field whitelist', function () {
    $student = h5SensitiveUser(['name' => 'H5 Api Users Student']);

    $payload = $this->actingAs($student, 'sanctum')
        ->getJson('/api/users')
        ->assertOk()
        ->json();

    expect($payload)->toBeArray()->not->toBeEmpty()
        ->and($payload)->not->toHaveKey('users')
        ->and($payload)->not->toHaveKey('formations');

    foreach ($payload as $user) {
        h5AssertPublicUserShape($user);
    }

    h5AssertNoSecrets($payload);
});
