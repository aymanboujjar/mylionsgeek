<?php

use App\Models\User;
use App\Models\UserSocialLink;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function m8User(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => ['student'],
        'status' => 'Studying',
        'email_verified_at' => now(),
        'account_state' => 0,
    ], $overrides));
}

function m8Add(User $user, array $payload)
{
    Auth::forgetGuards();

    return test()
        ->actingAs($user, 'sanctum')
        ->postJson('/api/mobile/profile/social-links', $payload);
}

function m8List(User $user)
{
    Auth::forgetGuards();

    return test()
        ->actingAs($user, 'sanctum')
        ->getJson('/api/mobile/profile/social-links');
}

test('anonymous cannot add a social link', function () {
    $this->postJson('/api/mobile/profile/social-links', [
        'title' => 'github',
        'url' => 'https://github.com/lionsgeek',
    ])->assertUnauthorized();

    expect(DB::table('user_social_links')->count())->toBe(0);
});

test('javascript scheme is rejected and not stored', function () {
    $user = m8User();

    m8Add($user, [
        'title' => 'portfolio',
        'url' => 'javascript:alert(1)',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');

    expect(UserSocialLink::query()->where('user_id', $user->id)->count())->toBe(0);
});

test('intent scheme is rejected', function () {
    $user = m8User();

    m8Add($user, [
        'title' => 'portfolio',
        'url' => 'intent://scan/#Intent;scheme=zxing;end',
    ])->assertUnprocessable();

    expect(UserSocialLink::query()->where('user_id', $user->id)->count())->toBe(0);
});

test('lionsgeek scheme is rejected', function () {
    $user = m8User();

    m8Add($user, [
        'title' => 'portfolio',
        'url' => 'lionsgeek://profile/1',
    ])->assertUnprocessable();

    expect(UserSocialLink::query()->where('user_id', $user->id)->count())->toBe(0);
});

test('data scheme is rejected', function () {
    $user = m8User();

    m8Add($user, [
        'title' => 'portfolio',
        'url' => 'data:text/html,<script>alert(1)</script>',
    ])->assertUnprocessable();

    expect(UserSocialLink::query()->where('user_id', $user->id)->count())->toBe(0);
});

test('file scheme is rejected', function () {
    $user = m8User();

    m8Add($user, [
        'title' => 'portfolio',
        'url' => 'file:///etc/passwd',
    ])->assertUnprocessable();

    expect(UserSocialLink::query()->where('user_id', $user->id)->count())->toBe(0);
});

test('valid https github url is created with unchanged success shape', function () {
    $user = m8User();
    $url = 'https://github.com/lionsgeek';

    $response = m8Add($user, [
        'title' => 'github',
        'url' => $url,
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.title', 'github')
        ->assertJsonPath('data.url', $url)
        ->assertJsonPath('data.user_id', $user->id);

    expect(UserSocialLink::query()->where('user_id', $user->id)->count())->toBe(1);

    m8List($user)
        ->assertOk()
        ->assertJsonPath('data.0.title', 'github')
        ->assertJsonPath('data.0.url', $url);
});

test('valid http url is created', function () {
    $user = m8User();

    m8Add($user, [
        'title' => 'portfolio',
        'url' => 'http://example.com',
    ])
        ->assertCreated()
        ->assertJsonPath('data.url', 'http://example.com')
        ->assertJsonPath('data.user_id', $user->id);

    expect(UserSocialLink::query()->where('user_id', $user->id)->value('url'))->toBe('http://example.com');
});
