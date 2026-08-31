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
    expect($user->fresh()->program_status)->toBe(User::PROGRAM_STATUS_ACTIVE);
});

it('should_set_active_when_program_status_is_an_empty_string', function () {
    $user = makeUser(['program_status' => '']);

    $written = $this->service->markActiveOnEnrollment($user);

    expect($written)->toBeTrue();
    expect($user->fresh()->program_status)->toBe(User::PROGRAM_STATUS_ACTIVE);
});

it('should_not_overwrite_left_when_re_enrolling_a_former_student', function () {
    $user = makeUser(['program_status' => User::PROGRAM_STATUS_LEFT]);

    $written = $this->service->markActiveOnEnrollment($user);

    expect($written)->toBeFalse();
    expect($user->fresh()->program_status)->toBe(User::PROGRAM_STATUS_LEFT);
});

it('should_not_overwrite_completed_when_re_enrolling_a_former_student', function () {
    $user = makeUser(['program_status' => User::PROGRAM_STATUS_COMPLETED]);

    $written = $this->service->markActiveOnEnrollment($user);

    expect($written)->toBeFalse();
    expect($user->fresh()->program_status)->toBe(User::PROGRAM_STATUS_COMPLETED);
});

it('should_not_overwrite_laureate_when_re_enrolling_a_former_student', function () {
    $user = makeUser(['program_status' => User::PROGRAM_STATUS_LAUREATE]);

    $written = $this->service->markActiveOnEnrollment($user);

    expect($written)->toBeFalse();
    expect($user->fresh()->program_status)->toBe(User::PROGRAM_STATUS_LAUREATE);
});

it('should_return_active_as_the_initial_status_for_a_user_created_with_a_training', function () {
    expect($this->service->initialProgramStatusFor(7))->toBe(User::PROGRAM_STATUS_ACTIVE);
});

it('should_return_null_as_the_initial_status_for_a_user_created_without_a_training', function () {
    expect($this->service->initialProgramStatusFor(null))->toBeNull();
});

it('should_mark_selected_students_as_laureate', function () {
    $first = makeUser(['program_status' => User::PROGRAM_STATUS_ACTIVE]);
    $second = makeUser(['program_status' => User::PROGRAM_STATUS_ACTIVE]);

    $updated = $this->service->markLaureates([$first->id, $second->id]);

    expect($updated)->toBe(2);
    expect($first->fresh()->program_status)->toBe(User::PROGRAM_STATUS_LAUREATE);
    expect($second->fresh()->program_status)->toBe(User::PROGRAM_STATUS_LAUREATE);
});

it('should_mark_a_student_with_no_program_status_as_laureate', function () {
    $user = makeUser(['program_status' => null]);

    $updated = $this->service->markLaureates([$user->id]);

    expect($updated)->toBe(1);
    expect($user->fresh()->program_status)->toBe(User::PROGRAM_STATUS_LAUREATE);
});

it('should_never_mark_a_student_who_left_as_laureate', function () {
    $whoLeft = makeUser(['program_status' => User::PROGRAM_STATUS_LEFT]);
    $active = makeUser(['program_status' => User::PROGRAM_STATUS_ACTIVE]);

    $updated = $this->service->markLaureates([$whoLeft->id, $active->id]);

    expect($updated)->toBe(1);
    expect($whoLeft->fresh()->program_status)->toBe(User::PROGRAM_STATUS_LEFT);
    expect($active->fresh()->program_status)->toBe(User::PROGRAM_STATUS_LAUREATE);
});

it('should_do_nothing_when_no_students_are_selected', function () {
    expect($this->service->markLaureates([]))->toBe(0);
});

it('should_not_touch_students_who_were_not_selected', function () {
    $selected = makeUser(['program_status' => User::PROGRAM_STATUS_ACTIVE]);
    $untouched = makeUser(['program_status' => User::PROGRAM_STATUS_ACTIVE]);

    $this->service->markLaureates([$selected->id]);

    expect($untouched->fresh()->program_status)->toBe(User::PROGRAM_STATUS_ACTIVE);
});
