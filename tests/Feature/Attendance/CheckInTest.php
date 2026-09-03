<?php

use App\Models\Attendance;
use App\Models\AttendanceListe;
use App\Models\Formation;
use App\Models\Note;
use App\Models\User;
use App\Services\FaceVerification\FaceVerificationResult;
use App\Services\FaceVerification\FaceVerificationService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
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

    $this->formation = Formation::create([
        'name' => 'Test Formation',
        'category' => 'coding',
        'start_time' => '2025-01-01',
        'user_id' => null,
    ]);

    config(['attendance.allowed_ips' => ['203.0.113.1']]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function createCheckInStudent(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'role' => ['student'],
        'status' => 'Studying',
        'formation_id' => test()->formation->id,
    ], $attributes));
}

function postCheckIn(TestCase $test, User $actor, string $remoteAddr, array $overrides = [], bool $withLivePhoto = true): TestResponse
{
    $payload = array_merge([
        'formation_id' => $test->formation->id,
        'attendance_day' => Carbon::now()->toDateString(),
    ], $overrides);

    if ($withLivePhoto && ! array_key_exists('live_photo', $payload)) {
        $payload['live_photo'] = m8LivePhoto();
    }

    return $test->actingAs($actor, 'sanctum')
        ->withServerVariables(['REMOTE_ADDR' => $remoteAddr])
        ->post('/api/mobile/attendance/check-in', $payload, [
            'Accept' => 'application/json',
        ]);
}

function freezeCheckInTime(string $time): void
{
    Carbon::setTestNow(Carbon::parse(Carbon::now()->toDateString().' '.$time, 'Africa/Casablanca'));
}

test('check-in saves present during the present window', function () {
    freezeCheckInTime('09:42:00');
    bindVerifiedFaceVerifier();

    $student = createCheckInStudent();
    $countBefore = AttendanceListe::count();
    $attendanceCountBefore = Attendance::count();

    postCheckIn($this, $student, '203.0.113.1')
        ->assertOk()
        ->assertJson([
            'slot' => 'morning',
            'status' => 'present',
            'row' => [
                'morning' => 'present',
                'lunch' => 'pending',
                'evening' => 'pending',
            ],
        ]);

    expect(AttendanceListe::count())->toBe($countBefore + 1);
    expect(Attendance::count())->toBe($attendanceCountBefore + 1);
    expect(Note::count())->toBe(1);
    expect(Note::first()->note)->toBe('Check-in at 09:42');
});

test('check-in saves late after the present window', function () {
    freezeCheckInTime('09:46:00');
    bindVerifiedFaceVerifier();

    $student = createCheckInStudent();

    postCheckIn($this, $student, '203.0.113.1')
        ->assertOk()
        ->assertJson([
            'slot' => 'morning',
            'status' => 'late',
            'row' => [
                'morning' => 'late',
                'lunch' => 'pending',
                'evening' => 'pending',
            ],
        ]);
});

test('check-in during a gap returns 422 with no database writes', function () {
    freezeCheckInTime('11:05:00');

    $student = createCheckInStudent();
    $listCountBefore = AttendanceListe::count();
    $attendanceCountBefore = Attendance::count();
    $noteCountBefore = Note::count();

    postCheckIn($this, $student, '203.0.113.1')
        ->assertStatus(422)
        ->assertJson(['message' => 'No attendance to mark right now.']);

    expect(AttendanceListe::count())->toBe($listCountBefore);
    expect(Attendance::count())->toBe($attendanceCountBefore);
    expect(Note::count())->toBe($noteCountBefore);
});

test('check-in outside school hours returns 422 with no database writes', function () {
    freezeCheckInTime('08:00:00');

    $student = createCheckInStudent();
    $listCountBefore = AttendanceListe::count();

    postCheckIn($this, $student, '203.0.113.1')
        ->assertStatus(422)
        ->assertJson(['message' => 'No attendance to mark right now.']);

    expect(AttendanceListe::count())->toBe($listCountBefore);
});

test('check-in for an already marked slot returns 409 with no database writes', function () {
    freezeCheckInTime('09:40:00');
    bindVerifiedFaceVerifier();

    $student = createCheckInStudent();
    $attendance = Attendance::create([
        'formation_id' => $this->formation->id,
        'attendance_day' => Carbon::now()->toDateString(),
        'staff_name' => 'Test',
    ]);
    AttendanceListe::create([
        'user_id' => $student->id,
        'attendance_id' => $attendance->id,
        'attendance_day' => Carbon::now()->toDateString(),
        'morning' => 'present',
        'lunch' => 'pending',
        'evening' => 'pending',
    ]);

    $countBefore = AttendanceListe::count();

    postCheckIn($this, $student, '203.0.113.1')
        ->assertStatus(409)
        ->assertJson(['message' => "You've already marked attendance for this slot."]);

    expect(AttendanceListe::count())->toBe($countBefore);
});

