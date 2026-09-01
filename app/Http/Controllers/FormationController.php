<?php

namespace App\Http\Controllers;

use App\Jobs\SendGeekLabCertificateEmail;
use App\Models\Attendance;
use App\Models\AttendanceListe;
use App\Models\Formation;
use App\Models\Note;
use App\Models\User;
use App\Services\AttendanceLegacyIdService;
use App\Services\AttendanceNoteService;
use App\Services\CertificatePdfGenerator;
use App\Services\CertificateTrackResolver;
use App\Services\CoachAttendanceSaveService;
use App\Services\DisciplineService;
use App\Services\GeekLabCertificateCodeAllocator;
use App\Services\StudentCheckInSlotService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use ZipArchive;

class FormationController extends Controller
{
    /**
     * Only admins can change the global certificate template.
     */
    private function canManageGlobalCertificateTemplate(): bool
    {
        $user = Auth::user();
        $roles = $user?->role;
        if (! is_array($roles)) {
            $roles = array_filter([$roles]);
        }

        return in_array('admin', $roles, true) || in_array('super_admin', $roles, true);
    }

    public function index(Request $request)
    {
        $coachId = $request->query('coach');
        $track = $request->query('track');
        $promo = $request->query('promo');

        $query = Formation::with('coach')->withCount('users');

        // dd($query->where('promo', $promo)->get());

        if (! empty($coachId)) {
            $query->where('user_id', $coachId);
        }

        if (! empty($track)) {
            $query->where('category', $track);
        }
        if (! empty($promo)) {
            $query->where('promo', $promo);
        }

        $trainings = $query->orderBy('created_at', 'desc')->get();

        $coaches = User::whereJsonContains('role', 'coach')->get();
        $tracks = Formation::select('category')->distinct()->pluck('category');
        $promos = Formation::select('promo')->distinct()->pluck('promo');

        return Inertia::render('admin/training/index', [
            'trainings' => $trainings,
            'coaches' => $coaches,
            'filters' => [
                'coach' => $coachId,
                'track' => $track,
                'promo' => $promo,
            ],
            'tracks' => $tracks,
            'promos' => $promos,
        ]);
    }

    // //////////////////////////////////////////
    public function show(Formation $training)
    {
        $usersNull = User::whereNull('formation_id')->get();

        // Get courses that belong to this training through exercises
        $courses = \App\Models\Course::whereHas('exercices', function ($query) use ($training) {
            $query->where('training_id', $training->id);
        })->with('exercices')->get();

        $training->load('coach', 'users');

        // Attach the discipline score to every enrolled user so the frontend
        // can display the attendance percentage without extra API calls.
        $disciplineService = new \App\Services\DisciplineService;
        $training->users->each(function (User $user) use ($disciplineService) {
            $user->discipline = $disciplineService->calculateDisciplineScore($user);
        });

        return inertia('admin/training/[id]', [
            'training' => $training,
            'usersNull' => $usersNull,
            'courses' => $courses,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'img' => 'nullable|string|max:255',
            'certificate_template' => 'nullable|image|max:5120',
            'category' => 'required|string|max:100',
            'start_time' => 'required|date',
            'end_time' => 'nullable|date',
            'user_id' => 'required|exists:users,id',
            'promo' => 'nullable|string|max:50',
        ]);
        // dd($request->all());

        if ($request->hasFile('certificate_template')) {
            if (! $this->canManageGlobalCertificateTemplate()) {
                abort(403, 'Only admins can update the certificate template.');
            }

            $file = $request->file('certificate_template');
            $targetDir = public_path('assets/images');
            if (! is_dir($targetDir)) {
                @mkdir($targetDir, 0755, true);
            }

            // Always overwrite the global default template
            $targetPath = $targetDir.DIRECTORY_SEPARATOR.'certif.jpg';
            $file->move($targetDir, 'certif.jpg');

            // Keep DB column aligned (optional; not used for rendering now)
            $validated['certificate_template'] = 'assets/images/certif.jpg';
        }

        Formation::create($validated);

        return back()->with('success', 'Training added successfully!');
    }

