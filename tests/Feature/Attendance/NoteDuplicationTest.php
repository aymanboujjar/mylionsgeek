<?php

use App\Models\Attendance;
use App\Models\AttendanceListe;
use App\Models\Formation;
use App\Models\Note;
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

    Schema::create('notes', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('attendance_id')->nullable();
        $table->string('note');
        $table->string('author')->nullable();
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

    Schema::create('user_social_links', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('title');
        $table->string('url');
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

    $this->student = User::factory()->create([
        'role' => ['student'],
        'status' => 'Studying',
    ]);
});

function postMobileAttendanceSave(TestCase $test, User $actor, array $payloadOverrides = []): TestResponse
{
    return $test->actingAs($actor, 'sanctum')
        ->postJson('/api/mobile/attendance/save', [
            'attendance' => [array_merge([
                'attendance_id' => $test->attendance->id,
                'user_id' => $test->student->id,
                'attendance_day' => '2025-06-22',
                'morning' => 'present',
            ], $payloadOverrides)],
        ]);
}

function postAdminAttendanceSave(TestCase $test, User $actor, array $payloadOverrides = []): TestResponse
{
    return $test->withoutMiddleware([
        \App\Http\Middleware\HandleInertiaRequests::class,
        \App\Http\Middleware\EnsureOrganisationOnboarded::class,
    ])
        ->actingAs($actor)
        ->postJson('/admin/attendance/save', [
            'attendance' => [array_merge([
                'attendance_id' => $test->attendance->id,
                'user_id' => $test->student->id,
                'attendance_day' => '2025-06-22',
                'morning' => 'present',
            ], $payloadOverrides)],
        ]);
}

function notesForPair(int $userId, int $attendanceId)
{
    return Note::query()
        ->where('user_id', $userId)
        ->where('attendance_id', $attendanceId)
        ->orderBy('id')
        ->pluck('note')
        ->all();
}

test('mobile save does not duplicate notes when the same list is saved twice', function () {
    $coach = User::factory()->create(['role' => ['coach'], 'status' => 'Studying']);

    postMobileAttendanceSave($this, $coach, ['note' => 'QR Code scan at 18:29'])
        ->assertOk();

    expect(notesForPair($this->student->id, $this->attendance->id))->toBe(['QR Code scan at 18:29']);

    postMobileAttendanceSave($this, $coach, ['note' => 'QR Code scan at 18:29'])
        ->assertOk();

    expect(notesForPair($this->student->id, $this->attendance->id))->toBe(['QR Code scan at 18:29']);
});

test('mobile save adds a new note without duplicating existing ones', function () {
    $coach = User::factory()->create(['role' => ['coach'], 'status' => 'Studying']);

    postMobileAttendanceSave($this, $coach, ['note' => 'QR Code scan at 18:29'])
        ->assertOk();

    postMobileAttendanceSave($this, $coach, ['note' => 'QR Code scan at 18:29 | QR Code scan at 18:30'])
        ->assertOk();

    expect(notesForPair($this->student->id, $this->attendance->id))->toBe([
        'QR Code scan at 18:29',
        'QR Code scan at 18:30',
    ]);
});

test('mobile save clears notes when an empty note payload is submitted', function () {
    $coach = User::factory()->create(['role' => ['coach'], 'status' => 'Studying']);

    postMobileAttendanceSave($this, $coach, ['note' => 'QR Code scan at 18:29'])
        ->assertOk();

    postMobileAttendanceSave($this, $coach, ['note' => null])
        ->assertOk();

    expect(Note::count())->toBe(0);
});

test('admin save does not duplicate notes when the same list is saved twice', function () {
    $admin = User::factory()->create(['role' => ['admin'], 'status' => 'Studying']);

    postAdminAttendanceSave($this, $admin, ['note' => 'Manual note'])
        ->assertOk();

    postAdminAttendanceSave($this, $admin, ['note' => 'Manual note'])
        ->assertOk();

    expect(notesForPair($this->student->id, $this->attendance->id))->toBe(['Manual note']);
});

test('admin save clears notes when notes are omitted', function () {
    $admin = User::factory()->create(['role' => ['admin'], 'status' => 'Studying']);

    postAdminAttendanceSave($this, $admin, ['note' => 'Manual note'])
        ->assertOk();

    postAdminAttendanceSave($this, $admin)
        ->assertOk();

    expect(Note::count())->toBe(0);
});