test('coach absent without notes blocks student check-in with 409', function () {
    freezeCheckInTime('09:40:00');
    bindVerifiedFaceVerifier();

    $student = createCheckInStudent();
    $attendance = Attendance::create([
        'formation_id' => $this->formation->id,
        'attendance_day' => Carbon::now()->toDateString(),
        'staff_name' => 'Coach',
    ]);
    AttendanceListe::create([
        'user_id' => $student->id,
        'attendance_id' => $attendance->id,
        'attendance_day' => Carbon::now()->toDateString(),
        'morning' => 'absent',
        'lunch' => 'pending',
        'evening' => 'pending',
    ]);

    $countBefore = AttendanceListe::count();

    postCheckIn($this, $student, '203.0.113.1')
        ->assertStatus(409)
        ->assertJson(['message' => "You've already marked attendance for this slot."]);

    expect(AttendanceListe::count())->toBe($countBefore);
    expect(AttendanceListe::first()->morning)->toBe('absent');
});

test('later check-in preserves an earlier marked slot', function () {
    freezeCheckInTime('11:35:00');
    bindVerifiedFaceVerifier();

    $student = createCheckInStudent();
    $attendance = Attendance::create([
        'formation_id' => $this->formation->id,
        'attendance_day' => Carbon::now()->toDateString(),
        'staff_name' => 'Test',
    ]);
    AttendanceListe::create([
        'user_id' => $student->id,
        'attendance_id' => $attendance->id,
        'attendance_day' => Carbon::now()->toDateString(),
        'morning' => 'present',
        'lunch' => null,
        'evening' => null,
    ]);

    postCheckIn($this, $student, '203.0.113.1')
        ->assertOk()
        ->assertJson([
            'slot' => 'lunch',
            'row' => [
                'morning' => 'present',
                'lunch' => 'present',
                'evening' => 'pending',
            ],
        ]);
});

test('lunch-only check-in finalizes morning as absent and leaves evening pending', function () {
    freezeCheckInTime('11:35:00');
    bindVerifiedFaceVerifier();

    $student = createCheckInStudent();

    postCheckIn($this, $student, '203.0.113.1')
        ->assertOk()
        ->assertJson([
            'slot' => 'lunch',
            'row' => [
                'morning' => 'absent',
                'lunch' => 'present',
                'evening' => 'pending',
            ],
        ]);
});

test('off-network student receives 403 on check-in', function () {
    freezeCheckInTime('09:42:00');

    $student = createCheckInStudent();
    $countBefore = AttendanceListe::count();

    postCheckIn($this, $student, '198.51.100.99')
        ->assertForbidden()
        ->assertJson(['message' => 'You must be connected to the school WiFi to check in.']);

    expect(AttendanceListe::count())->toBe($countBefore);
});

test('staff bypasses network restriction on check-in', function () {
    freezeCheckInTime('09:42:00');
    bindVerifiedFaceVerifier();

    $coach = createCheckInStudent(['role' => ['coach']]);
    $countBefore = AttendanceListe::count();

    postCheckIn($this, $coach, '198.51.100.99')
        ->assertOk()
        ->assertJsonPath('status', 'present');

    expect(AttendanceListe::count())->toBe($countBefore + 1);
});

test('slot-status reflects active morning slot from server time', function () {
    freezeCheckInTime('09:42:00');

    $student = createCheckInStudent();

    $this->actingAs($student, 'sanctum')
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])
        ->getJson('/api/mobile/attendance/slot-status?'.http_build_query([
            'formation_id' => $this->formation->id,
            'attendance_day' => Carbon::now()->toDateString(),
        ]))
        ->assertOk()
        ->assertJson([
            'attendance_day' => Carbon::now()->toDateString(),
            'current_slot' => 'morning',
            'phase' => 'active',
            'minutes_into_slot' => 12,
            'present_minutes' => 15,
            'already_marked_slots' => [],
            'label_key' => 'attendance.check_in.present',
        ]);
});

test('slot-status with only formation_id defaults attendance_day to server today', function () {
    freezeCheckInTime('09:42:00');

    $student = createCheckInStudent();

    $this->actingAs($student, 'sanctum')
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])
        ->getJson('/api/mobile/attendance/slot-status?'.http_build_query([
            'formation_id' => $this->formation->id,
        ]))
        ->assertOk()
        ->assertJson([
            'attendance_day' => Carbon::now()->toDateString(),
            'current_slot' => 'morning',
            'phase' => 'active',
        ]);
});

test('check-in without live_photo is rejected and does not write attendance', function () {
    freezeCheckInTime('09:42:00');

    $student = createCheckInStudent();
    $listCountBefore = AttendanceListe::count();
    $attendanceCountBefore = Attendance::count();

    $this->actingAs($student, 'sanctum')
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])
        ->postJson('/api/mobile/attendance/check-in', [
            'formation_id' => $this->formation->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('live_photo');

    expect(AttendanceListe::count())->toBe($listCountBefore)
        ->and(Attendance::count())->toBe($attendanceCountBefore);
});

