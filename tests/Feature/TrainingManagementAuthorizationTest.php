<?php

use App\Models\Formation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    if (Schema::hasTable('formations') && ! Schema::hasColumn('formations', 'category')) {
        Schema::table('formations', function (Blueprint $table) {
            $table->string('category')->nullable();
            $table->string('promo')->nullable();
            $table->string('user_id')->nullable();
        });
    }
});

function h1User(array $roles, array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => $roles,
        'status' => 'Studying',
        'email_verified_at' => now(),
    ], $overrides));
}

function h1Formation(array $overrides = []): Formation
{
    $coach = $overrides['user_id'] ?? h1User(['coach'])->id;

    return Formation::query()->create(array_merge([
        'name' => 'Coding Bootcamp',
        'category' => 'coding',
        'start_time' => '2026-09-01',
        'end_time' => '2026-12-01',
        'promo' => 'P1',
        'user_id' => $coach,
        'img' => 'default_training.jpg',
    ], $overrides));
}

function h1CreatePayload(int $coachId, array $overrides = []): array
{
    return array_merge([
        'name' => 'New Training',
        'category' => 'media',
        'start_time' => '2026-10-01',
        'user_id' => $coachId,
        'promo' => 'P2',
    ], $overrides);
}

function h1UpdatePayload(Formation $training, array $overrides = []): array
{
    return array_merge([
        'name' => 'Renamed Training',
        'category' => $training->category ?? 'coding',
        'start_time' => '2026-11-01',
        'user_id' => $training->user_id,
        'promo' => $training->promo,
    ], $overrides);
}

test('anonymous cannot manage or view trainings', function () {
    $training = h1Formation();

    $this->postJson('/api/mobile/trainings', h1CreatePayload((int) $training->user_id))
        ->assertUnauthorized();
    $this->putJson('/api/mobile/trainings/'.$training->id, h1UpdatePayload($training))
        ->assertUnauthorized();
    $this->deleteJson('/api/mobile/trainings/'.$training->id)
        ->assertUnauthorized();
    $this->postJson('/api/mobile/trainings/'.$training->id.'/students', ['student_id' => 1])
        ->assertUnauthorized();
    $this->deleteJson('/api/mobile/trainings/'.$training->id.'/students/1')
        ->assertUnauthorized();
    $this->postJson('/api/mobile/trainings/'.$training->id.'/bulk-update-users', [
        'user_ids' => [1],
        'status' => 'Working',
    ])->assertUnauthorized();
    $this->getJson('/api/mobile/trainings/'.$training->id)
        ->assertUnauthorized();

    expect(Formation::query()->whereKey($training->id)->exists())->toBeTrue();
});

test('student cannot create a training', function () {
    $student = h1User(['student']);
    $coach = h1User(['coach']);
    $before = Formation::query()->count();

    $this->actingAs($student, 'sanctum')
        ->postJson('/api/mobile/trainings', h1CreatePayload($coach->id))
        ->assertForbidden();

    expect(Formation::query()->count())->toBe($before);
});

test('student cannot update a training', function () {
    $training = h1Formation(['name' => 'Original Name']);
    $student = h1User(['student'], ['formation_id' => $training->id]);

    $this->actingAs($student, 'sanctum')
        ->putJson('/api/mobile/trainings/'.$training->id, h1UpdatePayload($training))
        ->assertForbidden();

    expect($training->fresh()->name)->toBe('Original Name');
});

test('student cannot delete a training', function () {
    $training = h1Formation();
    $student = h1User(['student'], ['formation_id' => $training->id]);

    $this->actingAs($student, 'sanctum')
        ->deleteJson('/api/mobile/trainings/'.$training->id)
        ->assertForbidden();

    expect(Formation::query()->whereKey($training->id)->exists())->toBeTrue();
});

test('student cannot add another user to a training', function () {
    $training = h1Formation();
    $student = h1User(['student'], ['formation_id' => $training->id]);
    $target = h1User(['student'], ['formation_id' => null]);

    $this->actingAs($student, 'sanctum')
        ->postJson('/api/mobile/trainings/'.$training->id.'/students', [
            'student_id' => $target->id,
        ])
        ->assertForbidden();

    expect($target->fresh()->formation_id)->toBeNull();
});

test('student cannot remove another user from a training', function () {
    $training = h1Formation();
    $student = h1User(['student'], ['formation_id' => $training->id]);
    $classmate = h1User(['student'], ['formation_id' => $training->id]);

    $this->actingAs($student, 'sanctum')
        ->deleteJson('/api/mobile/trainings/'.$training->id.'/students/'.$classmate->id)
        ->assertForbidden();

    expect((int) $classmate->fresh()->formation_id)->toBe((int) $training->id);
});

test('student cannot bulk-update role or status', function () {
    $training = h1Formation();
    $student = h1User(['student'], ['formation_id' => $training->id, 'status' => 'Studying']);
    $classmate = h1User(['student'], [
        'formation_id' => $training->id,
        'status' => 'Studying',
        'role' => ['student'],
    ]);

    $this->actingAs($student, 'sanctum')
        ->postJson('/api/mobile/trainings/'.$training->id.'/bulk-update-users', [
            'user_ids' => [$classmate->id],
            'roles' => ['admin'],
            'status' => 'Working',
        ])
        ->assertForbidden();

    $classmate->refresh();
    expect($classmate->normalizedRoles())->toBe(['student'])
        ->and($classmate->status)->toBe('Studying');
});

