<?php

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    Config::set('services.lionsgeek.key', 'test-lionsgeek-invite-key');
});

function invitePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Invited Student',
        'email' => 'invited.student@example.com',
        'phone' => '0612345678',
    ], $overrides);
}

function staffUser(array $roles = ['admin']): User
{
    return User::factory()->create([
        'role' => $roles,
        'email_verified_at' => now(),
    ]);
}

test('anonymous user cannot invite students', function () {
    $this->postJson('/api/invite-student', invitePayload())
        ->assertUnauthorized();

    expect(User::query()->where('email', 'invited.student@example.com')->exists())->toBeFalse();
});

test('student token cannot invite students', function () {
    $student = User::factory()->create(['role' => ['student']]);

    $this->actingAs($student, 'sanctum')
        ->postJson('/api/invite-student', invitePayload())
        ->assertForbidden();

    expect(User::query()->where('email', 'invited.student@example.com')->exists())->toBeFalse();
});

test('empty shared api key does not fail open', function () {
    Config::set('services.lionsgeek.key', '');

    $this->withToken('')
        ->postJson('/api/invite-student', invitePayload())
        ->assertUnauthorized();
});

test('staff can invite students and response does not expose the user or activation token', function () {
    $admin = staffUser();

    $response = $this->actingAs($admin, 'sanctum')
        ->postJson('/api/invite-student', invitePayload());

    $response->assertOk()
        ->assertJson([
            'status' => 'created',
            'mail_sent' => true,
        ])
        ->assertJsonMissingPath('data')
        ->assertJsonMissingPath('activation_token');

    $user = User::query()->where('email', 'invited.student@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->hasPendingActivation())->toBeTrue()
        ->and($user->activation_token)->not->toBeNull()
        ->and(strlen((string) $user->getRawOriginal('activation_token')))->toBe(64);
});

test('lionsgeek.ma shared bearer can invite students', function () {
    $response = $this->withToken('test-lionsgeek-invite-key')
        ->postJson('/api/invite-student', invitePayload());

    $response->assertOk()->assertJson(['status' => 'created']);
    expect(User::query()->where('email', 'invited.student@example.com')->exists())->toBeTrue();
});

test('inviting an already activated account does not rotate the activation token', function () {
    $existing = User::factory()->create([
        'email' => 'invited.student@example.com',
        'activation_token' => null,
        'role' => ['student'],
    ]);

    $this->withToken('test-lionsgeek-invite-key')
        ->postJson('/api/invite-student', invitePayload())
        ->assertOk()
        ->assertJson([
            'status' => 'exists',
            'mail_sent' => false,
        ]);

    $existing->refresh();
    expect($existing->hasPendingActivation())->toBeFalse()
        ->and($existing->activation_token)->toBeNull();
});

test('unsigned complete-profile get cannot activate', function () {
    $user = User::factory()->create(['role' => ['student']]);
    $plain = $user->issueActivationToken();

    $this->withoutVite()
        ->get('/complete-profile/'.$plain)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('profile/ExpiredLink'));
});

test('unsigned complete-profile post cannot set a password', function () {
    $user = User::factory()->create(['role' => ['student']]);
    $plain = $user->issueActivationToken();
    $original = $user->password;

    $this->withoutVite()
        ->post('/complete-profile/update/'.$plain, [
            'password' => 'NewPassword1',
            'password_confirmation' => 'NewPassword1',
            'phone' => '0611111111',
        ])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('profile/ExpiredLink'));

    $user->refresh();
    expect($user->password)->toBe($original)
        ->and($user->hasPendingActivation())->toBeTrue();
});

test('invalid token cannot activate even with a valid signature', function () {
    $this->withoutVite()
        ->get(URL::temporarySignedRoute(
            'user.complete-profile',
            now()->addHour(),
            ['token' => bin2hex(random_bytes(32))]
        ))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('profile/ExpiredLink'));
});

test('expired activation token cannot activate', function () {
    $user = User::factory()->create(['role' => ['student']]);
    $plain = $user->issueActivationToken();
    $user->forceFill(['activation_token_expires_at' => now()->subMinute()])->save();

    $this->withoutVite()
        ->get(URL::temporarySignedRoute(
            'user.complete-profile',
            now()->addHour(),
            ['token' => $plain]
        ))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('profile/ExpiredLink'));
});

test('signed complete-profile page does not expose the activation token', function () {
    $user = User::factory()->create(['role' => ['student']]);
    $plain = $user->issueActivationToken();

    $this->withoutVite()
        ->get(URL::temporarySignedRoute(
            'user.complete-profile',
            now()->addHours(24),
            ['token' => $plain]
        ))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('profile/index')
            ->has('submitUrl')
            ->missing('user.activation_token')
            ->where('user.name', $user->name)
        );
});

test('valid signed complete-profile post activates once and cannot be reused', function () {
    $user = User::factory()->create([
        'role' => ['student'],
        'invite_source' => 'lionsgeek_adult',
    ]);
    $plain = $user->issueActivationToken();

    $submitUrl = URL::temporarySignedRoute(
        'user.complete-profile.update',
        now()->addHours(24),
        ['token' => $plain]
    );

    $this->withoutVite()
        ->post($submitUrl, [
            'password' => 'NewPassword1',
            'password_confirmation' => 'NewPassword1',
        ])
        ->assertRedirect('/login');

    $user->refresh();
    expect($user->hasPendingActivation())->toBeFalse()
        ->and($user->activation_token)->toBeNull()
        ->and($user->activation_token_expires_at)->toBeNull();

    $this->withoutVite()
        ->post($submitUrl, [
            'password' => 'AnotherPassword1',
            'password_confirmation' => 'AnotherPassword1',
        ])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('profile/ExpiredLink'));
});
