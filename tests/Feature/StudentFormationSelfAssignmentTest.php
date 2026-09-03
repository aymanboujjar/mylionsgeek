<?php

use App\Models\Formation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();

    if (Schema::hasTable('formations') && ! Schema::hasColumn('formations', 'category')) {
        Schema::table('formations', function (Blueprint $table) {
            $table->string('category')->nullable();
            $table->string('promo')->nullable();
            $table->string('user_id')->nullable();
        });
    }
});

function n2User(array $roles, array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => $roles,
        'status' => 'Studying',
        'email_verified_at' => now(),
        'account_state' => 0,
        'access_cowork' => 0,
        'access_studio' => 0,
        'access_scan' => 0,
    ], $overrides));
}

function n2Formation(string $name, array $overrides = []): Formation
{
    $coachId = $overrides['user_id'] ?? n2User(['coach'])->id;

    return Formation::query()->create(array_merge([
        'name' => $name,
        'category' => 'coding',
        'start_time' => '2026-09-01',
        'end_time' => '2026-12-01',
        'promo' => 'P1',
        'user_id' => $coachId,
        'img' => 'default_training.jpg',
    ], $overrides));
}

test('student cannot change own formation_id through self-update', function () {
    $formationA = n2Formation('Formation A');
    $formationB = n2Formation('Formation B');
    $student = n2User(['student'], [
        'email' => 'n2.self@example.com',
        'formation_id' => $formationA->id,
        'name' => 'N2 Student',
    ]);

    $this->actingAs($student)
        ->from('/students/'.$student->id)
        ->put('/students/update/'.$student->id, [
            'formation_id' => $formationB->id,
        ])
        ->assertRedirect();

    $student->refresh();

    expect((int) $student->formation_id)->toBe((int) $formationA->id)
        ->and((int) $student->formation_id)->not->toBe((int) $formationB->id);
});

test('student cannot change formation_id while submitting legitimate profile fields', function () {
    $formationA = n2Formation('Formation A Mixed');
    $formationB = n2Formation('Formation B Mixed');
    $student = n2User(['student'], [
        'email' => 'n2.mixed@example.com',
        'phone' => '0600000000',
        'formation_id' => $formationA->id,
        'name' => 'N2 Original Name',
    ]);

    $this->actingAs($student)
        ->from('/students/'.$student->id)
        ->put('/students/update/'.$student->id, [
            'name' => 'N2 Updated Name',
            'email' => 'n2.mixed.updated@example.com',
            'phone' => '0611111111',
            'formation_id' => $formationB->id,
        ])
        ->assertRedirect();

    $student->refresh();

    expect($student->name)->toBe('N2 Updated Name')
        ->and($student->email)->toBe('n2.mixed.updated@example.com')
        ->and($student->phone)->toBe('0611111111')
        ->and((int) $student->formation_id)->toBe((int) $formationA->id)
        ->and((int) $student->formation_id)->not->toBe((int) $formationB->id);
});

test('student cannot change another user formation_id through the update route', function () {
    $formationA = n2Formation('Target Formation A');
    $formationB = n2Formation('Target Formation B');
    $student = n2User(['student'], ['email' => 'n2.attacker@example.com']);
    $target = n2User(['student'], [
        'email' => 'n2.victim@example.com',
        'formation_id' => $formationA->id,
    ]);

    $this->actingAs($student)
        ->from('/students/'.$target->id)
        ->put('/students/update/'.$target->id, [
            'name' => $target->name,
            'email' => $target->email,
            'formation_id' => $formationB->id,
        ])
        ->assertForbidden();

    expect((int) $target->fresh()->formation_id)->toBe((int) $formationA->id);
});

test('forged formation_id does not grant access to another training', function () {
    $formationA = n2Formation('Enrolled Training');
    $formationB = n2Formation('Secret Training');
    $student = n2User(['student'], [
        'email' => 'n2.forge.access@example.com',
        'formation_id' => $formationA->id,
    ]);

    $this->actingAs($student)
        ->from('/students/'.$student->id)
        ->put('/students/update/'.$student->id, [
            'name' => $student->name,
            'email' => $student->email,
            'formation_id' => $formationB->id,
        ])
        ->assertRedirect();

    expect((int) $student->fresh()->formation_id)->toBe((int) $formationA->id);

    Auth::forgetGuards();

    $this->actingAs($student->fresh(), 'sanctum')
        ->getJson('/api/mobile/trainings/'.$formationB->id)
        ->assertForbidden();

    expect((int) $student->fresh()->formation_id)->toBe((int) $formationA->id);
});

test('enrolled student can still view their own training', function () {
    $formationA = n2Formation('Own Visible Training');
    $student = n2User(['student'], [
        'email' => 'n2.enrolled.view@example.com',
        'formation_id' => $formationA->id,
    ]);

    $this->actingAs($student, 'sanctum')
        ->getJson('/api/mobile/trainings/'.$formationA->id)
        ->assertOk()
        ->assertJsonPath('training.id', $formationA->id);
});

test('staff can still assign formation_id through student update', function () {
    $formationA = n2Formation('Staff From');
    $formationB = n2Formation('Staff To');
    $admin = n2User(['admin'], ['email' => 'n2.admin.assign@example.com']);
    $target = n2User(['student'], [
        'email' => 'n2.staff.target@example.com',
        'formation_id' => $formationA->id,
        'name' => 'Staff Target',
    ]);

    $this->actingAs($admin)
        ->from('/admin/users/'.$target->id)
        ->put('/students/update/'.$target->id, [
            'name' => $target->name,
            'email' => $target->email,
            'formation_id' => $formationB->id,
        ])
        ->assertRedirect();

    expect((int) $target->fresh()->formation_id)->toBe((int) $formationB->id);
});

test('mobile profile update cannot modify formation_id', function () {
    $formationA = n2Formation('Mobile Formation A');
    $formationB = n2Formation('Mobile Formation B');
    $student = n2User(['student'], [
        'email' => 'n2.mobile@example.com',
        'name' => 'N2 Mobile',
        'formation_id' => $formationA->id,
    ]);

    Auth::forgetGuards();

    $this->actingAs($student, 'sanctum')
        ->postJson('/api/mobile/profile/update', [
            'name' => 'N2 Mobile Updated',
            'formation_id' => $formationB->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'N2 Mobile Updated');

    $student->refresh();

    expect($student->name)->toBe('N2 Mobile Updated')
        ->and((int) $student->formation_id)->toBe((int) $formationA->id);
});
