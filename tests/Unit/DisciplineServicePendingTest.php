<?php

use App\Models\Attendance;
use App\Models\AttendanceListe;
use App\Models\Formation;
use App\Models\User;
use App\Services\DisciplineService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::dropAllTables();

    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->string('remember_token')->nullable();
        $table->json('role')->nullable();
        $table->string('status')->default('Studying');
        $table->integer('formation_id')->nullable();
        $table->timestamps();
    });

    Schema::create('formations', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('img')->default('default_training.jpg');
        $table->string('category')->nullable();
        $table->string('start_time')->nullable();
        $table->string('end_time')->nullable();
        $table->integer('user_id')->nullable();
        $table->string('promo')->nullable();
        $table->boolean('is_active')->default(false);
        $table->timestamps();
    });

    Schema::create('attendances', function (Blueprint $table) {
        $table->id();
        $table->integer('formation_id');
        $table->string('attendance_day');
        $table->string('staff_name');
        $table->timestamps();
    });

    Schema::create('attendance_lists', function (Blueprint $table) {
        $table->id();
        $table->integer('user_id');
        $table->integer('attendance_id');
        $table->string('attendance_day');
        $table->string('morning')->nullable();
        $table->string('lunch')->nullable();
        $table->string('evening')->nullable();
        $table->timestamps();
    });

    Schema::create('discipline_notifications', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('message_notification')->nullable();
        $table->decimal('discipline_change', 5, 2)->nullable();
        $table->string('path')->nullable();
        $table->string('type')->nullable();
        $table->timestamps();
    });
});

test('pending future slots do not reduce discipline score', function () {
    $formation = Formation::create([
        'name' => 'Coding',
        'category' => 'coding',
        'start_time' => now()->subDays(10)->toDateString(),
        'end_time' => now()->addDays(10)->toDateString(),
    ]);

    $user = User::factory()->create([
        'role' => ['student'],
        'formation_id' => $formation->id,
    ]);

    $attendance = Attendance::create([
        'formation_id' => $formation->id,
        'attendance_day' => now()->toDateString(),
        'staff_name' => 'System',
    ]);

    AttendanceListe::create([
        'user_id' => $user->id,
        'attendance_id' => $attendance->id,
        'attendance_day' => now()->toDateString(),
        'morning' => 'present',
        'lunch' => 'pending',
        'evening' => 'pending',
    ]);

    $service = new DisciplineService;
    $lost = $service->countAbsentSlots($user);

    expect($lost)->toBe(0.0);
});

test('absent slots reduce discipline score while pending does not', function () {
    $formation = Formation::create([
        'name' => 'Coding',
        'category' => 'coding',
        'start_time' => now()->subDays(10)->toDateString(),
        'end_time' => now()->addDays(10)->toDateString(),
    ]);

    $user = User::factory()->create([
        'role' => ['student'],
        'formation_id' => $formation->id,
    ]);

    $attendance = Attendance::create([
        'formation_id' => $formation->id,
        'attendance_day' => now()->toDateString(),
        'staff_name' => 'System',
    ]);

    AttendanceListe::create([
        'user_id' => $user->id,
        'attendance_id' => $attendance->id,
        'attendance_day' => now()->toDateString(),
        'morning' => 'absent',
        'lunch' => 'pending',
        'evening' => 'pending',
    ]);

    $service = new DisciplineService;

    expect($service->countAbsentSlots($user))->toBe(1.0);
});
