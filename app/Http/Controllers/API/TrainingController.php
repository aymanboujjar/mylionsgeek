<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTrainingManagementRole;
use App\Models\Attendance;
use App\Models\AttendanceListe;
use App\Models\Note;
use App\Models\Formation;
use App\Models\User;
use App\Services\AttendanceCheckInService;
use App\Services\AttendanceLegacyIdService;
use App\Services\AttendancePersistenceService;
use App\Services\ProgramStatusService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TrainingController extends Controller
{
    public function index(Request $request)
    {
        try {
            $checkResult = $this->checkRequestedUser();
            if ($checkResult) {
                return $checkResult;
            }

            $coachId = $request->query('coach');
            $track = $request->query('track');
            $promo = $request->query('promo');
            $mineOnly = $request->boolean('mine');

            $authUser = Auth::guard('sanctum')->user();
            $myFormationIds = $mineOnly && $authUser
                ? $authUser->resolvedFormationIds()
                : [];

            $query = Formation::with('coach')->withCount('users');

            if ($mineOnly) {
                if (empty($myFormationIds)) {
                    return response()->json([
                        'trainings' => [],
                        'formation_id' => null,
                        'formation_ids' => [],
                        'coaches' => [],
                        'tracks' => [],
                        'promos' => [],
                        'filters' => ['mine' => true],
                    ]);
                }
                $query->whereIn('id', $myFormationIds);
            }

            if (!empty($coachId)) {
                $query->where('user_id', $coachId);
            }

            if (!empty($track)) {
                $query->where('category', $track);
            }
            
            if (!empty($promo)) {
                $query->where('promo', $promo);
            }

            $trainings = $query->orderBy('created_at', 'desc')->get()->map(function ($training) {
                try {
                    $img = $training->img ?? null;
                    if ($img && !Str::startsWith($img, ['http://', 'https://', 'storage/'])) {
                        $img = 'storage/img/training/' . ltrim($img, '/');
                    } elseif ($img && Str::startsWith($img, 'storage/') && !Str::startsWith($img, 'storage/img/')) {
                        // If it's already storage/ but not storage/img/training/, update it
                        $img = 'storage/img/training/' . basename($img);
                    }
                    
                    $coachData = null;
                    if ($training->coach) {
                        $coachData = [
                            'id' => $training->coach->id ?? null,
                            'name' => $training->coach->name ?? null,
                            'email' => $training->coach->email ?? null,
                        ];
                    }
                    
                    return [
                        'id' => $training->id ?? null,
                        'name' => $training->name ?? null,
                        'img' => $img ? asset($img) : null,
                        'category' => $training->category ?? null,
                        'start_time' => $training->start_time ?? null,
                        'end_time' => $training->end_time ?? null,
                        'promo' => $training->promo ?? null,
                        'coach' => $coachData,
                        'users_count' => $training->users_count ?? 0,
                        'created_at' => $training->created_at ? (is_string($training->created_at) ? $training->created_at : $training->created_at->toDateTimeString()) : null,
                        'updated_at' => $training->updated_at ? (is_string($training->updated_at) ? $training->updated_at : $training->updated_at->toDateTimeString()) : null,
                    ];
                } catch (\Exception $e) {
                    Log::error('Error mapping training: ' . $e->getMessage(), [
                        'training_id' => $training->id ?? 'unknown',
                    ]);
                    return null;
                }
            })->filter();

            // Try to get coaches, but handle errors gracefully
            try {
                $coaches = User::whereJsonContains('role', 'coach')->get()->map(function ($coach) {
                    return [
                        'id' => $coach->id,
                        'name' => $coach->name,
                        'email' => $coach->email,
                    ];
                });
            } catch (\Exception $e) {
                // Fallback: try to get coaches by role string if JSON query fails
                $coaches = User::where('role', 'coach')
                    ->orWhere('role', 'like', '%coach%')
                    ->get()
                    ->map(function ($coach) {
                        return [
                            'id' => $coach->id,
                            'name' => $coach->name,
                            'email' => $coach->email,
                        ];
                    });
            }

            $tracks = Formation::select('category')->distinct()->pluck('category')->filter()->values()->toArray();
            $promos = Formation::select('promo')->distinct()->pluck('promo')->filter()->values()->toArray();

            $payload = [
                'trainings' => $trainings->values()->toArray(),
                'coaches' => $coaches->values()->toArray(),
                'tracks' => $tracks,
                'promos' => $promos,
                'filters' => [
                    'coach' => $coachId,
                    'track' => $track,
                    'promo' => $promo,
                    'mine' => $mineOnly,
                ],
            ];

            if ($mineOnly && $authUser) {
                $payload['formation_id'] = $authUser->primaryFormationId();
                $payload['formation_ids'] = $myFormationIds;
            }

            return response()->json($payload);
        } catch (\Exception $e) {
            Log::error('TrainingController@index error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            
            return response()->json([
                'error' => 'Failed to fetch trainings',
                'message' => config('app.debug') ? $e->getMessage() : 'An error occurred while fetching trainings',
            ], 500);
        }
    }

    /**
     * Formations the authenticated user is enrolled in (for mobile attendance history).
     */
    public function enrolled(Request $request)
    {
        $checkResult = $this->checkRequestedUser();
        if ($checkResult) {
            return $checkResult;
        }

        $user = Auth::guard('sanctum')->user();
        $ids = $user->resolvedFormationIds();

        $trainings = empty($ids)
            ? collect()
            : Formation::query()
                ->whereIn('id', $ids)
                ->orderByDesc('id')
                ->get(['id', 'name', 'category', 'promo']);

        return response()->json([
            'formation_id' => $user->primaryFormationId(),
            'formation_ids' => $ids,
            'trainings' => $trainings->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'category' => $t->category,
                'promo' => $t->promo,
            ])->values()->all(),
        ]);
    }

    public function show($id)
    {
        $checkResult = $this->checkRequestedUser();
        if ($checkResult) {
            return $checkResult;
        }

        $auth = Auth::guard('sanctum')->user();
        $canManage = $auth instanceof User && EnsureTrainingManagementRole::allows($auth);

        if (! $canManage && ! $auth->isEnrolledInFormation((int) $id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $training = Formation::with(['coach', 'users'])->findOrFail($id);

        $usersNull = $canManage
            ? User::whereNull('formation_id')->get()->map(function ($user) {
                $img = $user->image;
                if ($img && !Str::startsWith($img, ['http://', 'https://', 'storage/'])) {
                    $img = 'storage/img/profile/' . ltrim($img, '/');
                }

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'image' => $img ? asset($img) : null,
                ];
            })
            : null;

        $img = $training->img;
        if ($img && !Str::startsWith($img, ['http://', 'https://', 'storage/'])) {
            $img = 'storage/img/training/' . ltrim($img, '/');
        } elseif ($img && Str::startsWith($img, 'storage/') && !Str::startsWith($img, 'storage/img/training/')) {
            // If it's already storage/ but not storage/img/training/, update it
            $img = 'storage/img/training/' . basename($img);
        }

        $coachImg = $training->coach?->image;
        if ($coachImg && !Str::startsWith($coachImg, ['http://', 'https://', 'storage/'])) {
            $coachImg = 'storage/img/profile/' . ltrim($coachImg, '/');
        }

        $trainingData = [
            'id' => $training->id,
            'name' => $training->name,
            'img' => $img ? asset($img) : null,
            'category' => $training->category,
            'start_time' => $training->start_time,
            'end_time' => $training->end_time,
            'promo' => $training->promo,
            'coach' => $training->coach ? [
                'id' => $training->coach->id,
                'name' => $training->coach->name,
                'email' => $training->coach->email,
                'image' => $coachImg ? asset($coachImg) : null,
            ] : null,
            'users' => $training->users->map(function ($user) {
                $userImg = $user->image;
                if ($userImg && !Str::startsWith($userImg, ['http://', 'https://', 'storage/'])) {
                    $userImg = 'storage/img/profile/' . ltrim($userImg, '/');
                }
                
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'image' => $userImg ? asset($userImg) : null,
                ];
            }),
            'created_at' => $training->created_at ? (is_string($training->created_at) ? $training->created_at : $training->created_at->toDateTimeString()) : null,
            'updated_at' => $training->updated_at ? (is_string($training->updated_at) ? $training->updated_at : $training->updated_at->toDateTimeString()) : null,
        ];

        $payload = [
            'training' => $trainingData,
        ];

        if ($canManage) {
            $payload['usersNull'] = $usersNull;
        }

        return response()->json($payload);
    }

    public function store(Request $request)
    {
        $checkResult = $this->checkRequestedUser();
        if ($checkResult) {
            return $checkResult;
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'img' => 'nullable|string|max:255',
            'category' => 'required|string|max:100',
            'start_time' => 'required|date',
            'end_time' => 'nullable|date',
            'user_id' => 'required|exists:users,id',
            'promo' => 'nullable|string|max:50',
        ]);

        $training = Formation::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Training created successfully',
            'training' => $training,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $checkResult = $this->checkRequestedUser();
        if ($checkResult) {
            return $checkResult;
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'img' => 'nullable|string|max:255',
            'category' => 'required|string|max:100',
            'start_time' => 'required|date',
            'end_time' => 'nullable|date',
            'user_id' => 'nullable|exists:users,id',
            'promo' => 'nullable|string|max:50',
        ]);

        $formation = Formation::findOrFail($id);
        $formation->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Training updated successfully',
            'training' => $formation,
        ]);
    }

    public function destroy($id)
    {
        $checkResult = $this->checkRequestedUser();
        if ($checkResult) {
            return $checkResult;
        }

        $formation = Formation::findOrFail($id);
        $formation->delete();

        return response()->json([
            'success' => true,
            'message' => 'Training deleted successfully',
        ]);
    }

    public function addStudent(Request $request, $id, ProgramStatusService $programStatusService)
    {
        $checkResult = $this->checkRequestedUser();
        if ($checkResult) {
            return $checkResult;
        }

        $validated = $request->validate([
            'student_id' => 'required|exists:users,id'
        ]);

        $training = Formation::findOrFail($id);
        $user = User::findOrFail($validated['student_id']);
        
        if ($user) {
            $user->formation_id = $training->id;
            $programStatusService->applyEnrollmentStatus($user);
            $user->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Student added successfully',
        ]);
    }

    public function removeStudent($id, $userId)
    {
        $checkResult = $this->checkRequestedUser();
        if ($checkResult) {
            return $checkResult;
        }

        $training = Formation::findOrFail($id);
        $user = User::findOrFail($userId);
        
        if ($user->formation_id === $training->id) {
            $user->formation_id = null;
            $user->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Student removed successfully',
        ]);
    }

    public function bulkUpdateUsers(Request $request, $id, ProgramStatusService $programStatusService)
    {
        $checkResult = $this->checkRequestedUser();
        if ($checkResult) {
            return $checkResult;
        }

        $validated = $request->validate([
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'required|exists:users,id',
            'roles' => 'nullable|array',
            'roles.*' => 'nullable|string|in:student,coach,admin,super_admin,moderateur,studio_responsable,responsable_studio,coworker,pro,recruiter',
            'program_status' => 'nullable|in:active,certified,not_certified,left',
            'has_handicap' => 'nullable|in:0,1',
        ]);

        $training = Formation::findOrFail($id);

        if ($request->exists('program_status')) {
            $programStatus = $request->input('program_status');
            if ($programStatus !== null && $programStatus !== '') {
                $programStatusService->assertCanAssignLeft($actor, $programStatus, $training);
            }
        }

        if ($request->exists('has_handicap')) {
            $actorRoles = is_array($actor->role) ? $actor->role : array_filter([(string) $actor->role]);
            $canViewHealthData = count(array_intersect($actorRoles, ['admin', 'super_admin'])) > 0;
            if (! $canViewHealthData) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only admins can update handicap data.',
                ], 403);
            }
        }

        $users = User::whereIn('id', $validated['user_ids'])
            ->where('formation_id', $training->id)
            ->get();

        if ($users->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No valid users found for this training.',
            ], 400);
        }

        if ($request->has('roles') && ! empty($validated['roles'])) {
            Auth::guard('sanctum')->user()->assertMayAssignRoles($validated['roles']);
        }

        $updated = 0;
        foreach ($users as $user) {
            $updateData = [];

            if ($request->has('roles') && !empty($validated['roles'])) {
                $updateData['role'] = array_values(array_map(function ($r) {
                    return strtolower((string) $r);
                }, array_filter($validated['roles'])));
            }

            if ($request->exists('program_status')) {
                $programStatus = $request->input('program_status');
                $updateData['program_status'] = ($programStatus === null || $programStatus === '') ? null : $programStatus;
            }

            if ($request->exists('has_handicap')) {
                $handicap = $request->input('has_handicap');
                if ($handicap === null || $handicap === '') {
                    $updateData['has_handicap'] = null;
                } else {
                    $updateData['has_handicap'] = (int) $handicap === 1;
                }
            }

            if (!empty($updateData)) {
                $user->forceFill($updateData)->save();
                $updated++;
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Successfully updated {$updated} user(s).",
        ]);
    }

    public function attendance(Request $request, AttendanceLegacyIdService $legacyIdService)
    {
        $checkResult = $this->checkRequestedUser();
        if ($checkResult) {
            return $checkResult;
        }

        $request->validate([
            'formation_id' => 'required|integer|exists:formations,id',
            'attendance_day' => 'required|date',
        ]);

        $staffName = Auth::guard('sanctum')->user()->name ?? 'Staff';

        // Find existing attendance for formation + day or create one
        $attendance = Attendance::where('formation_id', $request->formation_id)
            ->whereDate('attendance_day', $request->attendance_day)
            ->first();

        if (!$attendance) {
            $attendance = Attendance::create([
                'formation_id' => $request->formation_id,
                'attendance_day' => $request->attendance_day,
                'staff_name' => $staffName,
            ]);
        }

        $attendance = $legacyIdService->ensureNumericId($attendance, $staffName);

        // Load existing list entries and attach notes per user (joined as one string)
        $lists = AttendanceListe::where('attendance_id', $attendance->id)
            ->get(['user_id', 'attendance_day', 'morning', 'lunch', 'evening']);

        $userIds = $lists->pluck('user_id')->unique()->values();
        $notesByUser = Note::whereIn('user_id', $userIds)
            ->where('attendance_id', $attendance->id)
            ->get(['user_id', 'note'])
            ->groupBy('user_id')
            ->map(function ($group) {
                return $group->pluck('note')->implode(' | ');
            });

        $lists = $lists->map(function ($row) use ($notesByUser) {
            $row->note = $notesByUser[$row->user_id] ?? null;
            return $row;
        });

        return response()->json([
            'attendance_id' => $attendance->id,
            'lists' => $lists,
            'staff_name' => $attendance->staff_name,
        ]);
    }

    public function attendanceEvents($id)
    {
        $checkResult = $this->checkRequestedUser();
        if ($checkResult) {
            return $checkResult;
        }

        $training = Formation::findOrFail($id);
        $events = Attendance::where('formation_id', $training->id)
            ->orderByDesc('attendance_day')
            ->get(['attendance_day', 'staff_name'])
            ->map(function ($a) {
                return [
                    'date' => $a->attendance_day,
                    'title' => 'Saved by ' . ($a->staff_name ?? 'staff'),
                    'color' => '#FACC15', // yellow-400
                ];
            });

        return response()->json(['events' => $events]);
    }

    /**
     * Student (or staff) attendance ledger for one training: one row per day with morning / lunch / evening.
     */
    public function attendanceHistory(Request $request, int $id)
    {
        $checkResult = $this->checkRequestedUser();
        if ($checkResult) {
            return $checkResult;
        }

        $auth = Auth::guard('sanctum')->user();
        $training = Formation::findOrFail($id);

        $isMember = $auth->isEnrolledInFormation((int) $training->id);
        $isCoachOfTraining = (int) ($training->user_id ?? 0) === (int) $auth->id;
        $isPrivileged = $this->userHasPrivilegedTrainingAccess($auth);

        if (! $isMember && ! $isCoachOfTraining && ! $isPrivileged) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $requestedUserId = (int) $request->query('user_id', $auth->id);
        if ($requestedUserId !== (int) $auth->id) {
            if (! $isCoachOfTraining && ! $isPrivileged) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $subject = User::find($requestedUserId);
            if (! $subject || ! $subject->isEnrolledInFormation((int) $training->id)) {
                return response()->json(['message' => 'User not in this training'], 403);
            }
        }

        $targetUserId = $requestedUserId;

        $rows = DB::table('attendance_lists as al')
            ->join('attendances as a', 'a.id', '=', 'al.attendance_id')
            ->where('a.formation_id', $training->id)
            ->where('al.user_id', $targetUserId)
            ->orderByDesc('al.attendance_day')
            ->orderByDesc('al.id')
            ->select([
                'al.attendance_day',
                'al.morning',
                'al.lunch',
                'al.evening',
                'al.id as list_id',
                'a.staff_name',
            ])
            ->get();

        $byDay = [];
        foreach ($rows as $row) {
            $dayKey = (string) $row->attendance_day;
            if (isset($byDay[$dayKey])) {
                continue;
            }
            $byDay[$dayKey] = $row;
        }

        $days = [];
        foreach ($byDay as $row) {
            $m = $this->normalizeAttendanceSlot($row->morning ?? null);
            $l = $this->normalizeAttendanceSlot($row->lunch ?? null);
            $e = $this->normalizeAttendanceSlot($row->evening ?? null);

            $allPresent = ($m === 'present' && $l === 'present' && $e === 'present');
            $issues = [];
            foreach (['morning' => $m, 'lunch' => $l, 'evening' => $e] as $slot => $status) {
                if ($status !== 'present') {
                    $issues[] = $slot.'_'.$status;
                }
            }

            $days[] = [
                'date' => $row->attendance_day,
                'morning' => $m,
                'lunch' => $l,
                'evening' => $e,
                'summary' => $allPresent ? 'full_day' : 'partial',
                'issues' => $issues,
                'staff_name' => $row->staff_name ?? null,
            ];
        }

        usort($days, function ($a, $b) {
            return strcmp((string) $b['date'], (string) $a['date']);
        });

        return response()->json([
            'training' => [
                'id' => $training->id,
                'name' => $training->name,
                'promo' => $training->promo,
                'category' => $training->category,
            ],
            'user_id' => $targetUserId,
            'days' => array_values($days),
        ]);
    }

    private function userHasPrivilegedTrainingAccess(?User $user): bool
    {
        if (! $user) {
            return false;
        }
        $roles = $user->role ?? [];
        if (! is_array($roles)) {
            $roles = $roles ? [(string) $roles] : [];
        }
        $lower = array_map('strtolower', array_map('strval', $roles));

        return (bool) array_intersect($lower, ['admin', 'super_admin', 'moderateur', 'coach', 'studio_responsable']);
    }

    private function normalizeAttendanceSlot(?string $value): string
    {
        $s = strtolower(trim((string) ($value ?? '')));
        if ($s === '' || str_starts_with($s, 'present')) {
            return 'present';
        }
        if (in_array($s, ['absent', 'late', 'excused', 'pending'], true)) {
            return $s;
        }

        return 'present';
    }

    public function save(Request $request, AttendancePersistenceService $persistence)
    {
        $checkResult = $this->checkRequestedUser();
        if ($checkResult) {
            return $checkResult;
        }

        $request->validate([
            'attendance' => 'required|array|min:1',
            'attendance.*.attendance_id' => 'required|integer|exists:attendances,id',
            'attendance.*.user_id' => 'required|exists:users,id',
            'attendance.*.attendance_day' => 'required|date',
            'attendance.*.morning' => 'nullable|string|in:present,absent,late,excused,pending',
            'attendance.*.lunch' => 'nullable|string|in:present,absent,late,excused,pending',
            'attendance.*.evening' => 'nullable|string|in:present,absent,late,excused,pending',
            'attendance.*.note' => 'nullable|string',
        ]);

        $user = Auth::guard('sanctum')->user();

        foreach ($request->attendance as $data) {
            $attendanceId = isset($data['attendance_id']) && is_numeric($data['attendance_id'])
                ? (int) $data['attendance_id']
                : null;

            if ($attendanceId === null) {
                continue;
            }

            $attendanceUser = User::find($data['user_id']);
            if (!$attendanceUser) {
                continue;
            }

            $persistence->persistStudentRow(
                $attendanceId,
                (int) $data['user_id'],
                $data['attendance_day'],
                [
                    'morning' => $data['morning'] ?? 'absent',
                    'lunch' => $data['lunch'] ?? 'absent',
                    'evening' => $data['evening'] ?? 'absent',
                ],
                $data['note'] ?? null,
                $user->name ?? 'Staff',
            );
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Server-authoritative student check-in (slot + status from server clock).
     */
    public function checkIn(
        Request $request,
        AttendanceCheckInService $checkInService,
    ) {
        $checkResult = $this->checkRequestedUser();
        if ($checkResult) {
            return $checkResult;
        }

        $validated = $request->validate([
            'formation_id' => 'required|integer|exists:formations,id',
            'attendance_day' => 'nullable|date',
            'live_photo' => AttendanceCheckInService::livePhotoRules(),
        ]);

        $authUser = Auth::guard('sanctum')->user();
        $attendanceDay = $checkInService->resolveAttendanceDay($validated['attendance_day'] ?? null);

        return response()->json($checkInService->checkIn(
            $authUser,
            (int) $validated['formation_id'],
            $attendanceDay,
            $request->file('live_photo'),
        ));
    }

    /**
     * Read-only slot state for check-in UI (server clock).
     */
    public function slotStatus(Request $request, AttendanceCheckInService $checkInService)
    {
        $checkResult = $this->checkRequestedUser();
        if ($checkResult) {
            return $checkResult;
        }

        $validated = $request->validate([
            'formation_id' => 'required|integer|exists:formations,id',
            'attendance_day' => 'nullable|date',
        ]);

        $authUser = Auth::guard('sanctum')->user();
        $attendanceDay = $checkInService->resolveAttendanceDay($validated['attendance_day'] ?? null);

        return response()->json($checkInService->slotStatus(
            $authUser,
            (int) $validated['formation_id'],
            $attendanceDay,
        ));
    }

}
