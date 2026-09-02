<?php

use App\Mail\ForgotPasswordLinkMail;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Mail::fake();
});

function l1User(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => ['student'],
        'status' => 'Studying',
        'email_verified_at' => now(),
        'account_state' => 0,
    ], $overrides));
}

function l1Forgot(string $email)
{
    return test()->postJson('/api/mobile/forgot-password', [
        'email' => $email,
    ]);
}

function l1GenericPayload(): array
{
    return [
        'status' => 'A reset link will be sent if the account exists.',
        'ok' => true,
    ];
}

test('unknown email returns 200 with the generic response', function () {
    $response = l1Forgot('nobody.l1@example.com');

    $response
        ->assertOk()
        ->assertExactJson(l1GenericPayload());

    Mail::assertNothingSent();
});

test('known email returns 200 with identical status and ok', function () {
    $user = l1User(['email' => 'known.l1@example.com']);

    $unknown = l1Forgot('nobody.l1@example.com')->assertOk()->json();
    $known = l1Forgot($user->email)->assertOk()->json();

    expect($known)->toBe($unknown)
        ->and($known)->toBe(l1GenericPayload());
});

test('reset mail is sent only for the known account', function () {
    $user = l1User(['email' => 'mailed.l1@example.com']);

    l1Forgot('missing.l1@example.com')->assertOk();
    Mail::assertNotSent(ForgotPasswordLinkMail::class);

    l1Forgot($user->email)->assertOk();
    Mail::assertSent(ForgotPasswordLinkMail::class, function (ForgotPasswordLinkMail $mail) use ($user) {
        return $mail->hasTo($user->email);
    });
});

test('invalid email returns 422', function () {
    l1Forgot('not-an-email')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

test('forgot-password still works anonymously', function () {
    l1Forgot('anon.l1@example.com')
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('status', 'A reset link will be sent if the account exists.');
});
