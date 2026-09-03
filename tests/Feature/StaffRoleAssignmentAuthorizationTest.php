<?php

use App\Models\Formation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    $this->withoutVite();

    if (Schema::hasTable('formations') && ! Schema::hasColumn('formations', 'category')) {
        Schema::table('formations', function (Blueprint $table) {
            $table->string('category')->nullable();
            $table->string('promo')->nullable();
            $table->string('user_id')->nullable();
        });
    }
});

function n1User(array $roles, array $overrides = []): User
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

function n1Formation(): Formation
{
    $coach = n1User(['coach']);

    return Formation::query()->create([
        'name' => 'N1 Training',
        'category' => 'coding',
        'start_time' => '2026-09-01',
        'end_time' => '2026-12-01',
        'promo' => 'P1',
        'user_id' => $coach->id,
        'img' => 'default_training.jpg',
    ]);
}

function n1StorePayload(int $formationId, array $roles, string $email): array
{
    return [
        'name' => 'N1 Created User',
        'email' => $email,
        'formation_id' => $formationId,
        'access_studio' => 0,
        'access_cowork' => 0,
        'roles' => $roles,
        'image' => UploadedFile::fake()->image('avatar.jpg'),
    ];
}

test('coach cannot assign admin through student update', function () {
    $coach = n1User(['coach'], ['email' => 'n1.coach.update@example.com']);
    $target = n1User(['student'], ['email' => 'n1.target.update@example.com']);

    $this->actingAs($coach)
        ->from('/admin/users/'.$target->id)
        ->put('/students/update/'.$target->id, [
            'name' => $target->name,
            'email' => $target->email,
            'roles' => ['admin'],
        ])
        ->assertForbidden();

    expect($target->fresh()->normalizedRoles())->toBe(['student']);
});

test('coach cannot assign super_admin through student update', function () {
    $coach = n1User(['coach'], ['email' => 'n1.coach.sa@example.com']);
    $target = n1User(['student'], ['email' => 'n1.target.sa@example.com']);

    $this->actingAs($coach)
        ->from('/admin/users/'.$target->id)
        ->put('/students/update/'.$target->id, [
            'name' => $target->name,
            'email' => $target->email,
            'roles' => ['super_admin'],
        ])
        ->assertForbidden();

    expect($target->fresh()->normalizedRoles())->toBe(['student']);
});

test('studio_responsable cannot assign admin through student update', function () {
    $staff = n1User(['studio_responsable'], ['email' => 'n1.studio.update@example.com']);
    $target = n1User(['student'], ['email' => 'n1.target.studio@example.com']);

    $this->actingAs($staff)
        ->from('/admin/users/'.$target->id)
        ->put('/students/update/'.$target->id, [
            'name' => $target->name,
            'email' => $target->email,
            'roles' => ['admin'],
        ])
        ->assertForbidden();

    expect($target->fresh()->normalizedRoles())->toBe(['student']);
});

test('coach cannot assign coach through student update', function () {
    $coach = n1User(['coach'], ['email' => 'n1.coach.deny.coach@example.com']);
    $target = n1User(['student'], ['email' => 'n1.target.deny.coach@example.com']);

    $this->actingAs($coach)
        ->from('/admin/users/'.$target->id)
        ->put('/students/update/'.$target->id, [
            'name' => $target->name,
            'email' => $target->email,
            'roles' => ['coach'],
        ])
        ->assertForbidden();

    expect($target->fresh()->normalizedRoles())->toBe(['student']);
});

test('coach can still assign student and coworker through student update', function (array $roles) {
    $coach = n1User(['coach'], ['email' => 'n1.coach.ok.'.$roles[0].'@example.com']);
    $target = n1User(['student'], ['email' => 'n1.target.ok.'.$roles[0].'@example.com']);

    $this->actingAs($coach)
        ->from('/admin/users/'.$target->id)
        ->put('/students/update/'.$target->id, [
            'name' => $target->name,
            'email' => $target->email,
            'roles' => $roles,
        ])
        ->assertRedirect();

    expect($target->fresh()->normalizedRoles())->toBe($roles);
})->with([
    'student' => [['student']],
    'coworker' => [['coworker']],
]);

test('studio_responsable cannot assign moderateur through student update', function () {
    $staff = n1User(['studio_responsable'], ['email' => 'n1.studio.mod@example.com']);
    $target = n1User(['student'], ['email' => 'n1.target.mod@example.com']);

    $this->actingAs($staff)
        ->from('/admin/users/'.$target->id)
        ->put('/students/update/'.$target->id, [
            'name' => $target->name,
            'email' => $target->email,
            'roles' => ['moderateur'],
        ])
        ->assertForbidden();

    expect($target->fresh()->normalizedRoles())->toBe(['student']);
});

test('studio_responsable cannot self-escalate to recruiter', function () {
    $staff = n1User(['studio_responsable'], ['email' => 'n1.studio.self@example.com']);

    $this->actingAs($staff)
        ->from('/admin/users/'.$staff->id)
        ->put('/students/update/'.$staff->id, [
            'name' => $staff->name,
            'email' => $staff->email,
            'roles' => ['recruiter'],
        ])
        ->assertForbidden();

    expect($staff->fresh()->normalizedRoles())->toBe(['studio_responsable']);
});

