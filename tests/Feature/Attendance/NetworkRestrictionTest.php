<?php

use App\Models\Attendance;
use App\Models\AttendanceListe;
use App\Models\Formation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

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
        $table->string('morning')->default('present');
        $table->string('lunch')->default('present');
        $table->string('evening')->default('present');
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

    Schema::create('notes', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('attendance_id')->nullable();
        $table->string('note');
        $table->string('author')->nullable();
        $table->timestamps();
    });

    $this->formation = Formation::create([
        'name' => 'Test Formation',
        'category' => 'coding',
        'start_time' => '2025-01-01',
        'user_id' => null,
    ]);

    $this->attendance = Attendance::create([
        'formation_id' => $this->formation->id,
        'attendance_day' => '2025-06-22',
        'staff_name' => 'Test',
    ]);
});

function createAttendanceUser(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'status' => 'Studying',
    ], $attributes));
}

function postAttendanceSave(TestCase $test, User $actor, string $remoteAddr): TestResponse
{
    return $test->actingAs($actor, 'sanctum')
        ->withServerVariables(['REMOTE_ADDR' => $remoteAddr])
        ->postJson('/api/mobile/attendance/save', [
            'attendance' => [[
                'attendance_id' => $test->attendance->id,
                'user_id' => $actor->id,
                'attendance_day' => '2025-06-22',
                'morning' => 'present',
            ]],
        ]);
}

test('student on allowed IP can save attendance', function () {
    config(['attendance.allowed_ips' => ['203.0.113.1']]);

    $student = createAttendanceUser(['role' => ['student']]);
    $countBefore = AttendanceListe::count();

    $response = postAttendanceSave($this, $student, '203.0.113.1');

    $response->assertOk()
        ->assertJson(['status' => 'ok']);
    expect(AttendanceListe::count())->toBe($countBefore + 1);
});

test('student on blocked IP cannot save attendance', function () {
    config(['attendance.allowed_ips' => ['203.0.113.1']]);

    $student = createAttendanceUser(['role' => ['student']]);
    $countBefore = AttendanceListe::count();

    $response = postAttendanceSave($this, $student, '198.51.100.99');

    $response->assertForbidden()
        ->assertJson([
            'message' => 'You must be connected to the school WiFi to check in.',
        ]);
    expect(AttendanceListe::count())->toBe($countBefore);
});

test('student with empty whitelist receives 503 and no writes', function () {
    config(['attendance.allowed_ips' => []]);

    $student = createAttendanceUser(['role' => ['student']]);
    $countBefore = AttendanceListe::count();

    $response = postAttendanceSave($this, $student, '203.0.113.1');

    $response->assertStatus(503)
        ->assertJson([
            'message' => 'Attendance network is not configured.',
        ]);
    expect(AttendanceListe::count())->toBe($countBefore);
});

test('coach off-network bypasses IP restriction', function () {
    config(['attendance.allowed_ips' => ['203.0.113.1']]);

    $coach = createAttendanceUser(['role' => ['coach']]);
    $countBefore = AttendanceListe::count();

    $response = postAttendanceSave($this, $coach, '198.51.100.99');

    $response->assertOk()
        ->assertJson(['status' => 'ok']);
    expect(AttendanceListe::count())->toBe($countBefore + 1);
});

test('coach bypasses empty whitelist', function () {
    config(['attendance.allowed_ips' => []]);

    $coach = createAttendanceUser(['role' => ['coach']]);
    $countBefore = AttendanceListe::count();

    $response = postAttendanceSave($this, $coach, '198.51.100.99');

    $response->assertOk()
        ->assertJson(['status' => 'ok']);
    expect(AttendanceListe::count())->toBe($countBefore + 1);
});

test('multi-role user with staff slug bypasses IP restriction', function () {
    config(['attendance.allowed_ips' => ['203.0.113.1']]);

    $user = createAttendanceUser(['role' => ['admin', 'coach']]);
    $countBefore = AttendanceListe::count();

    $response = postAttendanceSave($this, $user, '198.51.100.99');

    $response->assertOk()
        ->assertJson(['status' => 'ok']);
    expect(AttendanceListe::count())->toBe($countBefore + 1);
});

test('network-check returns ok for student on allowed IP', function () {
    config(['attendance.allowed_ips' => ['203.0.113.1']]);

    $student = createAttendanceUser(['role' => ['student']]);

    $this->actingAs($student, 'sanctum')
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])
        ->getJson('/api/mobile/attendance/network-check')
        ->assertOk()
        ->assertJson(['ok' => true]);
});

test('network-check returns forbidden for student off-network', function () {
    config(['attendance.allowed_ips' => ['203.0.113.1']]);

    $student = createAttendanceUser(['role' => ['student']]);

    $this->actingAs($student, 'sanctum')
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
        ->getJson('/api/mobile/attendance/network-check')
        ->assertForbidden()
        ->assertJson([
            'message' => 'You must be connected to the school WiFi to check in.',
        ]);
});

test('student on IPv6-mapped allowed IP can save attendance', function () {
    config(['attendance.allowed_ips' => ['203.0.113.1']]);

    $student = createAttendanceUser(['role' => ['student']]);
    $countBefore = AttendanceListe::count();

    $response = postAttendanceSave($this, $student, '::ffff:203.0.113.1');

    $response->assertOk()
        ->assertJson(['status' => 'ok']);
    expect(AttendanceListe::count())->toBe($countBefore + 1);
});
