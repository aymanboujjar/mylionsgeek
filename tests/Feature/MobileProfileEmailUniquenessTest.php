<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function m5User(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => ['student'],
        'status' => 'Studying',
        'email_verified_at' => now(),
        'account_state' => 0,
        'phone' => '0611111111',
        'speciality' => 'coding',
    ], $overrides));
}

function m5Update(User $user, array $payload)
{
    Auth::forgetGuards();

    return test()
        ->actingAs($user, 'sanctum')
        ->postJson('/api/mobile/profile/update', $payload);
}

test('anonymous cannot update profile', function () {
    $this->postJson('/api/mobile/profile/update', [
        'email' => 'attacker@example.com',
    ])->assertUnauthorized();
});

test('user A cannot take user B email and A email stays unchanged', function () {
    $userA = m5User(['email' => 'm5.a@example.com']);
    $userB = m5User(['email' => 'm5.b@example.com']);

    m5Update($userA, [
        'email' => $userB->email,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    expect($userA->fresh()->email)->toBe('m5.a@example.com')
        ->and($userB->fresh()->email)->toBe('m5.b@example.com');
});

test('user A can submit their own existing email', function () {
    $userA = m5User(['email' => 'm5.own@example.com']);

    m5Update($userA, [
        'email' => 'm5.own@example.com',
    ])
        ->assertOk()
        ->assertJsonPath('message', 'Profile updated')
        ->assertJsonPath('data.email', 'm5.own@example.com')
        ->assertJsonPath('data.id', $userA->id);

    expect($userA->fresh()->email)->toBe('m5.own@example.com');
});

test('other profile fields still update and success shape is unchanged', function () {
    $userA = m5User([
        'email' => 'm5.fields@example.com',
        'name' => 'Old Name',
        'phone' => '0611111111',
        'status' => 'Studying',
        'speciality' => 'coding',
    ]);

    $response = m5Update($userA, [
        'name' => 'New Name',
        'phone' => '0622222222',
        'status' => 'Working',
        'speciality' => 'media',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('message', 'Profile updated')
        ->assertJsonPath('data.id', $userA->id)
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.email', 'm5.fields@example.com')
        ->assertJsonPath('data.phone', '0622222222')
        ->assertJsonPath('data.status', 'Working')
        ->assertJsonPath('data.speciality', 'media')
        ->assertJsonStructure([
            'message',
            'data' => ['id', 'name', 'email', 'phone', 'status', 'speciality', 'image', 'resume', 'resume_url', 'resume_view_url'],
        ]);

    $fresh = $userA->fresh();
    expect($fresh->name)->toBe('New Name')
        ->and($fresh->email)->toBe('m5.fields@example.com')
        ->and($fresh->phone)->toBe('0622222222')
        ->and($fresh->status)->toBe('Working')
        ->and($fresh->speciality)->toBe('media')
        ->and($fresh->email_verified_at)->not->toBeNull();
});

test('user A can change to an unused email', function () {
    $userA = m5User(['email' => 'm5.before@example.com']);

    m5Update($userA, [
        'email' => 'm5.after@example.com',
    ])
        ->assertOk()
        ->assertJsonPath('data.email', 'm5.after@example.com');

    expect($userA->fresh()->email)->toBe('m5.after@example.com');
});
