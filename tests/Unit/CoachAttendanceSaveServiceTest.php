<?php

use App\Models\Attendance;
use App\Models\AttendanceListe;
use App\Models\Formation;
use App\Services\AttendanceSlotService;
use App\Services\CoachAttendanceSaveService;
use App\Services\StudentCheckInSlotService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::dropAllTables();

    Schema::create('formations', function (Blueprint $table) {
        $table->id();
        $table->string('name');
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

    $this->formation = Formation::create(['name' => 'Test Formation']);
    $this->userIds = [11, 12, 13];

    $this->attendance = Attendance::create([
        'formation_id' => $this->formation->id,
        'attendance_day' => '2025-06-22',
        'staff_name' => 'Coach',
    ]);

    foreach ($this->userIds as $userId) {
        AttendanceListe::create([
            'user_id' => $userId,
            'attendance_id' => $this->attendance->id,
            'attendance_day' => '2025-06-22',
            'morning' => 'present',
            'lunch' => 'present',
            'evening' => 'present',
        ]);
    }

    $this->service = new CoachAttendanceSaveService(
        new StudentCheckInSlotService(new AttendanceSlotService),
        new AttendanceSlotService,
    );
});

test('preloadSaveContext uses one query per table for the full student batch', function () {
    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->service->preloadSaveContext(
        $this->attendance->id,
        $this->userIds,
    );

    expect(count(DB::getQueryLog()))->toBe(2);
});

test('resolveSlotsForSave with preloaded context matches per-student resolution', function () {
    $context = $this->service->preloadSaveContext($this->attendance->id, $this->userIds);

    foreach ($this->userIds as $userId) {
        $coachSlots = ['morning' => 'absent', 'lunch' => 'late', 'evening' => 'present'];

        expect(
            $this->service->resolveSlotsForSave(
                $this->attendance->id,
                $userId,
                '2025-06-22',
                $coachSlots,
                $context,
            )
        )->toBe(
            $this->service->resolveSlotsForSave(
                $this->attendance->id,
                $userId,
                '2025-06-22',
                $coachSlots,
            )
        );
    }
});
