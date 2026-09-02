<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\AblyCapabilityService;
use App\Services\CallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Ably\AblyRest;

class CallController extends Controller
{
    public function __construct(
        protected CallService $callService
    ) {
    }

    /**
     * Start a call to callee_id. Returns call id, channel name, and Agora token for caller.
     */
    public function initiate(Request $request): JsonResponse
    {
        $request->validate([
            'callee_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        try {
            $result = $this->callService->initiate(Auth::user(), (int) $request->callee_id);
            $call = $result['call'];
            $call->load(['caller:id,name,image', 'callee:id,name,image']);

            return response()->json([
                'call_id' => $call->id,
                'channel_name' => $result['channel_name'],
                'token' => $result['token'],
                'call' => [
                    'id' => $call->id,
                    'channel_name' => $call->channel_name,
                    'status' => $call->status,
                    'caller' => $call->caller,
                    'callee' => $call->callee,
                ],
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            Log::warning('Call initiate failed: ' . $e->getMessage());
            return response()->json(['message' => $e->getMessage()], 500);
        } catch (\Throwable $e) {
            Log::error('Call initiate error: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'message' => 'Unable to start call. Check server logs.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Callee accepts the call. Returns channel name and Agora token for callee.
     */
    public function accept(int $id): JsonResponse
    {
        try {
            $result = $this->callService->accept($id, Auth::user());
            $call = $result['call'];

            return response()->json([
                'call_id' => $call->id,
                'channel_name' => $result['channel_name'],
                'token' => $result['token'],
                'call' => [
                    'id' => $call->id,
                    'channel_name' => $call->channel_name,
                    'status' => $call->status,
                    'caller' => $call->caller,
                    'callee' => $call->callee,
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Callee rejects the call.
     */
    public function reject(int $id): JsonResponse
    {
        try {
            $this->callService->reject($id, Auth::user());
            return response()->json(['message' => 'Call rejected.']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * End an ongoing or pending call (caller or callee).
     */
    public function end(int $id): JsonResponse
    {
        try {
            $this->callService->end($id, Auth::user());
            return response()->json(['message' => 'Call ended.']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Get a single call by id (for callee opening from push – must be pending and callee is current user).
     */
    public function show(int $id): JsonResponse
    {
        $call = \App\Models\Call::with(['caller:id,name,image', 'callee:id,name,image'])
            ->where('id', $id)
            ->where('callee_id', Auth::id())
            ->where('status', \App\Models\Call::STATUS_PENDING)
            ->first();

        if (!$call) {
            return response()->json(['message' => 'Call not found or expired.'], 404);
        }

        return response()->json([
            'call_id' => $call->id,
            'channel_name' => $call->channel_name,
            'caller' => [
                'id' => $call->caller->id,
                'name' => $call->caller->name,
                'avatar' => $call->caller->image,
            ],
        ]);
    }

    /**
     * Get call history for the authenticated user.
     */
    public function history(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 20);
        $calls = $this->callService->history(Auth::user(), min($perPage, 50));
        return response()->json($calls);
    }

    /**
     * Get Ably token for call signaling.
     *
     * Capabilities granted:
     *   call:user:{authId}     – subscribe to this user's incoming/accepted/rejected events
     *   webrtc:{channel_name}  – publish/subscribe/presence for pending/ongoing calls
     *                            the user is caller or callee of
     */
    public function getAblyToken(AblyCapabilityService $capabilities): JsonResponse
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        try {
            $ablyKey = config('services.ably.key');
            if (!$ablyKey) {
                return response()->json(['error' => 'Ably not configured'], 500);
            }
            $tokenRequest = [
                'capability' => $capabilities->encode($capabilities->callCapabilities($user)),
                'clientId' => (string) $user->id,
            ];
            $ably = new AblyRest($ablyKey);
            $tokenDetails = $ably->auth->requestToken($tokenRequest);
            return response()->json([
                'token' => $tokenDetails->token,
                'expires' => $tokenDetails->expires,
                'clientId' => (string) $user->id,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to generate token: ' . $e->getMessage()], 500);
        }
    }
}
