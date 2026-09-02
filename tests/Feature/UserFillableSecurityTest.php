<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    $this->withoutVite();
    Config::set('services.lionsgeek.key', 'test-lionsgeek-invite-key');
});

function l3User(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => ['student'],
        'status' => 'Studying',
        'email_verified_at' => now(),
        'account_state' => 0,
        'access_cowork' => 0,
        'access_studio' => 0,
        'access_scan' => 0,
    ], $overrides));
}

test('student cannot escalate privileged fields through the student update endpoint', function () {
    $student = l3User(['email' => 'l3.student@example.com']);
    $originalId = $student->id;
    $originalHash = $student->getRawOriginal('password');

    $this->actingAs($student)
        ->from('/students/'.$student->id)
        ->put('/students/update/'.$student->id, [
            'name' => $student->name,
            'email' => $student->email,
            'roles' => ['admin'],
            'access_scan' => 1,
            'access_cowork' => 1,
            'access_studio' => 1,
            'id' => $originalId + 999,
            'password' => 'hacked-password',
        ])
        ->assertRedirect();

    $student->refresh();

    expect($student->normalizedRoles())->toBe(['student'])
        ->and((int) $student->access_scan)->toBe(0)
        ->and((int) $student->access_cowork)->toBe(0)
        ->and((int) $student->access_studio)->toBe(0)
        ->and($student->id)->toBe($originalId)
        ->and($student->getRawOriginal('password'))->toBe($originalHash)
        ->and(Hash::check('password', $student->password))->toBeTrue();
});

test('staff can still set access flags and role on another user', function () {
    $admin = l3User([
        'role' => ['admin'],
        'email' => 'l3.admin@example.com',
    ]);
    $target = l3User(['email' => 'l3.target@example.com']);

    $this->actingAs($admin)
        ->from('/admin/users/'.$target->id)
        ->put('/students/update/'.$target->id, [
            'name' => $target->name,
            'email' => $target->email,
            'roles' => ['coach'],
            'access_scan' => 1,
            'access_cowork' => 1,
            'access_studio' => 1,
        ])
        ->assertRedirect();

    $target->refresh();

    expect($target->normalizedRoles())->toBe(['coach'])
        ->and((int) $target->access_scan)->toBe(1)
        ->and((int) $target->access_cowork)->toBe(1)
        ->and((int) $target->access_studio)->toBe(1);
});

test('invite student creation still assigns student role and access flags', function () {
    $admin = l3User([
        'role' => ['admin'],
        'email' => 'l3.inviter@example.com',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/invite-student', [
            'name' => 'L3 Invited',
            'email' => 'l3.invited@example.com',
            'phone' => '0612345678',
        ])
        ->assertOk()
        ->assertJsonPath('status', 'created');

    $invited = User::query()->where('email', 'l3.invited@example.com')->first();

    expect($invited)->not->toBeNull()
        ->and($invited->normalizedRoles())->toBe(['student'])
        ->and((int) $invited->access_studio)->toBe(1)
        ->and((int) $invited->access_cowork)->toBe(1)
        ->and($invited->password)->not->toBeEmpty()
        ->and($invited->hasPendingActivation())->toBeTrue();
});

test('mobile profile update ignores privileged extra fields', function () {
    $user = l3User(['email' => 'l3.mobile@example.com', 'name' => 'L3 Mobile']);
    $originalHash = $user->getRawOriginal('password');

    Auth::forgetGuards();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/mobile/profile/update', [
            'name' => 'L3 Mobile Updated',
            'role' => ['admin'],
            'roles' => ['admin'],
            'access_scan' => 1,
            'access_cowork' => 1,
            'access_studio' => 1,
            'id' => 999999,
            'password' => 'should-not-apply',
            'account_state' => 1,
            'wakatime_api_key' => 'stolen-key',
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Profile updated')
        ->assertJsonPath('data.name', 'L3 Mobile Updated');

    $user->refresh();

    expect($user->name)->toBe('L3 Mobile Updated')
        ->and($user->normalizedRoles())->toBe(['student'])
        ->and((int) $user->access_scan)->toBe(0)
        ->and((int) $user->access_cowork)->toBe(0)
        ->and((int) $user->access_studio)->toBe(0)
        ->and((int) $user->account_state)->toBe(0)
        ->and($user->wakatime_api_key)->toBeNull()
        ->and($user->getRawOriginal('password'))->toBe($originalHash);
});
