<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;

class PlacesController extends Controller
{


    public function getPlacesJson(): JsonResponse
    {
        $data = [
            'studios' => [],
            'coworks' => [],
            'meeting_rooms' => [],
        ];

        if (Schema::hasTable('studios')) {
            $data['studios'] = DB::table('studios')
                ->select('id', 'name', 'image')
                ->where('state', 1)
                ->get()
                ->map(function ($row) {
                    $img = $row->image
                        ? (str_starts_with($row->image, 'http') || str_starts_with($row->image, 'storage/')
                            ? $row->image
                            : asset('storage/img/studio/' . ltrim($row->image, '/')))
                        : null;
                    return [
                        'id' => $row->id,
                        'name' => $row->name,
                        'image' => $img,
                    ];
                });
        }

        if (Schema::hasTable('coworks')) {
            $data['coworks'] = DB::table('coworks')
                ->select('id', 'table as name', 'image')
                ->where('state', 1)
                ->get()
                ->map(function ($row) {
                    $img = $row->image
                        ? (str_starts_with($row->image, 'http') || str_starts_with($row->image, 'storage/')
                            ? $row->image
                            : asset('storage/img/cowork/' . ltrim($row->image, '/')))
                        : null;
                    return [
                        'id' => $row->id,
                        'name' => 'Table ' . ($row->name ?? ''),
                        'image' => $img,
                    ];
                });
        }

        if (Schema::hasTable('meeting_rooms')) {
            $data['meeting_rooms'] = DB::table('meeting_rooms')
                ->select('id', 'name', 'image')
                ->where('state', 1)
                ->get()
                ->map(function ($row) {
                    $img = $row->image
                        ? (str_starts_with($row->image, 'http') || str_starts_with($row->image, 'storage/')
                            ? $row->image
                            : asset('storage/img/meeting_room/' . ltrim($row->image, '/')))
                        : null;
                    return [
                        'id' => $row->id,
                        'name' => $row->name,
                        'image' => $img,
                    ];
                });
        }

        return response()->json($data);
    }


    public function index(Request $request)
    {
        $calendarPlaceType = $request->input('calendar_place_type');
        $calendarPlaceId = $request->input('calendar_place_id');
        $calendarPlace = null;
        $calendarEvents = [];

        $places = collect();

        if (Schema::hasTable('coworks')) {
            $coworks = DB::table('coworks')
                ->select('id', 'table', 'state', 'image', 'created_at')
                ->orderByDesc('created_at')
                ->get()
                ->map(function ($row) {
                    $img = $row->image ? (str_starts_with($row->image, 'http') || str_starts_with($row->image, 'storage/') ? $row->image : ('storage/img/cowork/' . ltrim($row->image, '/'))) : null;
                    return [
                        'id' => $row->id,
                        'name' => 'Table ' . ($row->table ?? ''),
                        'place_type' => 'cowork',
                        'state' => (bool) ($row->state ?? 0),
                        'image' => $img ? asset($img) : null,
                        'created_at' => $row->created_at,
                    ];
                });
            $places = $places->concat($coworks);
        }

        if (Schema::hasTable('studios')) {
            $studios = DB::table('studios')
                ->select('id', 'name', 'state', 'image', 'created_at')
                ->orderByDesc('created_at')
                ->get()
                ->map(function ($row) {
                    $img = $row->image ? (str_starts_with($row->image, 'http') || str_starts_with($row->image, 'storage/') ? $row->image : ('storage/img/studio/' . ltrim($row->image, '/'))) : null;
                    return [
                        'id' => $row->id,
                        'name' => $row->name,
                        'place_type' => 'studio',
                        'state' => (bool) ($row->state ?? 0),
                        'image' => $img ? asset($img) : null,
                        'created_at' => $row->created_at,
                    ];
                });
            $places = $places->concat($studios);
        }

        if (Schema::hasTable('meeting_rooms')) {
            $rooms = DB::table('meeting_rooms')
                ->select('id', 'name', 'state', 'image', 'created_at')
                ->orderByDesc('created_at')
                ->get()
                ->map(function ($row) {
                    $img = $row->image ? (str_starts_with($row->image, 'http') || str_starts_with($row->image, 'storage/') ? $row->image : ('storage/img/meeting_room/' . ltrim($row->image, '/'))) : null;
                    return [
                        'id' => $row->id,
                        'name' => $row->name,
                        'place_type' => 'meeting_room',
                        'state' => (bool) ($row->state ?? 0),
                        'image' => $img ? asset($img) : null,
                        'created_at' => $row->created_at,
                    ];
                });
            $places = $places->concat($rooms);
        }

        // Globally sort newest first and compute available types
        $places = $places
            ->sortByDesc(function ($p) {
                return $p['created_at'] ?? null;
            })
            ->values();

        $types = $places->pluck('place_type')->unique()->values();

        // Collect gallery images from storage/img/* folders
        $studioImages = $this->listPublicImages('img/studio');
        $meetingRoomImages = $this->listPublicImages('img/meeting_room');
        $coworkImages = $this->listPublicImages('img/cowork');
        $equipmentImages = $this->listPublicImages('img/equipment');

        if ($calendarPlaceType && $calendarPlaceId) {
            $calendarPlace = $places->first(function ($place) use ($calendarPlaceType, $calendarPlaceId) {
                return $place['place_type'] === $calendarPlaceType && (int)$place['id'] === (int)$calendarPlaceId;
            });

            if ($calendarPlace) {
                $calendarEvents = $this->getPlaceReservations($calendarPlaceType, (int)$calendarPlaceId);
            }
        }

        $equipmentOptions = $this->getEquipmentOptions();
        $teamMemberOptions = $this->getTeamMemberOptions();

        return Inertia::render('admin/places/index', [
            'places' => $places->map(function ($p) {
                unset($p['created_at']);
                return $p;
            }),
            'types' => $types,
            'studioImages' => $studioImages,
            'meetingRoomImages' => $meetingRoomImages,
            'coworkImages' => $coworkImages,
            'equipmentImages' => $equipmentImages,
            'calendarPlace' => $calendarPlace,
            'calendarEvents' => $calendarEvents,
            'equipmentOptions' => $equipmentOptions,
            'teamMemberOptions' => $teamMemberOptions,
        ]);
    }

