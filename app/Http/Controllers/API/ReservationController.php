<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Models\Reservation;
use App\Models\ReservationCowork;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

class ReservationController extends Controller
{
    private const ACCESS_BYPASS_ROLES = ['admin', 'super_admin', 'moderateur', 'coach', 'studio_responsable'];


    public function users()
    {
        $users = User::query()
            ->select('id', 'name', 'image')
            ->where('role', '!=', 'admin')
            ->whereJsonDoesntContain('role', 'admin')
            ->orderBy('name')
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'image' => $user->image,
                ];
            })
            ->values();

        return response()->json([
            'users' => $users,
        ]);
    }
    public function index(Request $request)
    {
        // Check authentication
        $checkResult = $this->checkRequestedUser();
        if ($checkResult) {
            return $checkResult;
        }
        $user = Auth::guard('sanctum')->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $reservations = Reservation::query()
            ->where('user_id', $user->id)
            ->with(['studio:id,name'])
            ->orderByDesc('day')
            ->orderByDesc('start')
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($reservation) {
                return [
                    'id' => $reservation->id,
                    'title' => $reservation->title,
                    'description' => $reservation->description,
                    'day' => $reservation->day,
                    'start' => $reservation->start,
                    'end' => $reservation->end,
                    'type' => $reservation->type,
                    'approved' => (bool) $reservation->approved,
                    'canceled' => (bool) $reservation->canceled,
                    'studio_name' => $reservation->studio?->name,
                    'created_at' => $reservation->created_at ? (is_string($reservation->created_at) ? $reservation->created_at : $reservation->created_at->toDateTimeString()) : null,
                    'updated_at' => $reservation->updated_at ? (is_string($reservation->updated_at) ? $reservation->updated_at : $reservation->updated_at->toDateTimeString()) : null,
                ];
            })
            ->values()
            ->toArray();

        return response()->json(['reservations' => $reservations]);
    }
    public function indexcowork(Request $request)
    {
        // Check authentication
        $checkResult = $this->checkRequestedUser();
        if ($checkResult) {
            return $checkResult;
        }
        $user = Auth::guard('sanctum')->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $reservations = ReservationCowork::query()
            ->where('user_id', $user->id)
            ->orderByDesc('day')
            ->orderByDesc('start')
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($reservation) {
                $deskId = $reservation->table;

                return [
                    'id' => $reservation->id,
                    'title' => 'Desk / spot #'.$deskId,
                    'desk_id' => $deskId,
                    'seats' => $reservation->seats ?? 1,
                    'start' => $reservation->start,
                    'end' => $reservation->end,
                    'day' => $reservation->day,
                    'approved' => (bool) $reservation->approved,
                    'canceled' => (bool) $reservation->canceled,
                    'passed' => (bool) ($reservation->passed ?? false),
                    'created_at' => $reservation->created_at ? (is_string($reservation->created_at) ? $reservation->created_at : $reservation->created_at->toDateTimeString()) : null,
                    'updated_at' => $reservation->updated_at ? (is_string($reservation->updated_at) ? $reservation->updated_at : $reservation->updated_at->toDateTimeString()) : null,
                ];
            })
            ->values()
            ->toArray();

        return response()->json(['reservations' => $reservations]);
    }

    public function show(int $id)
    {
        if (!Schema::hasTable('reservations')) {
            return response()->json(['error' => 'reservations table not found'], 500);
        }

        $reservationData = DB::table('reservations as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->leftJoin('users as a', 'a.id', '=', 'r.approve_id')
            ->leftJoin('studios as s', 's.id', '=', 'r.studio_id')
            ->where('r.id', $id)
            ->select(
                'r.*',
                'u.name as user_name',
                'u.email as user_email',
                'u.phone as user_phone',
                'u.image as user_avatar',
                'a.name as approver_name',
                's.name as studio_name'
            )
            ->first();

        if (!$reservationData) {
            return response()->json(['error' => 'Reservation not found'], 404);
        }

        $authUser = Auth::guard('sanctum')->user();
        if (!$authUser) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }
        if (!$this->reservationVisibleToUser($authUser, (int) ($reservationData->user_id ?? 0))) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Normalize user avatar path
        $userAvatar = $reservationData->user_avatar ?? null;
        if ($userAvatar && !Str::startsWith($userAvatar, ['http://', 'https://', 'storage/'])) {
            $userAvatar = 'img/profile/' . ltrim($userAvatar, '/');
        }

        // Get approver name (prefer joined value; fallback to direct lookup)
        $approverName = $reservationData->approver_name ?? null;
        if (!$approverName && $reservationData->approve_id) {
            $approver = DB::table('users')->where('id', $reservationData->approve_id)->value('name');
            $approverName = $approver ?: null;
        }

        // Determine status
        $status = 'ended';
        if ($reservationData->canceled) {
            $status = 'cancelled';
        } elseif ($reservationData->approved && $reservationData->day > now()->toDateString()) {
            $status = 'upcoming';
        } elseif ($reservationData->approved && $reservationData->day == now()->toDateString()) {
            $status = 'active';
        }

        // Equipments
        $equipments = [];
        if (Schema::hasTable('reservation_equipment') && Schema::hasTable('equipment')) {
            $equipments = DB::table('reservation_equipment as re')
                ->leftJoin('equipment as e', 'e.id', '=', 're.equipment_id')
                ->leftJoin('equipment_types as et', 'et.id', '=', 'e.equipment_type_id')
                ->where('re.reservation_id', $id)
                ->select(
                    'e.id',
                    'e.reference',
                    'e.mark',
                    'e.image',
                    'et.name as type_name',
                    're.day as equipment_day',
                    're.start as equipment_start',
                    're.end as equipment_end'
                )
                ->get()
                ->map(function ($equipment) {
                    $img = $equipment->image ?? null;
                    if ($img && !Str::startsWith($img, ['http://', 'https://', 'storage/'])) {
                        $img = 'img/equipment/' . ltrim($img, '/');
                    }
                    return [
                        'id' => $equipment->id,
                        'reference' => $equipment->reference,
                        'mark' => $equipment->mark,
                        'name' => $equipment->reference . ' - ' . $equipment->mark,
                        'image' => $img,
                        'type_name' => $equipment->type_name,
                        'day' => $equipment->equipment_day,
                        'start' => $equipment->equipment_start,
                        'end' => $equipment->equipment_end,
                    ];
                });
        }

        // Team Members
        $members = [];
        if (Schema::hasTable('reservation_teams')) {
            $members = DB::table('reservation_teams as rt')
                ->leftJoin('users as u', 'u.id', '=', 'rt.user_id')
                ->where('rt.reservation_id', $id)
                ->select('u.name', 'u.email', 'u.image as avatar', 'u.phone')
                ->get()
                ->map(function ($member) {
                    $img = $member->avatar ?? null;
                    if ($img && !Str::startsWith($img, ['http://', 'https://', 'storage/'])) {
                        $img = 'img/profile/' . ltrim($img, '/');
                    }
                    return [
                        'name' => $member->name,
                        'email' => $member->email,
                        'phone' => $member->phone,
                        'avatar' => $img,
                        'role' => 'Team Member'
                    ];
                });
        }

        $reservation = [
            'id' => $reservationData->id,
            'title' => $reservationData->title,
            'description' => $reservationData->description,
            'type' => $reservationData->type,
            'day' => $reservationData->day,
            'start' => $reservationData->start,
            'end' => $reservationData->end,
            'status' => $status,
            'approved' => (bool) $reservationData->approved,
            'canceled' => (bool) $reservationData->canceled,
            'passed' => (bool) $reservationData->passed,
            'start_signed' => (bool) $reservationData->start_signed,
            'end_signed' => (bool) $reservationData->end_signed,
            'notes' => $reservationData->description,
            'user_name' => $reservationData->user_name,
            'user_email' => $reservationData->user_email,
            'user_phone' => $reservationData->user_phone,
            'user_avatar' => $userAvatar,
            'studio_name' => $reservationData->studio_name,
            'approver_name' => $approverName,
            'equipments' => $equipments,
            'members' => $members,
        ];

        return response()->json([
            'reservation' => $reservation
        ]);
    }



    public function storemobile(Request $request)
    {
        // Check authentication
        $checkResult = $this->checkRequestedUser();
        if ($checkResult) {
            return $checkResult;
        }

        $authUser = Auth::guard('sanctum')->user();
        if (! $authUser) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'studio_id' => 'required|integer|exists:studios,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'day' => 'required|date',
            'start' => 'required|string',
            'end' => 'required|string',
            'team_members' => 'nullable|array',
            'team_members.*' => 'integer|exists:users,id',
            'equipment' => 'nullable|array',
            'equipment.*' => 'integer|exists:equipment,id',
            'user_id' => 'nullable|integer',
        ]);

        // Never trust client-supplied user_id as the reservation owner.
        $validated['user_id'] = (int) $authUser->id;

        if (!$this->userHasAccessFlag($authUser, 'access_studio')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to reserve a studio.',
            ], 403);
        }

        try {
            $reservationId = null;

            DB::transaction(function () use ($validated, &$reservationId) {
                $lastId = (int) (DB::table('reservations')->max('id') ?? 0);
                $reservationId = $lastId + 1;

                DB::table('reservations')->insert([
                    'id' => $reservationId,
                    'studio_id' => $validated['studio_id'],
                    'user_id' => $validated['user_id'],
                    'title' => $validated['title'],
                    'description' => $validated['description'] ?? '',
                    'day' => $validated['day'],
                    'start' => $validated['start'],
                    'end' => $validated['end'],
                    'type' => 'studio',
                    'approved' => 0,
                    'canceled' => 0,
                    'passed' => 0,
                    'start_signed' => 0,
                    'end_signed' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Send Expo push notification to studio responsables
                try {
                    \Illuminate\Support\Facades\Log::info('Attempting to send push notification for reservation (API)', [
                        'reservation_id' => $reservationId,
                        'user_id' => $validated['user_id'],
                    ]);
                    
                    // Get all users who should be notified (studio responsables and admins)
                    // Roles can be stored as string or JSON array, so we need to check both
                    $notifyUsers = \App\Models\User::where(function($query) {
                        $query->where('role', 'studio_responsable')
                            ->orWhereJsonContains('role', 'studio_responsable')
                            ->orWhere('role', 'admin')
                            ->orWhereJsonContains('role', 'admin')
                            ->orWhere('role', 'super_admin')
                            ->orWhereJsonContains('role', 'super_admin');
                    })->get();
                    
                    \Illuminate\Support\Facades\Log::info('Found users to notify for reservation (API)', [
                        'count' => $notifyUsers->count(),
                        'ids' => $notifyUsers->pluck('id')->toArray(),
                        'emails' => $notifyUsers->pluck('email')->toArray(),
                    ]);
                    
                    $reservationUser = \App\Models\User::find($validated['user_id']);
                    
                    if ($reservationUser) {
                        $reservationTitle = $validated['title'] ?? "Reservation #{$reservationId}";
                        $reservationMessage = "{$reservationUser->name} submitted a new reservation: {$reservationTitle}";
                        
                        foreach ($notifyUsers as $notifyUser) {
                            // Refresh user to get latest expo_push_token
                            $notifyUser->refresh();
                            
                            \Illuminate\Support\Facades\Log::info('Processing user for push notification (API)', [
                                'user_id' => $notifyUser->id,
                                'user_email' => $notifyUser->email,
                                'has_expo_token' => !empty($notifyUser->expo_push_token),
                                'token_preview' => $notifyUser->expo_push_token ? substr($notifyUser->expo_push_token, 0, 30) . '...' : null,
                            ]);
                            
                            if ($notifyUser->expo_push_token) {
                                $pushService = app(\App\Services\ExpoPushNotificationService::class);
                                
                                \Illuminate\Support\Facades\Log::info('Sending push notification for reservation (API)', [
                                    'notify_user_id' => $notifyUser->id,
                                    'reservation_id' => $reservationId,
                                    'user_id' => $validated['user_id'],
                                    'message' => $reservationMessage,
                                ]);
                                
                                $success = $pushService->sendToUser($notifyUser, 'New Reservation', $reservationMessage, [
                                    'type' => 'reservation',
                                    'reservation_id' => $reservationId,
                                    'user_id' => $validated['user_id'],
                                    'user_name' => $reservationUser->name,
                                    'title' => $reservationTitle,
                                    'day' => $validated['day'],
                                    'start' => $validated['start'],
                                    'end' => $validated['end'],
                                ]);
                                
                                if (!$success) {
                                    \Illuminate\Support\Facades\Log::warning('Push notification send returned false for reservation (API)', [
                                        'notify_user_id' => $notifyUser->id,
                                        'reservation_id' => $reservationId,
                                    ]);
                                } else {
                                    \Illuminate\Support\Facades\Log::info('Push notification sent successfully for reservation (API)', [
                                        'notify_user_id' => $notifyUser->id,
                                        'reservation_id' => $reservationId,
                                    ]);
                                }
                            } else {
                                \Illuminate\Support\Facades\Log::info('User does not have Expo push token, skipping push notification (API)', [
                                    'user_id' => $notifyUser->id,
                                    'user_email' => $notifyUser->email,
                                ]);
                            }
                        }
                    } else {
                        \Illuminate\Support\Facades\Log::warning('Reservation user not found for push notification (API)', [
                            'user_id' => $validated['user_id'],
                        ]);
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Failed to send Expo push notification for reservation (API)', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'reservation_id' => $reservationId,
                    ]);
                    // Don't fail reservation creation if push fails
                }


                // Insert team members
                if (!empty($validated['team_members'])) {
                    $teamData = array_map(function ($userId) use ($reservationId) {
                        return [
                            'reservation_id' => $reservationId,
                            'user_id' => $userId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }, $validated['team_members']);

                    DB::table('reservation_teams')->insert($teamData);
                }

                // Insert equipment
                if (!empty($validated['equipment'])) {
                    $equipmentData = array_map(function ($equipmentId) use ($validated, $reservationId) {
                        return [
                            'reservation_id' => $reservationId,
                            'equipment_id' => $equipmentId,
                            'day' => $validated['day'],
                            'start' => $validated['start'],
                            'end' => $validated['end'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }, $validated['equipment']);

                    DB::table('reservation_equipment')->insert($equipmentData);
                }
            });

            // Return JSON for API
            return response()->json([
                'success' => true,
                'reservation_id' => $reservationId,
                'message' => 'Reservation created successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create reservation: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function getEquipment()
    {
        // Check authentication
        $checkResult = $this->checkRequestedUser();
        if ($checkResult) {
            return $checkResult;
        }
        $equipment = Equipment::with('equipmentType')
            ->where('state', 1) // Only available equipment
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($e) {
                $img = $e->image;
                if ($img && !Str::startsWith($img, ['http://', 'https://', 'storage/'])) {
                    $img = 'storage/img/equipment/' . ltrim($img, '/');
                }
                return [
                    'id' => $e->id,
                    'reference' => $e->reference,
                    'mark' => $e->mark,
                    'type' => $e->equipmentType->name ?? 'other',
                    'image' => $img ? asset($img) : null,
                ];
            })
            ->values()
            ->toArray();

        return response()->json($equipment);
    }

    public function getUserss()
    {
        // Check authentication
        $checkResult = $this->checkRequestedUser();
        if ($checkResult) {
            return $checkResult;
        }

        $query = User::select('id', 'name', 'image')->orderBy('name');


        $users = $query->get()->map(function ($user) {
            $img = $user->image;

            if ($img && !Str::startsWith($img, ['http://', 'https://', 'storage/'])) {
                $img = 'storage/img/profile/' . ltrim($img, '/');
            }

            return [
                'id' => $user->id,
                'name' => $user->name,
                'image' => $img ? asset($img) : null,
            ];
        })
        ->values()
        ->toArray();

        return response()->json($users);
    }

    public function storeReservationCoworkMobile(Request $request)
    {
        $checkResult = $this->checkRequestedUser();
        if ($checkResult) {
            return $checkResult; // Must return JSON
        }

        $user = Auth::guard('sanctum')->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }
        if (!$this->userHasAccessFlag($user, 'access_cowork')) {
            return response()->json(['message' => 'You do not have permission to reserve a cowork table.'], 403);
        }
        $request->validate([
            'table' => 'required|integer',
            'seats' => 'required|integer|min:1',
            'day' => 'required|date',
            'start' => 'required',
            'end' => 'required',
        ]);

        $table = (int) $request->table;
        $day = $request->day;
        $start = $request->start;
        $end = $request->end;
        $lockName = 'cowork-reserve-'.$table.'-'.$day;
        $driver = DB::getDriverName();

        try {
            // Serialize first-insert races on MySQL/MariaDB when no reservation rows exist yet.
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::select('SELECT GET_LOCK(?, 10) as acquired', [$lockName]);
            }

            $reservation = DB::transaction(function () use ($request, $user, $table, $day, $start, $end) {
                // Parent-row lock: does not 404 if the cowork is missing (no extra exists validation).
                if (Schema::hasTable('coworks')) {
                    DB::table('coworks')->where('id', $table)->lockForUpdate()->first();
                }

                $sameTableDay = ReservationCowork::query()
                    ->where('table', $table)
                    ->where('day', $day)
                    ->lockForUpdate()
                    ->get();

                $hasOverlap = $sameTableDay->contains(function ($row) use ($start, $end) {
                    if ($row->canceled) {
                        return false;
                    }

                    return $row->start < $end && $row->end > $start;
                });

                if ($hasOverlap) {
                    throw ValidationException::withMessages([
                        'start' => ['This table is already reserved for the selected time.'],
                    ]);
                }

                return ReservationCowork::create([
                    'table' => $table,
                    'seats' => $request->seats,
                    'day' => $day,
                    'start' => $start,
                    'end' => $end,
                    'user_id' => $user->id,
                    'approved' => 1,
                    'canceled' => 0,
                    'passed' => 0,
                ]);
            });
        } finally {
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::select('SELECT RELEASE_LOCK(?)', [$lockName]);
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Cowork reservation created and approved automatically',
            'reservation' => $reservation,
        ], 201);
    }

    private function normalizeRolesList($roles): array
    {
        if (is_array($roles)) {
            $list = $roles;
        } elseif (is_string($roles) && $roles !== '') {
            $decoded = json_decode($roles, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $list = $decoded;
            } else {
                $list = array_map('trim', explode(',', $roles));
            }
        } else {
            $list = [];
        }

        return array_filter(array_map(fn ($role) => strtolower((string) $role), $list));
    }

    private function userHasAccessFlag($user, string $field): bool
    {
        if (!$user) {
            return false;
        }

        $roles = $this->normalizeRolesList(data_get($user, 'role'));
        if (!empty(array_intersect($roles, self::ACCESS_BYPASS_ROLES))) {
            return true;
        }

        $directValue = data_get($user, $field);
        if ($directValue !== null) {
            return (bool) $directValue;
        }

        $relationValue = data_get($user, "access.$field");
        if ($relationValue !== null) {
            return (bool) $relationValue;
        }

        if ($user instanceof User) {
            $relationValue = optional($user->access)->{$field};
            if ($relationValue !== null) {
                return (bool) $relationValue;
            }
        }

        return false;
    }

    /**
     * Studio reservation detail: owner, or privileged staff (see ACCESS_BYPASS_ROLES).
     */
    private function reservationVisibleToUser(?User $authUser, int $reservationOwnerId): bool
    {
        if (!$authUser || $reservationOwnerId < 1) {
            return false;
        }
        $roles = $this->normalizeRolesList(data_get($authUser, 'role'));
        if (!empty(array_intersect($roles, self::ACCESS_BYPASS_ROLES))) {
            return true;
        }

        return (int) $authUser->id === $reservationOwnerId;
    }
}