test('check-in rejects a non-image live_photo and does not write attendance', function () {
    freezeCheckInTime('09:42:00');

    $student = createCheckInStudent();
    $listCountBefore = AttendanceListe::count();
    $attendanceCountBefore = Attendance::count();

    postCheckIn($this, $student, '203.0.113.1', [
        'live_photo' => UploadedFile::fake()->create('note.txt', 20, 'text/plain'),
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('live_photo');

    expect(AttendanceListe::count())->toBe($listCountBefore)
        ->and(Attendance::count())->toBe($attendanceCountBefore);
});

test('a valid jpeg without server-side face verification does not create attendance', function () {
    freezeCheckInTime('09:42:00');

    $student = createCheckInStudent();
    $listCountBefore = AttendanceListe::count();
    $attendanceCountBefore = Attendance::count();

    postCheckIn($this, $student, '203.0.113.1')
        ->assertStatus(503)
        ->assertJson(['message' => 'Unable to verify your identity.']);

    expect(AttendanceListe::count())->toBe($listCountBefore)
        ->and(Attendance::count())->toBe($attendanceCountBefore)
        ->and(Note::count())->toBe(0);
});

test('rejected face verification does not create attendance', function () {
    freezeCheckInTime('09:42:00');

    app()->instance(FaceVerificationService::class, new class implements FaceVerificationService
    {
        public function verify(User $user, UploadedFile $livePhoto): FaceVerificationResult
        {
            return FaceVerificationResult::Rejected;
        }
    });

    $student = createCheckInStudent();
    $listCountBefore = AttendanceListe::count();
    $attendanceCountBefore = Attendance::count();

    postCheckIn($this, $student, '203.0.113.1')
        ->assertUnprocessable()
        ->assertJson(['message' => 'Face not recognized.']);

    expect(AttendanceListe::count())->toBe($listCountBefore)
        ->and(Attendance::count())->toBe($attendanceCountBefore);
});

test('unenrolled student cannot check in to another formation', function () {
    freezeCheckInTime('09:42:00');

    $otherFormation = Formation::create([
        'name' => 'Other Formation',
        'category' => 'media',
        'start_time' => '2025-01-01',
        'user_id' => null,
    ]);
    $student = createCheckInStudent();
    $listCountBefore = AttendanceListe::count();
    $attendanceCountBefore = Attendance::count();

    postCheckIn($this, $student, '203.0.113.1', [
        'formation_id' => $otherFormation->id,
    ])
        ->assertForbidden()
        ->assertJson(['message' => 'Forbidden']);

    expect(AttendanceListe::count())->toBe($listCountBefore)
        ->and(Attendance::count())->toBe($attendanceCountBefore);
});

test('client-supplied user_id cannot check in another student', function () {
    freezeCheckInTime('09:42:00');
    bindVerifiedFaceVerifier();

    $actor = createCheckInStudent(['email' => 'm8.actor@example.com']);
    $other = createCheckInStudent(['email' => 'm8.other@example.com']);

    postCheckIn($this, $actor, '203.0.113.1', [
        'user_id' => $other->id,
    ])
        ->assertOk()
        ->assertJsonPath('row.user_id', $actor->id);

    expect(AttendanceListe::query()->where('user_id', $actor->id)->exists())->toBeTrue()
        ->and(AttendanceListe::query()->where('user_id', $other->id)->exists())->toBeFalse();
});

test('verified check-in with only formation_id grades for server today', function () {
    freezeCheckInTime('09:42:00');
    bindVerifiedFaceVerifier();

    $student = createCheckInStudent();

    $this->actingAs($student, 'sanctum')
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])
        ->post('/api/mobile/attendance/check-in', [
            'formation_id' => $this->formation->id,
            'live_photo' => m8LivePhoto(),
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJson([
            'slot' => 'morning',
            'status' => 'present',
            'row' => [
                'attendance_day' => Carbon::now()->toDateString(),
                'morning' => 'present',
            ],
        ]);
});

test('slot-status reports gap phase between slots', function () {
    freezeCheckInTime('11:05:00');

    $student = createCheckInStudent();

    $this->actingAs($student, 'sanctum')
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])
        ->getJson('/api/mobile/attendance/slot-status?'.http_build_query([
            'formation_id' => $this->formation->id,
            'attendance_day' => Carbon::now()->toDateString(),
        ]))
        ->assertOk()
        ->assertJson([
            'current_slot' => null,
            'phase' => 'gap',
            'label_key' => 'attendance.gap',
        ]);
});

test('save defaults omitted slots to absent', function () {
    $coach = createCheckInStudent(['role' => ['coach']]);
    $student = createCheckInStudent();
    $attendance = Attendance::create([
        'formation_id' => $this->formation->id,
        'attendance_day' => '2025-06-22',
        'staff_name' => 'Test',
    ]);

    $this->actingAs($coach, 'sanctum')
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])
        ->postJson('/api/mobile/attendance/save', [
            'attendance' => [[
                'attendance_id' => $attendance->id,
                'user_id' => $student->id,
                'attendance_day' => '2025-06-22',
                'morning' => 'present',
            ]],
        ])
        ->assertOk();

    $row = AttendanceListe::first();
    expect($row->morning)->toBe('present');
    expect($row->lunch)->toBe('absent');
    expect($row->evening)->toBe('absent');
});