    public function index2(Request $request)
    {
        $studios = DB::table('studios')
            ->select('id', 'name', 'state', 'image')
            ->orderBy('name')
            ->get()
            ->map(function ($studio) {
                $img = $studio->image ? (
                    str_starts_with($studio->image, 'http') || str_starts_with($studio->image, 'storage/')
                    ? $studio->image
                    : ('storage/img/studio/' . ltrim($studio->image, '/'))
                ) : null;

                return [
                    'id' => $studio->id,
                    'name' => $studio->name,
                    'state' => (bool) $studio->state,
                    'image' => $img ? asset($img) : null,
                    'type' => 'studio',
                ];
            });

        $coworks = DB::table('coworks')
            ->select('id', 'table', 'state', 'image')
            ->orderBy('table')
            ->get()
            ->map(function ($cowork) {
                $img = $cowork->image ? (
                    str_starts_with($cowork->image, 'http') || str_starts_with($cowork->image, 'storage/')
                    ? $cowork->image
                    : ('storage/img/cowork/' . ltrim($cowork->image, '/'))
                ) : null;

                return [
                    'id' => $cowork->id,
                    'name' => 'Table ' . $cowork->table,
                    'state' => (bool) $cowork->state,
                    'image' => $img ? asset($img) : null,
                    'type' => 'cowork',
                ];
            });

        $meetingRooms = DB::table('meeting_rooms')
            ->select('id', 'name', 'state', 'image')
            ->orderBy('name')
            ->get()
            ->map(function ($room) {
                $img = $room->image ? (
                    str_starts_with($room->image, 'http') || str_starts_with($room->image, 'storage/')
                    ? $room->image
                    : ('storage/img/meeting_room/' . ltrim($room->image, '/'))
                ) : null;

                return [
                    'id' => $room->id,
                    'name' => $room->name,
                    'state' => (bool) $room->state,
                    'image' => $img ? asset($img) : null,
                    'type' => 'Meeting Room',
                    'cardType' => 'meeting_room',
                ];
            });

        $equipmentOptions = $this->getEquipmentOptions();
        $teamMemberOptions = $this->getTeamMemberOptions();

        $events = [];
        $calendarContext = null;
        $eventsMode = $request->input('events_mode');
        $eventType = $request->input('event_type');
        $eventId = $request->input('event_id');

        if ($eventsMode === 'studio_all') {
            $events = $this->getAllStudioEvents($studios);
        } elseif ($eventsMode === 'cowork_all') {
            $events = $this->getAllCoworkEvents($coworks);
        } elseif ($eventsMode === 'place' && $eventType && $eventId) {
            $calendarContext = $this->resolveCalendarContext($eventType, (int) $eventId, $studios, $coworks, $meetingRooms);
            $events = $this->getPlaceReservations($eventType, (int) $eventId);
        }

        return Inertia::render('students/spaces/index', [
            'studios' => $studios,
            'coworks' => $coworks,
            'meetingRooms' => $meetingRooms,
            'equipmentOptions' => $equipmentOptions,
            'teamMemberOptions' => $teamMemberOptions,
            'events' => $events,
            'calendarContext' => $calendarContext,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'place_type' => 'required|string|in:cowork,studio,meeting_room',
            'state' => 'required',
            'image' => 'nullable|image|max:4096',
        ]);

        $table = $this->resolveTableFromType($data['place_type']);
        if (!$table || !Schema::hasTable($table)) {
            return back()->with('error', 'Target table not available.');
        }

        $imagePath = null;
        if ($data['place_type'] === 'cowork') {
            // Always use default cowork image
            $imagePath = 'storage/img/cowork/cowork.jpg';
        } else if ($request->hasFile('image')) {
            $imagePath = 'storage/' . $request->file('image')->store('places', 'public');
        }

        // Some SQLite tables may not have AUTOINCREMENT configured for id.
        // To avoid NOT NULL constraint errors, explicitly assign the next id when needed.
        $nextId = (int) (DB::table($table)->max('id') ?? 0) + 1;

        if ($table === 'coworks') {
            DB::table($table)->insert([
                'id' => $nextId,
                'table' => $data['name'],
                'state' => (int) ((string)$data['state'] === '1' || $data['state'] === 1 || $data['state'] === true),
                'image' => $imagePath,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table($table)->insert([
                'id' => $nextId,
                'name' => $data['name'],
                'state' => (int) ((string)$data['state'] === '1' || $data['state'] === 1 || $data['state'] === true),
                'image' => $imagePath,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }


        return redirect()->route('admin.places')->with('success', 'Place added');
    }

    public function update(Request $request, int $place)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'place_type' => 'required|string|in:cowork,studio,meeting_room',
            'state' => 'required',
            'image' => 'nullable|image|max:4096',
        ]);

        $table = $this->resolveTableFromType($data['place_type']);
        if (!$table || !Schema::hasTable($table)) {
            return back()->with('error', 'Target table not available.');
        }

        if ($table === 'coworks') {
            $update = [
                'table' => $data['name'],
                'state' => (int) ((string)$data['state'] === '1' || $data['state'] === 1 || $data['state'] === true),
                'updated_at' => now(),
            ];
        } else {
            $update = [
                'name' => $data['name'],
                'state' => (int) ((string)$data['state'] === '1' || $data['state'] === 1 || $data['state'] === true),
                'updated_at' => now(),
            ];
        }



        if ($table !== 'coworks' && $request->hasFile('image')) {
            $update['image'] = 'storage/' . $request->file('image')->store('places', 'public');
        }

        DB::table($table)->where('id', $place)->update($update);

        return redirect()->route('admin.places')->with('success', 'Place updated');
    }

    public function destroy(Request $request, int $place)
    {
        $type = $request->input('place_type');
        $table = $this->resolveTableFromType($type);

        if (!$table || !Schema::hasTable($table)) {
            return back()->with('error', 'Target table not available.');
        }

        DB::table($table)->where('id', $place)->delete();

        return back()->with('success', 'Place deleted');
    }

    private function getPlaceReservations(string $type, int $id): array
    {
        if ($type === 'studio') {
            return DB::table('reservations as r')
                ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
                ->where('r.studio_id', $id)
                ->where('r.canceled', 0)
                ->select(
                    'r.id',
                    'r.day as start',
                    'r.start as startTime',
                    'r.end as endTime',
                    'r.title',
                    'r.approved',
                    'r.canceled',
                    'r.user_id as user_id',
                    'r.studio_id',
                    'r.created_at',
                    'u.name as user_name'
                )
                ->orderByDesc('r.created_at')
                ->get()
                ->map(function ($r) {
                    return [
                        'id' => $r->id,
                        'reservation_id' => $r->id,
                        'title' => trim(($r->title ?? 'Reservation') . ' — ' . ($r->user_name ?? '')),
                        'start' => $r->start . 'T' . $r->startTime,
                        'end' => $r->start . 'T' . $r->endTime,
                        'backgroundColor' => $r->canceled ? '#6b7280' : ($r->approved ? '#FFC801' : '#f59e0b'),
                        'user_id' => $r->user_id,
                        'canceled' => (bool) $r->canceled,
                        'approved' => (bool) $r->approved,
                        'studio_id' => $r->studio_id,
                        'created_at' => $r->created_at ? (is_string($r->created_at) ? $r->created_at : $r->created_at->toDateTimeString()) : null,
                    ];
                })
                ->values()
                ->toArray();
        }

        if ($type === 'cowork') {
            return DB::table('reservation_coworks as rc')
                ->leftJoin('users as u', 'u.id', '=', 'rc.user_id')
                ->leftJoin('coworks as c', 'c.id', '=', 'rc.table')
                ->where('rc.table', $id)
                ->where('rc.canceled', 0)
                ->select(
                    'rc.id',
                    'rc.day as start',
                    'rc.start as startTime',
                    'rc.end as endTime',
                    'rc.approved',
                    'rc.canceled',
                    'rc.user_id as user_id',
                    'rc.created_at',
                    'u.name as user_name',
                    'c.table as table_number',
                    'rc.table as table_id'
                )
                ->orderByDesc('rc.created_at')
                ->get()
                ->map(function ($r) {
                    return [
                        'id' => $r->id,
                        'reservation_id' => $r->id,
                        'title' => 'Table ' . $r->table_number . ' — ' . ($r->user_name ?? ''),
                        'start' => $r->start . 'T' . $r->startTime,
                        'end' => $r->start . 'T' . $r->endTime,
                        'backgroundColor' => $r->canceled ? '#6b7280' : ($r->approved ? '#FFC801' : '#f59e0b'),
                        'user_id' => $r->user_id,
                        'canceled' => (bool) $r->canceled,
                        'approved' => (bool) $r->approved,
                        'created_at' => $r->created_at ? (is_string($r->created_at) ? $r->created_at : $r->created_at->toDateTimeString()) : null,
                        'table_id' => $r->table_id,
                    ];
                })
                ->values()
                ->toArray();
        }

        if ($type === 'meeting_room') {
            return DB::table('reservation_meeting_rooms as rmr')
                ->leftJoin('users as u', 'u.id', '=', 'rmr.user_id')
                ->where('rmr.meeting_room_id', $id)
                ->where('rmr.canceled', 0)
                ->select(
                    'rmr.id',
                    'rmr.day as start',
                    'rmr.start as startTime',
                    'rmr.end as endTime',
                    'rmr.approved',
                    'rmr.canceled',
                    'rmr.user_id as user_id',
                    'rmr.created_at',
                    'u.name as user_name'
                )
                ->orderByDesc('rmr.created_at')
                ->get()
                ->map(function ($r) {
                    return [
                        'id' => $r->id,
                        'reservation_id' => $r->id,
                        'title' => 'Meeting Room — ' . ($r->user_name ?? ''),
                        'start' => $r->start . 'T' . $r->startTime,
                        'end' => $r->start . 'T' . $r->endTime,
                        'backgroundColor' => $r->canceled ? '#6b7280' : ($r->approved ? '#FFC801' : '#f59e0b'),
                        'user_id' => $r->user_id,
                        'canceled' => (bool) $r->canceled,
                        'approved' => (bool) $r->approved,
                        'created_at' => $r->created_at ? (is_string($r->created_at) ? $r->created_at : $r->created_at->toDateTimeString()) : null,
                    ];
                })
                ->values()
                ->toArray();
        }

        return [];
    }

    private function resolveTableFromType(?string $type): ?string
    {
        return match ($type) {
            'cowork' => 'coworks',
            'studio' => 'studios',
            'meeting_room' => 'meeting_rooms',
            default => null,
        };
    }

    private function getEquipmentOptions(): array
    {
        if (!Schema::hasTable('equipment')) {
            return [];
        }

        return DB::table('equipment as e')
            ->leftJoin('equipment_types as et', 'et.id', '=', 'e.equipment_type_id')
            ->select('e.id', 'e.reference', 'e.mark', 'e.image', 'et.name as type_name')
            ->orderBy('e.mark')
            ->get()
            ->map(function ($equipment) {
                $image = $equipment->image ?? null;
                $basePath = 'storage/img/equipment/';
                if ($image) {
                    if (Str::startsWith($image, ['http://', 'https://'])) {
                        $imageUrl = $image;
                    } else {
                        $normalized = Str::startsWith($image, ['storage/', '/storage/', 'img/'])
                            ? ltrim($image, '/')
                            : $basePath . ltrim($image, '/');
                        $imageUrl = asset(Str::startsWith($normalized, 'storage/') ? $normalized : 'storage/' . ltrim($normalized, '/'));
                    }
                } else {
                    $imageUrl = null;
                }

                return [
                    'id' => $equipment->id,
                    'reference' => $equipment->reference,
                    'mark' => $equipment->mark,
                    'image' => $imageUrl,
                    'type_name' => $equipment->type_name,
                ];
            })
            ->values()
            ->toArray();
    }

    private function getTeamMemberOptions(): array
    {
        if (!Schema::hasTable('users')) {
            return [];
        }

        $viewer = Auth::user();
        $viewerRoles = is_array($viewer?->role) ? $viewer->role : array_filter([(string) ($viewer?->role ?? '')]);
        $viewerIsAdmin = (bool) array_intersect(
            array_map('strtolower', array_map('strval', $viewerRoles)),
            ['admin', 'super_admin']
        );

        return DB::table('users')
            ->select('id', 'name', 'image', 'role')
            ->orderBy('name')
            ->get()
            ->filter(function ($user) use ($viewerIsAdmin) {
                if ($viewerIsAdmin) {
                    return true;
                }

                return ! $this->teamMemberHasAdminRole($user->role ?? null);
            })
            ->map(function ($user) {
                $image = $user->image ?? null;
                $basePath = 'storage/img/profile/';
                if ($image) {
                    if (Str::startsWith($image, ['http://', 'https://'])) {
                        $imageUrl = $image;
                    } else {
                        $normalized = Str::startsWith($image, ['storage/', '/storage/', 'img/'])
                            ? ltrim($image, '/')
                            : $basePath . ltrim($image, '/');
                        $imageUrl = asset(Str::startsWith($normalized, 'storage/') ? $normalized : 'storage/' . ltrim($normalized, '/'));
                    }
                } else {
                    $imageUrl = null;
                }

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'image' => $imageUrl,
                ];
            })
            ->values()
            ->toArray();
    }

