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
        $table->string('morning')->nullable();
        $table->string('lunch')->nullable();
        $table->string('evening')->nullable();
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

    $this->formation = Formation::create([
        'name' => 'Test Formation',
        'category' => 'coding',
        'start_time' => '2025-01-01',
        'user_id' => null,
    ]);

    $this->student = User::factory()->create([
        'role' => ['student'],
        'status' => 'Studying',
        'formation_id' => $this->formation->id,
    ]);

    $this->coach = User::factory()->create([
        'role' => ['coach'],
        'status' => 'Studying',
    ]);
});

function postCoachCalendarOpen(TestCase $test, string $day = '2025-06-22'): TestResponse
{
    return $test->withoutMiddleware([
        \App\Http\Middleware\HandleInertiaRequests::class,
        \App\Http\Middleware\EnsureOrganisationOnboarded::class,
    ])
        ->actingAs($test->coach)
        ->postJson('/attendances', [
            'formation_id' => $test->formation->id,
            'attendance_day' => $day,
        ]);
}

function postCoachCalendarSave(TestCase $test, array $attendanceRows, ?int $attendanceId = null): TestResponse
{
    $rows = array_map(function (array $row) use ($attendanceId) {
        return array_merge([
            'attendance_id' => $attendanceId,
            'user_id' => test()->student->id,
            'attendance_day' => '2025-06-22',
            'morning' => 'present',
            'lunch' => 'present',
            'evening' => 'present',
        ], $row);
    }, $attendanceRows);

    return $test->withoutMiddleware([
        \App\Http\Middleware\HandleInertiaRequests::class,
        \App\Http\Middleware\EnsureOrganisationOnboarded::class,
    ])
        ->actingAs($test->coach)
        ->postJson('/admin/attendance/save', [
            'formation_id' => test()->formation->id,
            'attendance' => $rows,
        ]);
}

test('opening a day with no records writes nothing to the database', function () {
    expect(Attendance::count())->toBe(0);
    expect(AttendanceListe::count())->toBe(0);

    postCoachCalendarOpen($this)
        ->assertOk()
        ->assertJson([
            'attendance_id' => null,
            'lists' => [],
        ]);

    expect(Attendance::count())->toBe(0);
    expect(AttendanceListe::count())->toBe(0);
});

test('coach save writes toggle values for slots without a student check-in note', function () {
    postCoachCalendarSave($this, [[
        'morning' => 'present',
        'lunch' => 'absent',
        'evening' => 'late',
    ]])->assertOk();

    $row = AttendanceListe::first();
    expect($row->morning)->toBe('present');
    expect($row->lunch)->toBe('absent');
    expect($row->evening)->toBe('late');
});

test('coach save preserves a slot with a student check-in note even when coach sends present', function () {
    $attendance = Attendance::create([
        'formation_id' => $this->formation->id,
        'attendance_day' => '2025-06-22',
        'staff_name' => 'Student',
    ]);

    AttendanceListe::create([
        'user_id' => $this->student->id,
        'attendance_id' => $attendance->id,
        'attendance_day' => '2025-06-22',
        'morning' => 'late',
        'lunch' => 'absent',
        'evening' => 'absent',
    ]);

    Note::create([
        'user_id' => $this->student->id,
        'attendance_id' => $attendance->id,
        'note' => 'Check-in at 09:46',
        'author' => $this->student->name,
    ]);

    postCoachCalendarSave($this, [[
        'morning' => 'present',
        'lunch' => 'present',
        'evening' => 'present',
    ]], $attendance->id)->assertOk();

    $row = AttendanceListe::first();
    expect($row->morning)->toBe('late');
    expect($row->lunch)->toBe('present');
    expect($row->evening)->toBe('present');
});

test('admin save defaults omitted fields to absent', function () {
    $attendance = Attendance::create([
        'formation_id' => $this->formation->id,
        'attendance_day' => '2025-06-22',
        'staff_name' => 'Coach',
    ]);

    postCoachCalendarSave($this, [[
        'morning' => 'present',
        'lunch' => null,
        'evening' => null,
    ]], $attendance->id)->assertOk();

    $row = AttendanceListe::first();
    expect($row->morning)->toBe('present');
    expect($row->lunch)->toBe('absent');
    expect($row->evening)->toBe('absent');
});

test('coach save after morning student check-in preserves morning and applies coach values to other slots', function () {
    $attendance = Attendance::create([
        'formation_id' => $this->formation->id,
        'attendance_day' => '2025-06-22',
        'staff_name' => 'Student',
    ]);

    AttendanceListe::create([
        'user_id' => $this->student->id,
        'attendance_id' => $attendance->id,
        'attendance_day' => '2025-06-22',
        'morning' => 'present',
        'lunch' => 'absent',
        'evening' => 'absent',
    ]);

    Note::create([
        'user_id' => $this->student->id,
        'attendance_id' => $attendance->id,
        'note' => 'Check-in at 09:42',
        'author' => $this->student->name,
    ]);

    postCoachCalendarSave($this, [[
        'morning' => 'present',
        'lunch' => 'present',
        'evening' => 'present',
    ]], $attendance->id)->assertOk();

    $row = AttendanceListe::first();
    expect($row->morning)->toBe('present');
    expect($row->lunch)->toBe('present');
    expect($row->evening)->toBe('present');
});

test('calendar open returns student marked slots for flagged rows', function () {
    $attendance = Attendance::create([
        'formation_id' => $this->formation->id,
        'attendance_day' => '2025-06-22',
        'staff_name' => 'Student',
    ]);

    AttendanceListe::create([
        'user_id' => $this->student->id,
        'attendance_id' => $attendance->id,
        'attendance_day' => '2025-06-22',
        'morning' => 'late',
        'lunch' => 'absent',
        'evening' => 'absent',
    ]);

    Note::create([
        'user_id' => $this->student->id,
        'attendance_id' => $attendance->id,
        'note' => 'Check-in at 09:46',
        'author' => $this->student->name,
    ]);

    postCoachCalendarOpen($this)
        ->assertOk()
        ->assertJsonPath('lists.0.student_marked_slots', ['morning']);
});

test('legacy all-present rows without check-in notes remain coach-editable on save', function () {
    $attendance = Attendance::create([
        'formation_id' => $this->formation->id,
        'attendance_day' => '2025-06-22',
        'staff_name' => 'Legacy Coach',
    ]);

    AttendanceListe::create([
        'user_id' => $this->student->id,
        'attendance_id' => $attendance->id,
        'attendance_day' => '2025-06-22',
        'morning' => 'present',
        'lunch' => 'present',
        'evening' => 'present',
    ]);

    postCoachCalendarSave($this, [[
        'morning' => 'absent',
        'lunch' => 'absent',
        'evening' => 'absent',
    ]], $attendance->id)->assertOk();

    $row = AttendanceListe::first();
    expect($row->morning)->toBe('absent');
    expect($row->lunch)->toBe('absent');
    expect($row->evening)->toBe('absent');
});
