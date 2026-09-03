<?php

use App\Models\Attendance;
use App\Models\AttendanceListe;
use App\Models\DisciplineNotification;
use App\Models\Formation;
use App\Models\Note;
use App\Models\User;
use Carbon\Carbon;
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

    $this->ownFormation = Formation::create([
        'name' => 'Own Formation',
        'category' => 'coding',
        'start_time' => '2025-01-01',
        'user_id' => null,
    ]);

    $this->otherFormation = Formation::create([
        'name' => 'Other Formation',
        'category' => 'media',
        'start_time' => '2025-01-01',
        'user_id' => null,
    ]);

    $this->otherAttendance = Attendance::create([
        'formation_id' => $this->otherFormation->id,
        'attendance_day' => '2025-06-22',
        'staff_name' => 'Coach',
    ]);

    config(['attendance.allowed_ips' => ['203.0.113.1']]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function h2User(array $roles, array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => $roles,
        'status' => 'Studying',
        'formation_id' => test()->ownFormation->id,
    ], $overrides));
}

function h2SavePayload(array $overrides = []): array
{
    return [
        'attendance' => [array_merge([
            'attendance_id' => test()->otherAttendance->id,
            'user_id' => h2User(['student'], ['formation_id' => test()->otherFormation->id])->id,
            'attendance_day' => '2025-06-22',
            'morning' => 'present',
            'lunch' => 'present',
            'evening' => 'present',
            'note' => 'forged',
        ], $overrides)],
    ];
}

function h2PostSave(TestCase $test, User $actor, array $payload, string $remoteAddr = '203.0.113.1'): TestResponse
{
    return $test->actingAs($actor, 'sanctum')
        ->withServerVariables(['REMOTE_ADDR' => $remoteAddr])
        ->postJson('/api/mobile/attendance/save', $payload);
}

function h2AssertNoStaffWrites(int $attendanceCount, int $listCount, int $noteCount, int $disciplineCount): void
{
    expect(Attendance::count())->toBe($attendanceCount)
        ->and(AttendanceListe::count())->toBe($listCount)
        ->and(Note::count())->toBe($noteCount)
        ->and(DisciplineNotification::count())->toBe($disciplineCount);
}

test('anonymous cannot save attendance', function () {
    $this->postJson('/api/mobile/attendance/save', h2SavePayload())
        ->assertUnauthorized();
});

test('anonymous cannot load staff attendance roster', function () {
    $this->postJson('/api/mobile/attendances', [
        'formation_id' => $this->ownFormation->id,
        'attendance_day' => '2025-06-22',
    ])->assertUnauthorized();
});

test('student cannot save attendance even on school network', function () {
    $student = h2User(['student']);
    $target = h2User(['student'], ['formation_id' => $this->otherFormation->id]);
    $payload = [
        'attendance' => [[
            'attendance_id' => $this->otherAttendance->id,
            'user_id' => $target->id,
            'attendance_day' => '2025-06-22',
            'morning' => 'present',
            'note' => 'forged',
        ]],
    ];

    $attendanceCount = Attendance::count();
    $listCount = AttendanceListe::count();
    $noteCount = Note::count();
    $disciplineCount = DisciplineNotification::count();

    h2PostSave($this, $student, $payload)
        ->assertForbidden()
        ->assertJson(['message' => 'Forbidden']);

    h2AssertNoStaffWrites($attendanceCount, $listCount, $noteCount, $disciplineCount);
});

test('student cannot create or read a roster via POST attendances', function () {
    $student = h2User(['student']);
    $before = Attendance::count();

    $this->actingAs($student, 'sanctum')
        ->postJson('/api/mobile/attendances', [
            'formation_id' => $this->otherFormation->id,
            'attendance_day' => '2026-01-15',
        ])
        ->assertForbidden()
        ->assertJsonMissingPath('attendance_id')
        ->assertJsonMissingPath('lists');

    expect(Attendance::count())->toBe($before)
        ->and(Attendance::query()->where('formation_id', $this->otherFormation->id)->where('attendance_day', '2026-01-15')->exists())->toBeFalse();
});

test('student cannot view attendance events', function () {
    $student = h2User(['student']);

    $this->actingAs($student, 'sanctum')
        ->getJson('/api/mobile/trainings/'.$this->otherFormation->id.'/attendance-events')
        ->assertForbidden();
});

test('student cannot IDOR save another formation or user', function () {
    $student = h2User(['student']);
    $otherStudent = h2User(['student'], ['formation_id' => $this->otherFormation->id]);

    $attendanceCount = Attendance::count();
    $listCount = AttendanceListe::count();
    $noteCount = Note::count();
    $disciplineCount = DisciplineNotification::count();

    h2PostSave($this, $student, [
        'attendance' => [[
            'attendance_id' => $this->otherAttendance->id,
            'user_id' => $otherStudent->id,
            'attendance_day' => '2025-06-22',
            'morning' => 'absent',
            'lunch' => 'absent',
            'evening' => 'absent',
        ]],
    ])->assertForbidden();

    h2AssertNoStaffWrites($attendanceCount, $listCount, $noteCount, $disciplineCount);
});