test('admin can still assign coach through student update', function () {
    $admin = n1User(['admin'], ['email' => 'n1.admin.assign.coach@example.com']);
    $target = n1User(['student'], ['email' => 'n1.target.assign.coach@example.com']);

    $this->actingAs($admin)
        ->from('/admin/users/'.$target->id)
        ->put('/students/update/'.$target->id, [
            'name' => $target->name,
            'email' => $target->email,
            'roles' => ['coach'],
        ])
        ->assertRedirect();

    expect($target->fresh()->normalizedRoles())->toBe(['coach']);
});

test('non-privileged staff cannot create an admin or super_admin user', function (string $actorRole, array $requestedRoles) {
    $actor = n1User([$actorRole], ['email' => 'n1.store.actor.'.$actorRole.'.'.implode('.', $requestedRoles).'@example.com']);
    $formation = n1Formation();
    $email = 'n1.store.created.'.$actorRole.'.'.implode('.', $requestedRoles).'@example.com';

    $this->actingAs($actor)
        ->from('/admin/users')
        ->post('/admin/users/store', n1StorePayload((int) $formation->id, $requestedRoles, $email))
        ->assertForbidden();

    expect(User::query()->where('email', $email)->exists())->toBeFalse();
})->with([
    'coach-admin' => ['coach', ['admin']],
    'coach-super_admin' => ['coach', ['super_admin']],
    'moderateur-admin' => ['moderateur', ['admin']],
    'pro-admin' => ['pro', ['admin']],
    'studio_responsable-admin' => ['studio_responsable', ['admin']],
]);

test('admin can still create a user with the admin role', function () {
    $admin = n1User(['admin'], ['email' => 'n1.store.admin@example.com']);
    $formation = n1Formation();
    $email = 'n1.store.admin.created@example.com';

    $this->actingAs($admin)
        ->from('/admin/users')
        ->post('/admin/users/store', n1StorePayload((int) $formation->id, ['admin'], $email))
        ->assertRedirect();

    $created = User::query()->where('email', $email)->first();

    expect($created)->not->toBeNull()
        ->and($created->normalizedRoles())->toBe(['admin']);
});

test('coach cannot bulk-assign admin on web training', function () {
    $training = n1Formation();
    $coach = n1User(['coach'], ['email' => 'n1.bulk.coach@example.com']);
    $enrolled = n1User(['student'], [
        'email' => 'n1.bulk.enrolled@example.com',
        'formation_id' => $training->id,
    ]);

    $this->actingAs($coach)
        ->from('/trainings/'.$training->id)
        ->post('/trainings/'.$training->id.'/bulk-update-users', [
            'user_ids' => [$enrolled->id],
            'roles' => ['admin'],
        ])
        ->assertForbidden();

    expect($enrolled->fresh()->normalizedRoles())->toBe(['student']);
});

test('coach can still bulk-assign student on web training', function () {
    $training = n1Formation();
    $coach = n1User(['coach'], ['email' => 'n1.bulk.coach.ok@example.com']);
    $enrolled = n1User(['student'], [
        'email' => 'n1.bulk.enrolled.ok@example.com',
        'formation_id' => $training->id,
        'status' => 'Studying',
    ]);

    $this->actingAs($coach)
        ->from('/trainings/'.$training->id)
        ->post('/trainings/'.$training->id.'/bulk-update-users', [
            'user_ids' => [$enrolled->id],
            'roles' => ['student'],
            'status' => 'Internship',
        ])
        ->assertRedirect();

    $enrolled->refresh();
    expect($enrolled->normalizedRoles())->toBe(['student'])
        ->and($enrolled->status)->toBe('Internship');
});

test('moderateur cannot bulk-assign admin on mobile training api', function () {
    $training = n1Formation();
    $moderateur = n1User(['moderateur'], ['email' => 'n1.api.mod@example.com']);
    $enrolled = n1User(['student'], [
        'email' => 'n1.api.enrolled@example.com',
        'formation_id' => $training->id,
    ]);

    $this->actingAs($moderateur, 'sanctum')
        ->postJson('/api/mobile/trainings/'.$training->id.'/bulk-update-users', [
            'user_ids' => [$enrolled->id],
            'roles' => ['admin'],
        ])
        ->assertForbidden();

    expect($enrolled->fresh()->normalizedRoles())->toBe(['student']);
});

test('moderateur can still bulk-assign student on mobile training api', function () {
    $training = n1Formation();
    $moderateur = n1User(['moderateur'], ['email' => 'n1.api.mod.ok@example.com']);
    $enrolled = n1User(['student'], [
        'email' => 'n1.api.enrolled.ok@example.com',
        'formation_id' => $training->id,
        'status' => 'Studying',
    ]);

    $this->actingAs($moderateur, 'sanctum')
        ->postJson('/api/mobile/trainings/'.$training->id.'/bulk-update-users', [
            'user_ids' => [$enrolled->id],
            'roles' => ['student'],
            'status' => 'Internship',
        ])
        ->assertOk();

    $enrolled->refresh();
    expect($enrolled->normalizedRoles())->toBe(['student'])
        ->and($enrolled->status)->toBe('Internship');
});
