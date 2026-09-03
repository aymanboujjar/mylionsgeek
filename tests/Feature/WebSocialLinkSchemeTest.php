<?php

use App\Models\User;
use App\Models\UserSocialLink;
use Illuminate\Support\Facades\Auth;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
});

function n7User(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => ['student'],
        'status' => 'Studying',
        'email_verified_at' => now(),
        'account_state' => 0,
    ], $overrides));
}

function n7Create(User $user, array $payload)
{
    Auth::forgetGuards();

    return test()
        ->from('/students/'.$user->id)
        ->actingAs($user)
        ->postJson('/students/social-links', $payload);
}

function n7Update(User $user, int $linkId, array $payload)
{
    Auth::forgetGuards();

    return test()
        ->from('/students/'.$user->id)
        ->actingAs($user)
        ->putJson('/students/social-links/'.$linkId, $payload);
}

function n7SafeLink(User $user, string $url = 'https://example.com'): UserSocialLink
{
    return UserSocialLink::query()->create([
        'user_id' => $user->id,
        'title' => 'portfolio',
        'url' => $url,
        'sort_order' => 0,
    ]);
}

$rejectedSchemes = [
    'javascript:alert(document.domain)',
    'JaVaScRiPt:alert(1)',
    'data:text/html,<script>alert(1)</script>',
    'vbscript:msgbox(1)',
    'file:///etc/passwd',
    'intent://scan/#Intent;scheme=zxing;end',
    'lionsgeek://profile/1',
    ' javascript:alert(1)',
];

test('web create rejects unsafe url schemes and does not persist them', function (string $url) {
    $user = n7User();

    n7Create($user, [
        'title' => 'portfolio',
        'url' => $url,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');

    expect(UserSocialLink::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(UserSocialLink::query()->where('url', $url)->exists())->toBeFalse();
})->with($rejectedSchemes);

test('web create accepts http and https urls', function (string $url) {
    $user = n7User();

    n7Create($user, [
        'title' => 'portfolio',
        'url' => $url,
    ])
        ->assertRedirect();

    expect(UserSocialLink::query()->where('user_id', $user->id)->value('url'))->toBe($url);
})->with([
    'http://example.com',
    'https://example.com',
]);

test('web update rejects unsafe url schemes and keeps the original url', function (string $url) {
    $user = n7User();
    $link = n7SafeLink($user, 'https://safe.example');

    n7Update($user, $link->id, [
        'title' => 'portfolio',
        'url' => $url,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');

    expect($link->fresh()->url)->toBe('https://safe.example')
        ->and(UserSocialLink::query()->where('url', $url)->exists())->toBeFalse();
})->with($rejectedSchemes);

test('web update accepts http and https urls', function (string $url) {
    $user = n7User();
    $link = n7SafeLink($user, 'https://old.example');

    n7Update($user, $link->id, [
        'title' => 'portfolio',
        'url' => $url,
    ])
        ->assertRedirect();

    expect($link->fresh()->url)->toBe($url);
})->with([
    'http://example.com/updated',
    'https://example.com/updated',
]);

test('web update cannot change another users social link', function () {
    $owner = n7User();
    $other = n7User();
    $link = n7SafeLink($owner, 'https://owner.example');

    n7Update($other, $link->id, [
        'title' => 'portfolio',
        'url' => 'https://attacker.example',
    ])->assertRedirect();

    expect($link->fresh()->url)->toBe('https://owner.example')
        ->and($link->fresh()->user_id)->toBe($owner->id);
});

test('guest cannot create a web social link', function () {
    test()
        ->postJson('/students/social-links', [
            'title' => 'portfolio',
            'url' => 'https://example.com',
        ])
        ->assertUnauthorized();

    expect(UserSocialLink::query()->count())->toBe(0);
});
