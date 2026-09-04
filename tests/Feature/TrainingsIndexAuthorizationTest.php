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

function trainingsPiiUser(array $roles, array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => $roles,
        'status' => 'Studying',
        'email_verified_at' => now(),
        'access_cowork' => 0,
        'access_studio' => 0,
    ], $overrides));
}

function trainingsPiiFormation(array $overrides = []): Formation
{
    $coachId = $overrides['user_id'] ?? trainingsPiiUser(['coach'], [
        'email' => 'coach.'.uniqid('', true).'@example.com',
    ])->id;

    return Formation::query()->create(array_merge([
        'name' => 'Coding Bootcamp',
        'category' => 'coding',
        'start_time' => '2026-09-01',
        'end_time' => '2026-12-01',
        'promo' => 'P1',
        'user_id' => $coachId,
        'img' => 'default_training.jpg',
    ], $overrides));
}

test('student trainings index is scoped to enrollment and omits coach emails', function () {
    $own = trainingsPiiFormation(['name' => 'Own Formation']);
    $other = trainingsPiiFormation(['name' => 'Other Formation']);
    $coachEmail = User::query()->find($own->user_id)?->email;
    $student = trainingsPiiUser(['student'], [
        'formation_id' => $own->id,
        'email' => 'student.trainings.pii@example.com',
    ]);

    $response = $this->actingAs($student, 'sanctum')
        ->getJson('/api/mobile/trainings')
        ->assertOk()
        ->assertJsonPath('filters.mine', true)
        ->assertJsonPath('coaches', [])
        ->assertJsonFragment(['id' => $own->id, 'name' => 'Own Formation'])
        ->assertJsonMissing(['id' => $other->id, 'name' => 'Other Formation']);

    $json = json_encode($response->json());
    expect($json)->not->toContain($coachEmail)
        ->and($response->json('trainings.0.coach'))->not->toHaveKey('email');
});

test('coach trainings index still includes coach emails and full catalog', function () {
    $a = trainingsPiiFormation(['name' => 'Formation A']);
    $b = trainingsPiiFormation(['name' => 'Formation B']);
    $coach = trainingsPiiUser(['coach'], ['email' => 'staff.coach@example.com']);

    $response = $this->actingAs($coach, 'sanctum')
        ->getJson('/api/mobile/trainings')
        ->assertOk();

    $ids = collect($response->json('trainings'))->pluck('id')->all();
    expect($ids)->toContain($a->id)->toContain($b->id)
        ->and($response->json('coaches'))->not->toBeEmpty()
        ->and($response->json('trainings.0.coach'))->toHaveKey('email');
});
