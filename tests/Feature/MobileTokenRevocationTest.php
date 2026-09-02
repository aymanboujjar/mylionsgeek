<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\PersonalAccessToken;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function h6User(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => ['student'],
        'status' => 'Studying',
        'email_verified_at' => now(),
        'account_state' => 0,
    ], $overrides));
}

function h6Login(User $user, string $password = 'password'): string
{
    $response = test()->postJson('/api/mobile/login', [
        'email' => $user->email,
        'password' => $password,
    ])->assertOk();

    $token = $response->json('token');
    expect($token)->toBeString()->not->toBeEmpty();

    return $token;
}

function h6AuthGet(string $token, string $uri = '/api/mobile/profile')
{
    Auth::forgetGuards();

    return test()->flushSession()->withToken($token)->getJson($uri);
}

function h6AuthPost(string $token, string $uri, array $data = [])
{
    Auth::forgetGuards();

    return test()->flushSession()->withToken($token)->postJson($uri, $data);
}

test('anonymous logout is unauthorized', function () {
    $this->postJson('/api/mobile/logout')->assertUnauthorized();
});

test('authenticated token can access a protected endpoint then logout invalidates only that token', function () {
    $userA = h6User(['email' => 'h6.a@example.com']);
    $userB = h6User(['email' => 'h6.b@example.com']);

    $tokenA = h6Login($userA);
    $tokenB = h6Login($userB);

    h6AuthGet($tokenA)->assertOk();
    h6AuthGet($tokenB)->assertOk();

    h6AuthPost($tokenA, '/api/mobile/logout')
        ->assertOk()
        ->assertJsonPath('message', 'Logged out successfully.');

    h6AuthGet($tokenA)->assertUnauthorized();
    h6AuthGet($tokenB)->assertOk();

    expect(PersonalAccessToken::query()->where('tokenable_id', $userA->id)->count())->toBe(0)
        ->and(PersonalAccessToken::query()->where('tokenable_id', $userB->id)->count())->toBe(1);
});

test('mobile login stores expires_at about 30 days in the future', function () {
    $this->freezeTime();

    $user = h6User();
    h6Login($user);

    $expiresAt = PersonalAccessToken::query()
        ->where('tokenable_id', $user->id)
        ->where('name', 'mobile')
        ->value('expires_at');

    expect($expiresAt)->not->toBeNull();

    $expires = Carbon::parse($expiresAt);
    expect($expires->timestamp)->toBe(now()->addDays(30)->timestamp);
});

test('expired mobile token is rejected', function () {
    $this->freezeTime();

    $user = h6User();
    $token = h6Login($user);

    h6AuthGet($token)->assertOk();

    $this->travel(31)->days();

    h6AuthGet($token)->assertUnauthorized();
});

test('mobile password change revokes existing tokens and requires a new login', function () {
    $user = h6User();
    $oldToken = h6Login($user);

    h6AuthPost($oldToken, '/api/mobile/password', [
            'current_password' => 'password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])
        ->assertOk()
        ->assertJsonPath('must_relogin', true);

    h6AuthGet($oldToken)->assertUnauthorized();
    expect(PersonalAccessToken::query()->where('tokenable_id', $user->id)->count())->toBe(0);

    $newToken = h6Login($user->fresh(), 'new-password-123');
    h6AuthGet($newToken)->assertOk();
});

test('web password change revokes mobile pats without requiring a new web session cookie', function () {
    $user = h6User();
    $token = h6Login($user);

    $this->actingAs($user)
        ->from(route('password.edit'))
        ->put(route('password.update'), [
            'current_password' => 'password',
            'password' => 'web-password-123',
            'password_confirmation' => 'web-password-123',
        ])
        ->assertSessionHasNoErrors();

    $this->flushSession();

    expect(Hash::check('web-password-123', $user->fresh()->password))->toBeTrue()
        ->and(PersonalAccessToken::query()->where('tokenable_id', $user->id)->count())->toBe(0);

    h6AuthGet($token)->assertUnauthorized();
});

test('email password reset revokes mobile pats', function () {
    $user = h6User();
    $token = h6Login($user);
    $resetToken = Password::broker()->createToken($user);

    $this->post(route('password.store'), [
        'token' => $resetToken,
        'email' => $user->email,
        'password' => 'reset-password-123',
        'password_confirmation' => 'reset-password-123',
    ])->assertSessionHasNoErrors();

    expect(Hash::check('reset-password-123', $user->fresh()->password))->toBeTrue()
        ->and(PersonalAccessToken::query()->where('tokenable_id', $user->id)->count())->toBe(0);

    h6AuthGet($token)->assertUnauthorized();
});

test('admin password reset revokes the target users mobile pats', function () {
    Mail::fake();

    $admin = h6User([
        'role' => ['admin'],
        'email' => 'h6.admin@example.com',
    ]);
    $target = h6User(['email' => 'h6.target@example.com']);
    $targetToken = h6Login($target);
    $adminToken = h6Login($admin);

    $this->actingAs($admin)
        ->from('/admin/users')
        ->post('/admin/users/'.$target->id.'/reset-password')
        ->assertRedirect();

    $this->flushSession();

    expect(PersonalAccessToken::query()->where('tokenable_id', $target->id)->count())->toBe(0)
        ->and(PersonalAccessToken::query()->where('tokenable_id', $admin->id)->count())->toBe(1);

    h6AuthGet($targetToken)->assertUnauthorized();
    h6AuthGet($adminToken)->assertOk();
});

test('repeated logins still accumulate tokens until logout or password change', function () {
    $user = h6User();
    $first = h6Login($user);
    $second = h6Login($user);

    expect(PersonalAccessToken::query()->where('tokenable_id', $user->id)->count())->toBe(2);

    h6AuthGet($first)->assertOk();
    h6AuthGet($second)->assertOk();
});