    // ///////////////////
    public function addStudent(Formation $training, Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|exists:users,id',
        ]);

        $user = User::find($validated['student_id']);
        if ($user) {
            $user->formation_id = $training->id;
            $user->save();
        }

        return back()->with('success', 'Student added');
    }

    public function removeStudent(Formation $training, User $user)
    {
        if ($user->formation_id === $training->id) {
            $user->formation_id = null;
            $user->save();
        }

        return back()->with('success', 'Student removed');
    }

    // attendance — read-only load for calendar open (no rows created until explicit Save)
    public function attendance(Request $request, StudentCheckInSlotService $studentCheckInSlotService, AttendanceLegacyIdService $legacyIdService)
    {
        $request->validate([
            'formation_id' => 'required|integer|exists:formations,id',
            'attendance_day' => 'required|date',
        ]);

        $attendance = Attendance::where('formation_id', $request->formation_id)
            ->whereDate('attendance_day', $request->attendance_day)
            ->first();

        if (! $attendance) {
            return response()->json([
                'attendance_id' => null,
                'lists' => [],
                'staff_name' => null,
            ]);
        }

        // Normalize legacy IDs: if non-numeric, migrate to fresh numeric id
        $attendance = $legacyIdService->ensureNumericId($attendance, Auth::user()->name ?? 'Staff');

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

        $attendanceDay = $request->attendance_day;

        $lists = $lists->map(function ($row) use ($notesByUser, $studentCheckInSlotService, $attendanceDay) {
            $note = $notesByUser[$row->user_id] ?? null;
            $row->note = $note;
            $row->student_marked_slots = $studentCheckInSlotService->studentMarkedSlots(
                $attendanceDay,
                $note,
                [
                    'morning' => $row->morning,
                    'lunch' => $row->lunch,
                    'evening' => $row->evening,
                ],
            );

            return $row;
        });

        return response()->json([
            'attendance_id' => $attendance->id,
            'lists' => $lists,
            'staff_name' => $attendance->staff_name,
        ]);
    }

    // List attendance events for a formation (calendar markers)
    public function attendanceEvents(Formation $training)
    {
        $events = Attendance::where('formation_id', $training->id)
            ->orderByDesc('attendance_day')
            ->get(['attendance_day', 'staff_name'])
            ->map(function ($a) {
                return [
                    'date' => $a->attendance_day,
                    'title' => 'Saved by '.($a->staff_name ?? 'staff'),
                    'color' => '#FACC15', // yellow-400
                ];
            });

        return response()->json(['events' => $events]);
    }

    // attendance list
    public function save(Request $request, CoachAttendanceSaveService $coachAttendanceSaveService, AttendanceLegacyIdService $legacyIdService)
    {
        $request->validate([
            'attendance' => 'required|array|min:1',
            'formation_id' => 'nullable|integer|exists:formations,id',
            'attendance.*.attendance_id' => 'nullable|integer|exists:attendances,id',
            'attendance.*.user_id' => 'required|exists:users,id',
            'attendance.*.attendance_day' => 'required|date',
            'attendance.*.morning' => 'nullable|string|in:present,absent,late,excused,pending',
            'attendance.*.lunch' => 'nullable|string|in:present,absent,late,excused,pending',
            'attendance.*.evening' => 'nullable|string|in:present,absent,late,excused,pending',
            'attendance.*.note' => 'nullable|string',
        ]);

        $lastAttendanceId = null;
        $disciplineService = new DisciplineService;
        $attendanceNoteService = new AttendanceNoteService;
        $staffName = Auth::user()->name ?? 'Staff';

        $userIds = collect($request->attendance)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $sharedAttendanceId = null;
        foreach ($request->attendance as $row) {
            if (isset($row['attendance_id']) && is_numeric($row['attendance_id'])) {
                $sharedAttendanceId = (int) $row['attendance_id'];
                break;
            }
        }

        if ($sharedAttendanceId !== null) {
            $attendance = Attendance::find($sharedAttendanceId);
            if ($attendance) {
                $sharedAttendanceId = (int) $legacyIdService->ensureNumericId($attendance, $staffName)->id;
            }
        } else {
            $formationId = $request->input('formation_id');
            $batchDay = $request->attendance[0]['attendance_day'] ?? null;
            if ($formationId && $batchDay) {
                $attendance = Attendance::firstOrCreate(
                    [
                        'formation_id' => (int) $formationId,
                        'attendance_day' => $batchDay,
                    ],
                    ['staff_name' => $staffName],
                );
                $sharedAttendanceId = (int) $legacyIdService->ensureNumericId($attendance, $staffName)->id;
            }
        }

        $saveContextsByAttendanceId = [];
        if ($sharedAttendanceId !== null) {
            $saveContextsByAttendanceId[$sharedAttendanceId] = $coachAttendanceSaveService->preloadSaveContext(
                $sharedAttendanceId,
                $userIds,
            );
        }

        foreach ($request->attendance as $data) {
            $attendanceId = isset($data['attendance_id']) && is_numeric($data['attendance_id'])
                ? (int) $data['attendance_id']
                : $sharedAttendanceId;

            if ($attendanceId === null) {
                $formationId = $request->input('formation_id');
                if (! $formationId) {
                    continue;
                }

                $attendance = Attendance::firstOrCreate(
                    [
                        'formation_id' => (int) $formationId,
                        'attendance_day' => $data['attendance_day'],
                    ],
                    ['staff_name' => $staffName],
                );
                $attendanceId = (int) $legacyIdService->ensureNumericId($attendance, $staffName)->id;
            } elseif ($attendanceId !== $sharedAttendanceId) {
                $attendance = Attendance::find($attendanceId);
                if ($attendance) {
                    $attendanceId = (int) $legacyIdService->ensureNumericId($attendance, $staffName)->id;
                }
            }

            if (! isset($saveContextsByAttendanceId[$attendanceId])) {
                $saveContextsByAttendanceId[$attendanceId] = $coachAttendanceSaveService->preloadSaveContext(
                    $attendanceId,
                    $userIds,
                );
            }

            $lastAttendanceId = $attendanceId;

            $user = User::find($data['user_id']);
            if (! $user) {
                continue;
            }

            $oldDiscipline = $disciplineService->calculateDisciplineScore($user);

            $coachSlots = [
                'morning' => $data['morning'] ?? 'absent',
                'lunch' => $data['lunch'] ?? 'absent',
                'evening' => $data['evening'] ?? 'absent',
            ];

            $payload = [
                'attendance_day' => $data['attendance_day'],
                ...$coachAttendanceSaveService->resolveSlotsForSave(
                    $attendanceId,
                    (int) $data['user_id'],
                    $data['attendance_day'],
                    $coachSlots,
                    $saveContextsByAttendanceId[$attendanceId],
                ),
            ];

            AttendanceListe::updateOrCreate(
                [
                    'attendance_id' => $attendanceId,
                    'user_id' => $data['user_id'],
                ],
                $payload
            );

            $disciplineService->processDisciplineChange($user, $oldDiscipline);

            $attendanceNoteService->syncNotes(
                (int) $data['user_id'],
                $attendanceId,
                $data['note'] ?? null,
                Auth::user()->name ?? 'Staff',
            );
        }

        if (! empty($lastAttendanceId)) {
            Attendance::where('id', $lastAttendanceId)->update(['staff_name' => Auth::user()->name]);
        }

        return response()->json(['status' => 'ok', 'attendance_id' => $lastAttendanceId]);
    }

    // Update formation
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'img' => 'nullable|string|max:255',
            'certificate_template' => 'nullable|image|max:5120',
            'category' => 'required|string|max:100',
            'start_time' => 'required|date',
            'end_time' => 'nullable|date',
            'user_id' => 'nullable|exists:users,id',
            'promo' => 'nullable|string|max:50',
            'is_active' => 'sometimes|boolean',
        ]);

        $formation = Formation::findOrFail($id);

        if ($request->hasFile('certificate_template')) {
            if (! $this->canManageGlobalCertificateTemplate()) {
                abort(403, 'Only admins can update the certificate template.');
            }

            $file = $request->file('certificate_template');
            $targetDir = public_path('assets/images');
            if (! is_dir($targetDir)) {
                @mkdir($targetDir, 0755, true);
            }

            // Always overwrite the global default template
            $file->move($targetDir, 'certif.jpg');

            $validated['certificate_template'] = 'assets/images/certif.jpg';
        } else {
            unset($validated['certificate_template']);
        }

        // FormData may send "0"/"1"; always normalize when the field is present
        if ($request->has('is_active')) {
            $validated['is_active'] = $request->boolean('is_active');
        }

        $formation->update($validated);

        return back()->with('success', 'Formation updated successfully!');
    }

    // Delete formation — unassign enrolled students first (users.formation_id FK is ON DELETE NO ACTION)
    public function destroy($id)
    {
        $formation = Formation::findOrFail($id);

        DB::transaction(function () use ($formation) {
            User::where('formation_id', $formation->id)->update(['formation_id' => null]);

            if (Schema::hasTable('formation_user')) {
                DB::table('formation_user')->where('formation_id', $formation->id)->delete();
            }

            $formation->delete();
        });

        return back()->with('success', 'Formation deleted successfully!');
    }

    // Bulk update users roles, program status, and handicap
    public function bulkUpdateUsers(Formation $training, Request $request)
    {
        $validated = $request->validate([
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'required|exists:users,id',
            'roles' => 'nullable|array',
            'roles.*' => 'nullable|string|in:student,coach,admin,super_admin,moderateur,studio_responsable,responsable_studio,coworker,pro,recruiter',
            'program_status' => 'nullable|in:active,certified,not_certified,left',
            'has_handicap' => 'nullable|in:0,1',
        ]);

        $users = User::whereIn('id', $validated['user_ids'])
            ->where('formation_id', $training->id)
            ->get();

        if ($users->isEmpty()) {
            return back()->with('error', 'No valid users found for this training.');
        }

        $updated = 0;
        foreach ($users as $user) {
            $updateData = [];

            if ($request->has('roles') && ! empty($validated['roles'])) {
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

            if (! empty($updateData)) {
                $user->update($updateData);
                $updated++;
            }
        }

        return back()->with('success', "Successfully updated {$updated} user(s).");
    }

    /**
     * Admin or assigned coach only.
     */
    private function canPrintCertificates(Formation $training): bool
    {
        $user = Auth::user();
        if (! $user) {
            return false;
        }

        $roles = is_array($user->role) ? $user->role : [(string) $user->role];
        $roles = array_filter(array_map('strval', $roles));

        if (count(array_intersect($roles, ['admin', 'super_admin'])) > 0) {
            return true;
        }

        if (in_array('coach', $roles, true) && (int) $training->user_id === (int) $user->id) {
            return true;
        }

        return false;
    }

    /**
     * Generate certificates (PDF), store per student, return ZIP download.
     * Stores: storage/app/public/certificates/{name-slug}.pdf (e.g. yahya-moussair.pdf)
     * Supports standard and GeekLab templates (GeekLab also assigns C-ID codes).
     */
    public function downloadCertificatesZip(
        Formation $training,
        Request $request,
        CertificateTrackResolver $trackResolver,
        CertificatePdfGenerator $pdfGenerator,
        GeekLabCertificateCodeAllocator $codeAllocator,
    ) {
        if (! $this->canPrintCertificates($training)) {
            abort(403, 'You are not allowed to print certificates for this training.');
        }

        $validated = $request->validate([
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'required|exists:users,id',
            'issued_date' => 'required|date',
        ]);

        $issuedCarbon = Carbon::parse($validated['issued_date'])->startOfDay();
        $issuedDateFormatted = $issuedCarbon->format('d/m/Y');
        $isGeekLab = $trackResolver->isGeekLabTraining($training->name);
        $trainingName = (string) $training->name;

        $users = User::whereIn('id', $validated['user_ids'])
            ->where('formation_id', $training->id)
            ->get();

        if ($users->isEmpty()) {
            return $this->certificateZipErrorResponse($request, 'No valid users found for this training.', 422);
        }

        $tmpZipPath = storage_path('app/tmp/certificates-'.$training->id.'-'.now()->format('YmdHis').'-'.Str::random(8).'.zip');
        if (! is_dir(dirname($tmpZipPath))) {
            @mkdir(dirname($tmpZipPath), 0755, true);
        }

        $zip = new ZipArchive;
        if ($zip->open($tmpZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return $this->certificateZipErrorResponse($request, 'Failed to create ZIP archive.', 500);
        }

        $savedCount = 0;
        $skipped = [];
        $usedZipNames = [];

        foreach ($users as $user) {
            $track = $isGeekLab
                ? $trackResolver->resolveForTraining($user->field ?? null, $trainingName)
                : $trackResolver->resolve($user->field ?? null);

            if ($track === null) {
                $skipped[] = [
                    'id' => $user->id,
                    'name' => (string) $user->name,
                    'reason' => 'Le champ doit être « coding » ou « media ».',
                ];

                continue;
            }

            // Stored + ZIP entry as yahya-moussair.pdf
            $pdfFileName = $this->certificatePdfFileName($user);
            $pdfStoragePath = 'certificates/'.$pdfFileName;
            $zipEntryName = $this->uniqueZipEntryName($pdfFileName, $usedZipNames, $user);

            try {
                $certificateCode = $isGeekLab
                    ? $codeAllocator->resolveForUser($user->certificate_code ?? null)
                    : null;

                $pdfBytes = $pdfGenerator->generate(
                    $track,
                    (string) ($user->name ?? ''),
                    $issuedDateFormatted,
                    $certificateCode,
                );

                if ($pdfBytes === null || $pdfBytes === '') {
                    $skipped[] = [
                        'id' => $user->id,
                        'name' => (string) $user->name,
                        'reason' => 'Échec de génération du PDF.',
                    ];

                    continue;
                }

                $this->storeCertificatePdf($user, $pdfStoragePath, $pdfBytes);

                $certFields = [
                    'status' => 'Certified',
                    'certified_at' => $issuedCarbon,
                    'certified_training_id' => (int) $training->id,
                    'certificate_share_token' => $user->certificate_share_token ?: Str::random(48),
                    'certificate_pdf_path' => $pdfStoragePath,
                ];
                if ($isGeekLab && $certificateCode !== null) {
                    $certFields['certificate_code'] = $certificateCode;
                }

                $user->forceFill($certFields)->save();

                $zip->addFromString($zipEntryName, $pdfBytes);
                $savedCount++;
            } catch (\Throwable $e) {
                Log::error('Failed to generate certificate', [
                    'training_id' => $training->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);

                $skipped[] = [
                    'id' => $user->id,
                    'name' => (string) $user->name,
                    'reason' => 'Erreur serveur lors de la génération.',
                ];
            }
        }

        $zip->close();

        if ($savedCount === 0) {
            @unlink($tmpZipPath);

            return $this->certificateZipErrorResponse(
                $request,
                'Aucun certificat généré.',
                422,
                ['skipped' => $skipped],
            );
        }

        return response()
            ->download($tmpZipPath, 'certificats-'.$training->id.'.zip', [
                'Content-Type' => 'application/zip',
                'X-Certificate-Warnings' => json_encode($skipped, JSON_UNESCAPED_UNICODE),
            ])
            ->deleteFileAfterSend(true);
    }

    /**
     * GeekLab: generate PDFs, store them, mark students Certified, queue email jobs.
     */
    public function emailGeekLabCertificates(
        Formation $training,
        Request $request,
        CertificateTrackResolver $trackResolver,
        CertificatePdfGenerator $pdfGenerator,
        GeekLabCertificateCodeAllocator $codeAllocator,
    ) {
        if (! $this->canPrintCertificates($training)) {
            abort(403, 'You are not allowed to print certificates for this training.');
        }

        if (! $trackResolver->isGeekLabTraining($training->name)) {
            return $this->certificateZipErrorResponse(
                $request,
                'Cet endpoint est réservé aux formations GeekLab.',
                422,
            );
        }

        $validated = $request->validate([
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'required|exists:users,id',
            'issued_date' => 'required|date',
        ]);

        $issuedCarbon = Carbon::parse($validated['issued_date'])->startOfDay();
        $issuedDateFormatted = $issuedCarbon->format('d/m/Y');

        $users = User::whereIn('id', $validated['user_ids'])
            ->where('formation_id', $training->id)
            ->get();

        if ($users->isEmpty()) {
            return $this->certificateZipErrorResponse($request, 'No valid users found for this training.', 422);
        }

        $queuedCount = 0;
        $skipped = [];
        $trainingName = (string) $training->name;

        foreach ($users as $user) {
            $track = $trackResolver->resolveForTraining($user->field ?? null, $trainingName);
            if ($track === null) {
                $skipped[] = [
                    'id' => $user->id,
                    'name' => (string) $user->name,
                    'reason' => 'Le champ doit être « coding » ou « media ».',
                ];

                continue;
            }

            $email = trim((string) ($user->email ?? ''));
            if ($email === '') {
                $skipped[] = [
                    'id' => $user->id,
                    'name' => (string) $user->name,
                    'reason' => 'Aucune adresse e-mail.',
                ];

                continue;
            }

            $pdfFileName = $this->certificatePdfFileName($user);
            $pdfStoragePath = 'certificates/'.$pdfFileName;

            try {
                $certificateCode = $codeAllocator->resolveForUser($user->certificate_code ?? null);

                $pdfBytes = $pdfGenerator->generate(
                    $track,
                    (string) ($user->name ?? ''),
                    $issuedDateFormatted,
                    $certificateCode,
                );

                if ($pdfBytes === null || $pdfBytes === '') {
                    $skipped[] = [
                        'id' => $user->id,
                        'name' => (string) $user->name,
                        'reason' => 'Échec de génération du PDF.',
                    ];

                    continue;
                }

                $this->storeCertificatePdf($user, $pdfStoragePath, $pdfBytes);

                // Certify immediately when PDF is stored and the email job is queued.
                $user->forceFill([
                    'status' => 'Certified',
                    'certified_at' => $issuedCarbon,
                    'certified_training_id' => (int) $training->id,
                    'certificate_share_token' => $user->certificate_share_token ?: Str::random(48),
                    'certificate_pdf_path' => $pdfStoragePath,
                    'certificate_code' => $certificateCode,
                ])->save();

                SendGeekLabCertificateEmail::dispatch($user, $pdfStoragePath, $trainingName);
                $queuedCount++;
            } catch (\Throwable $e) {
                Log::error('Failed to generate/queue GeekLab certificate', [
                    'training_id' => $training->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);

                $skipped[] = [
                    'id' => $user->id,
                    'name' => (string) $user->name,
                    'reason' => 'Erreur serveur lors de la génération.',
                ];
            }
        }

        if ($queuedCount === 0) {
            return $this->certificateZipErrorResponse(
                $request,
                'Aucun certificat envoyé.',
                422,
                ['skipped' => $skipped],
            );
        }

        return response()->json([
            'success' => true,
            'queued' => $queuedCount,
            'skipped' => $skipped,
            'message' => $queuedCount.' certificat(s) généré(s) et e-mail(s) mis en file d’attente.',
        ]);
    }

    /**
     * Filename like yahya-moussair.pdf (fallback certificat-{id}.pdf).
     * Appends -{id} when another user already owns the same slug path.
     */
    private function certificatePdfFileName(User $user): string
    {
        $slug = Str::slug((string) ($user->name ?? ''), '-');
        if ($slug === '') {
            $slug = 'certificat-'.$user->id;
        }

        $fileName = $slug.'.pdf';
        $path = 'certificates/'.$fileName;

        $takenByOther = User::query()
            ->where('certificate_pdf_path', $path)
            ->where('id', '!=', $user->id)
            ->exists();

        if ($takenByOther) {
            $fileName = $slug.'-'.$user->id.'.pdf';
        }

        return $fileName;
    }

    /**
     * Ensure ZIP entry names stay unique within one archive.
     *
     * @param  array<string, true>  $usedNames
     */
    private function uniqueZipEntryName(string $fileName, array &$usedNames, User $user): string
    {
        if (! isset($usedNames[$fileName])) {
            $usedNames[$fileName] = true;

            return $fileName;
        }

        $slug = pathinfo($fileName, PATHINFO_FILENAME);
        $unique = $slug.'-'.$user->id.'.pdf';
        $usedNames[$unique] = true;

        return $unique;
    }

    /**
     * Store PDF under the name-based path and remove any previous certificate file.
     */
    private function storeCertificatePdf(User $user, string $pdfStoragePath, string $pdfBytes): void
    {
        $disk = Storage::disk('public');
        $previous = $user->certificate_pdf_path;
        $legacyIdPath = 'certificates/'.$user->id.'.pdf';

        $disk->put($pdfStoragePath, $pdfBytes);

        foreach (array_unique(array_filter([$previous, $legacyIdPath])) as $oldPath) {
            if ($oldPath !== $pdfStoragePath && $disk->exists($oldPath)) {
                $disk->delete($oldPath);
            }
        }
    }

    private function certificateZipErrorResponse(Request $request, string $message, int $status, array $extra = [])
    {
        if ($request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
            return response()->json(array_merge(['error' => $message], $extra), $status);
        }

        return back()->with('error', $message);
    }
}
