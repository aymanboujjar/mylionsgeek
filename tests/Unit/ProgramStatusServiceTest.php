<?php

use App\Models\User;
use App\Services\ProgramStatusService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::dropAllTables();

    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->string('password')->nullable();
        $table->json('role')->nullable();
        $table->string('status')->nullable();
        $table->string('program_status')->nullable();
        $table->integer('formation_id')->nullable();
        $table->timestamps();
    });

    $this->service = new ProgramStatusService;
});

function makeUser(array $attributes = []): User
{
    return User::create(array_merge([
        'name' => 'Test Student',
        'email' => 'student'.uniqid().'@example.com',
        'password' => 'secret',
    ], $attributes));
}

it('should_set_active_when_program_status_is_empty', function () {
    $user = makeUser(['program_status' => null]);

    $written = $this->service->markActiveOnEnrollment($user);

    expect($written)->toBeTrue();
    expect($user->fresh()->program_status)->toBe(ProgramStatusService::ACTIVE);
});

it('should_set_active_when_program_status_is_an_empty_string', function () {
    $user = makeUser(['program_status' => '']);

    $written = $this->service->markActiveOnEnrollment($user);

    expect($written)->toBeTrue();
    expect($user->fresh()->program_status)->toBe(ProgramStatusService::ACTIVE);
});

it('should_not_overwrite_left_when_re_enrolling_a_former_student', function () {
    $user = makeUser(['program_status' => ProgramStatusService::LEFT]);

    $written = $this->service->markActiveOnEnrollment($user);

    expect($written)->toBeFalse();
    expect($user->fresh()->program_status)->toBe(ProgramStatusService::LEFT);
});

it('should_not_overwrite_not_certified_when_re_enrolling_a_former_student', function () {
    $user = makeUser(['program_status' => ProgramStatusService::NOT_CERTIFIED]);

    $written = $this->service->markActiveOnEnrollment($user);

    expect($written)->toBeFalse();
    expect($user->fresh()->program_status)->toBe(ProgramStatusService::NOT_CERTIFIED);
});

it('should_not_overwrite_certified_when_re_enrolling_a_former_student', function () {
    $user = makeUser(['program_status' => ProgramStatusService::CERTIFIED]);

    $written = $this->service->markActiveOnEnrollment($user);

    expect($written)->toBeFalse();
    expect($user->fresh()->program_status)->toBe(ProgramStatusService::CERTIFIED);
});

it('should_apply_enrollment_status_without_overwriting_existing_value', function () {
    $user = makeUser(['program_status' => ProgramStatusService::LEFT]);

    $this->service->applyEnrollmentStatus($user);

    expect($user->program_status)->toBe(ProgramStatusService::LEFT);
});

it('should_return_active_as_the_initial_status_for_a_user_created_with_a_training', function () {
    expect($this->service->initialProgramStatusFor(7))->toBe(ProgramStatusService::ACTIVE);
});

it('should_return_null_as_the_initial_status_for_a_user_created_without_a_training', function () {
    expect($this->service->initialProgramStatusFor(null))->toBeNull();
});

it('should_mark_unselected_active_students_as_not_certified', function () {
    $training = new \App\Models\Formation;
    $training->id = 1;

    $certified = makeUser(['formation_id' => 1, 'program_status' => ProgramStatusService::ACTIVE]);
    $unselected = makeUser(['formation_id' => 1, 'program_status' => ProgramStatusService::ACTIVE]);

    $this->service->markUnselectedActiveStudentsAsNotCertified($training, [$certified->id]);

    expect($unselected->fresh()->program_status)->toBe(ProgramStatusService::NOT_CERTIFIED);
    expect($certified->fresh()->program_status)->toBe(ProgramStatusService::ACTIVE);
});

it('should_not_change_program_status_when_student_has_left', function () {
    $training = new \App\Models\Formation;
    $training->id = 1;
    $whoLeft = makeUser(['formation_id' => 1, 'program_status' => ProgramStatusService::LEFT]);

    $this->service->markUnselectedActiveStudentsAsNotCertified($training, []);

    expect($whoLeft->fresh()->program_status)->toBe(ProgramStatusService::LEFT);
});

it('should_not_change_program_status_when_student_is_already_certified', function () {
    $training = new \App\Models\Formation;
    $training->id = 1;
    $certified = makeUser(['formation_id' => 1, 'program_status' => ProgramStatusService::CERTIFIED]);

    $this->service->markUnselectedActiveStudentsAsNotCertified($training, []);

    expect($certified->fresh()->program_status)->toBe(ProgramStatusService::CERTIFIED);
});