    private function teamMemberHasAdminRole(mixed $role): bool
    {
        if ($role === null || $role === '') {
            return false;
        }

        if (is_string($role)) {
            $decoded = json_decode($role, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $role = $decoded;
            } else {
                $role = [$role];
            }
        }

        if (! is_array($role)) {
            $role = [(string) $role];
        }

        $lower = array_map('strtolower', array_map('strval', $role));

        return (bool) array_intersect($lower, ['admin', 'super_admin']);
    }

    private function getAllStudioEvents(Collection $studios): array
    {
        if ($studios->isEmpty() || !Schema::hasTable('reservations')) {
            return [];
        }

        $studioIds = $studios->pluck('id')->all();

        return DB::table('reservations as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->leftJoin('studios as s', 's.id', '=', 'r.studio_id')
            ->whereIn('r.studio_id', $studioIds)
            ->where('r.canceled', 0)
            ->select(
                'r.id',
                'r.day as start',
                'r.start as startTime',
                'r.end as endTime',
                'r.title',
                'r.approved',
                'r.canceled',
                'r.user_id as user_id',
                'r.studio_id',
                'r.created_at',
                'u.name as user_name',
                's.name as studio_name'
            )
            ->orderByDesc('r.created_at')
            ->get()
            ->map(function ($row) {
                return [
                    'id' => $row->id,
                    'reservation_id' => $row->id,
                    'title' => trim(($row->title ?? 'Reservation') . ' — ' . ($row->studio_name ?? '') . ' — ' . ($row->user_name ?? '')),
                    'start' => $row->start . 'T' . $row->startTime,
                    'end' => $row->start . 'T' . $row->endTime,
                    'backgroundColor' => $row->canceled ? '#6b7280' : ($row->approved ? '#FFC801' : '#f59e0b'),
                    'user_id' => $row->user_id,
                    'canceled' => (bool) $row->canceled,
                    'approved' => (bool) $row->approved,
                    'studio_id' => $row->studio_id,
                    'created_at' => $row->created_at ? (is_string($row->created_at) ? $row->created_at : $row->created_at->toDateTimeString()) : null,
                ];
            })
            ->values()
            ->toArray();
    }

    private function getAllCoworkEvents(Collection $coworks): array
    {
        if ($coworks->isEmpty() || !Schema::hasTable('reservation_coworks')) {
            return [];
        }

        $coworkIds = $coworks->pluck('id')->all();

        return DB::table('reservation_coworks as rc')
            ->leftJoin('users as u', 'u.id', '=', 'rc.user_id')
            ->leftJoin('coworks as c', 'c.id', '=', 'rc.table')
            ->whereIn('rc.table', $coworkIds)
            ->where('rc.canceled', 0)
            ->select(
                'rc.id',
                'rc.day as start',
                'rc.start as startTime',
                'rc.end as endTime',
                'rc.approved',
                'rc.canceled',
                'rc.user_id as user_id',
                'rc.created_at',
                'u.name as user_name',
                'c.table as table_number',
                'rc.table as table_id'
            )
            ->orderByDesc('rc.created_at')
            ->get()
            ->map(function ($row) {
                return [
                    'id' => $row->id,
                    'reservation_id' => $row->id,
                    'title' => 'Table ' . ($row->table_number ?? '') . ' — ' . ($row->user_name ?? ''),
                    'start' => $row->start . 'T' . $row->startTime,
                    'end' => $row->start . 'T' . $row->endTime,
                    'backgroundColor' => $row->canceled ? '#6b7280' : ($row->approved ? '#FFC801' : '#f59e0b'),
                    'user_id' => $row->user_id,
                    'canceled' => (bool) $row->canceled,
                    'approved' => (bool) $row->approved,
                    'created_at' => $row->created_at ? (is_string($row->created_at) ? $row->created_at : $row->created_at->toDateTimeString()) : null,
                    'table_id' => $row->table_id,
                ];
            })
            ->values()
            ->toArray();
    }

    private function resolveCalendarContext(?string $type, int $id, Collection $studios, Collection $coworks, Collection $meetingRooms = null): ?array
    {
        if (!$type || !$id) {
            return null;
        }

        if ($type === 'studio') {
            $studio = $studios->firstWhere('id', $id);
            if ($studio) {
                return [
                    'place_type' => 'studio',
                    'id' => $studio['id'],
                    'name' => $studio['name'],
                ];
            }
        }

        if ($type === 'cowork') {
            $cowork = $coworks->firstWhere('id', $id);
            if ($cowork) {
                return [
                    'place_type' => 'cowork',
                    'id' => $cowork['id'],
                    'name' => $cowork['name'],
                ];
            }
        }

        if ($type === 'meeting_room' && $meetingRooms) {
            $room = $meetingRooms->firstWhere('id', $id);
            if ($room) {
                return [
                    'place_type' => 'meeting_room',
                    'id' => $room['id'],
                    'name' => $room['name'],
                ];
            }
        }

        return null;
    }

    /**
     * List image URLs under storage/app/public/{folder} as asset('storage/...')
     */
    private function listPublicImages(string $folder): array
    {
        $diskPath = 'public/' . trim($folder, '/');
        if (!Storage::exists($diskPath)) {
            return [];
        }
        $files = Storage::files($diskPath);
        $images = [];
        foreach ($files as $path) {
            $lower = strtolower($path);
            if (!str_ends_with($lower, '.jpg') && !str_ends_with($lower, '.jpeg') && !str_ends_with($lower, '.png') && !str_ends_with($lower, '.gif') && !str_ends_with($lower, '.webp') && !str_ends_with($lower, '.svg')) {
                continue;
            }
            // Convert public/... to storage/...
            $publicUrl = asset(str_replace('public/', 'storage/', $path));
            $images[] = $publicUrl;
        }
        return $images;
    }
}