test('studio_responsable cannot manage attendance roster', function () {
    $studio = h2User(['studio_responsable']);
    $before = Attendance::count();

    h2PostSave($this, $studio, [
        'attendance' => [[
            'attendance_id' => $this->otherAttendance->id,
            'user_id' => h2User(['student'])->id,
            'attendance_day' => '2025-06-22',
            'morning' => 'present',
        ]],
    ])->assertForbidden();

    $this->actingAs($studio, 'sanctum')
        ->postJson('/api/mobile/attendances', [
            'formation_id' => $this->ownFormation->id,
            'attendance_day' => '2025-06-22',
        ])
        ->assertForbidden();

    $this->actingAs($studio, 'sanctum')
        ->getJson('/api/mobile/trainings/'.$this->ownFormation->id.'/attendance-events')
        ->assertForbidden();

    expect(Attendance::count())->toBe($before);
});

test('moderateur cannot manage attendance roster', function () {
    $moderateur = h2User(['moderateur']);

    h2PostSave($this, $moderateur, [
        'attendance' => [[
            'attendance_id' => $this->otherAttendance->id,
            'user_id' => h2User(['student'])->id,
            'attendance_day' => '2025-06-22',
            'morning' => 'present',
        ]],
    ])->assertForbidden();

    $this->actingAs($moderateur, 'sanctum')
        ->postJson('/api/mobile/attendances', [
            'formation_id' => $this->ownFormation->id,
            'attendance_day' => '2025-06-22',
        ])
        ->assertForbidden();
});

test('super_admin cannot manage attendance roster', function () {
    $superAdmin = h2User(['super_admin']);

    h2PostSave($this, $superAdmin, [
        'attendance' => [[
            'attendance_id' => $this->otherAttendance->id,
            'user_id' => h2User(['student'])->id,
            'attendance_day' => '2025-06-22',
            'morning' => 'present',
        ]],
    ])->assertForbidden();

    $this->actingAs($superAdmin, 'sanctum')
        ->getJson('/api/mobile/trainings/'.$this->ownFormation->id.'/attendance-events')
        ->assertForbidden();
});

test('admin can load roster, save attendance, and view events', function () {
    $admin = h2User(['admin']);
    $student = h2User(['student']);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/mobile/attendances', [
            'formation_id' => $this->ownFormation->id,
            'attendance_day' => '2025-06-22',
        ])
        ->assertOk()
        ->assertJsonPath('attendance_id', fn ($id) => $id !== null);

    $session = Attendance::query()
        ->where('formation_id', $this->ownFormation->id)
        ->where('attendance_day', '2025-06-22')
        ->first();

    expect($session)->not->toBeNull();

    $this->actingAs($admin, 'sanctum')
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])
        ->postJson('/api/mobile/attendance/save', [
            'attendance' => [[
                'attendance_id' => $session->id,
                'user_id' => $student->id,
                'attendance_day' => '2025-06-22',
                'morning' => 'present',
                'lunch' => 'late',
                'evening' => 'absent',
            ]],
        ])
        ->assertOk()
        ->assertJson(['status' => 'ok']);

    expect(AttendanceListe::query()->where('user_id', $student->id)->where('attendance_id', $session->id)->exists())->toBeTrue();

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/mobile/trainings/'.$this->ownFormation->id.'/attendance-events')
        ->assertOk()
        ->assertJsonStructure(['events']);
});

test('coach can manage attendance for any training', function () {
    $coach = h2User(['coach'], ['formation_id' => null]);
    $student = h2User(['student'], ['formation_id' => $this->otherFormation->id]);

    $this->actingAs($coach, 'sanctum')
        ->postJson('/api/mobile/attendances', [
            'formation_id' => $this->otherFormation->id,
            'attendance_day' => '2025-06-22',
        ])
        ->assertOk();

    h2PostSave($this, $coach, [
        'attendance' => [[
            'attendance_id' => $this->otherAttendance->id,
            'user_id' => $student->id,
            'attendance_day' => '2025-06-22',
            'morning' => 'present',
        ]],
    ], '198.51.100.99')->assertOk();

    $this->actingAs($coach, 'sanctum')
        ->getJson('/api/mobile/trainings/'.$this->otherFormation->id.'/attendance-events')
        ->assertOk();
});

test('enrolled student on school network can still self check-in', function () {
    Carbon::setTestNow(Carbon::parse(Carbon::now()->toDateString().' 09:42:00', 'Africa/Casablanca'));
    bindVerifiedFaceVerifier();

    $student = h2User(['student']);

    $this->actingAs($student, 'sanctum')
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])
        ->post('/api/mobile/attendance/check-in', [
            'formation_id' => $this->ownFormation->id,
            'attendance_day' => Carbon::now()->toDateString(),
            'live_photo' => m8LivePhoto(),
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJson([
            'slot' => 'morning',
            'status' => 'present',
        ]);

    expect(AttendanceListe::query()->where('user_id', $student->id)->exists())->toBeTrue();
});