test('student cannot view another formation', function () {
    $own = h1Formation(['name' => 'Own Training']);
    $other = h1Formation(['name' => 'Other Training']);
    $student = h1User(['student'], ['formation_id' => $own->id]);

    $this->actingAs($student, 'sanctum')
        ->getJson('/api/mobile/trainings/'.$other->id)
        ->assertForbidden()
        ->assertJsonMissingPath('usersNull');
});

test('student can view own formation without usersNull', function () {
    $training = h1Formation(['name' => 'Own Training']);
    $unassigned = h1User(['student'], ['formation_id' => null, 'email' => 'unassigned.h1@example.com']);
    $student = h1User(['student'], ['formation_id' => $training->id]);

    $response = $this->actingAs($student, 'sanctum')
        ->getJson('/api/mobile/trainings/'.$training->id)
        ->assertOk()
        ->assertJsonPath('training.id', $training->id)
        ->assertJsonPath('training.name', 'Own Training')
        ->assertJsonMissingPath('usersNull');

    expect(json_encode($response->json()))->not->toContain($unassigned->email);
});

test('student enrolled listing remains scoped to own formations', function () {
    $own = h1Formation(['name' => 'Enrolled Training']);
    $other = h1Formation(['name' => 'Hidden Training']);
    $student = h1User(['student'], ['formation_id' => $own->id]);

    $this->actingAs($student, 'sanctum')
        ->getJson('/api/mobile/trainings/enrolled')
        ->assertOk()
        ->assertJsonPath('formation_id', $own->id)
        ->assertJsonFragment(['id' => $own->id, 'name' => 'Enrolled Training'])
        ->assertJsonMissing(['id' => $other->id, 'name' => 'Hidden Training']);
});

test('studio_responsable cannot manage trainings', function () {
    $training = h1Formation(['name' => 'Studio Blocked']);
    $studio = h1User(['studio_responsable']);
    $target = h1User(['student'], ['formation_id' => null]);
    $before = Formation::query()->count();

    $this->actingAs($studio, 'sanctum')
        ->postJson('/api/mobile/trainings', h1CreatePayload((int) $training->user_id))
        ->assertForbidden();
    $this->actingAs($studio, 'sanctum')
        ->putJson('/api/mobile/trainings/'.$training->id, h1UpdatePayload($training))
        ->assertForbidden();
    $this->actingAs($studio, 'sanctum')
        ->deleteJson('/api/mobile/trainings/'.$training->id)
        ->assertForbidden();
    $this->actingAs($studio, 'sanctum')
        ->postJson('/api/mobile/trainings/'.$training->id.'/students', ['student_id' => $target->id])
        ->assertForbidden();

    expect(Formation::query()->count())->toBe($before)
        ->and($training->fresh()->name)->toBe('Studio Blocked')
        ->and($target->fresh()->formation_id)->toBeNull();
});

test('admin can create a training', function () {
    $admin = h1User(['admin']);
    $coach = h1User(['coach']);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/mobile/trainings', h1CreatePayload($coach->id, ['name' => 'Admin Created']))
        ->assertCreated()
        ->assertJsonPath('success', true);

    expect(Formation::query()->where('name', 'Admin Created')->exists())->toBeTrue();
});

test('coach can update any training without ownership restriction', function () {
    $assignedCoach = h1User(['coach']);
    $otherCoach = h1User(['coach']);
    $training = h1Formation([
        'name' => 'Coach Target',
        'user_id' => $assignedCoach->id,
    ]);

    $this->actingAs($otherCoach, 'sanctum')
        ->putJson('/api/mobile/trainings/'.$training->id, h1UpdatePayload($training, [
            'name' => 'Updated By Other Coach',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($training->fresh()->name)->toBe('Updated By Other Coach');
});

test('super_admin can enroll a user', function () {
    $training = h1Formation();
    $superAdmin = h1User(['super_admin']);
    $target = h1User(['student'], ['formation_id' => null]);

    $this->actingAs($superAdmin, 'sanctum')
        ->postJson('/api/mobile/trainings/'.$training->id.'/students', [
            'student_id' => $target->id,
        ])
        ->assertOk();

    expect((int) $target->fresh()->formation_id)->toBe((int) $training->id);
});

test('moderateur can bulk-update status and role', function () {
    $training = h1Formation();
    $moderateur = h1User(['moderateur']);
    $enrolled = h1User(['student'], [
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
    expect($enrolled->status)->toBe('Internship')
        ->and($enrolled->normalizedRoles())->toBe(['student']);
});

test('staff show includes usersNull and can view any training', function () {
    $training = h1Formation();
    $unassigned = h1User(['student'], ['formation_id' => null, 'email' => 'free.slot@example.com']);
    $admin = h1User(['admin']);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/mobile/trainings/'.$training->id)
        ->assertOk()
        ->assertJsonPath('training.id', $training->id)
        ->assertJsonFragment(['email' => $unassigned->email]);
});
