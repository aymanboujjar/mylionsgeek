<?php

use App\Models\User;
use Illuminate\Support\Facades\Mail;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    $this->withoutVite();
});

function linkedInReturnUser(): User
{
    return User::factory()->create([
        'role' => ['student'],
        'status' => 'Studying',
        'email_verified_at' => now(),
        'account_state' => 0,
        'access_cowork' => 0,
        'access_studio' => 0,
        'access_scan' => 0,
        'formation_id' => null,
    ]);
}

test('linkedin callback with off-site return_to redirects home, not evil', function () {
    $user = linkedInReturnUser();

    $this->actingAs($user)
        ->withSession([
            'linkedin_oauth_state' => 'expected-state',
            'linkedin_oauth_return_to' => 'https://evil.example/phish',
        ])
        ->get('/auth/linkedin/callback?state=wrong&error=access_denied')
        ->assertRedirect('/');
});

test('linkedin callback with protocol-relative return_to redirects home', function () {
    $user = linkedInReturnUser();

    $this->actingAs($user)
        ->withSession([
            'linkedin_oauth_state' => 'expected-state',
            'linkedin_oauth_return_to' => '//evil.example/phish',
        ])
        ->get('/auth/linkedin/callback?state=wrong')
        ->assertRedirect('/');
});

test('linkedin callback keeps same-app relative return_to', function () {
    $user = linkedInReturnUser();

    $this->actingAs($user)
        ->withSession([
            'linkedin_oauth_state' => 'expected-state',
            'linkedin_oauth_return_to' => '/students/profile',
        ])
        ->get('/auth/linkedin/callback?state=wrong')
        ->assertRedirect('/students/profile');
});
