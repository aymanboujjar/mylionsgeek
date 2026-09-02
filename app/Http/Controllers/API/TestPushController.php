<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ExpoPushNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class TestPushController extends Controller
{
    /**
     * Test endpoint to send a push notification to the authenticated user
     * Useful for debugging and testing push notifications
     */
    public function test(Request $request)
    {
        $user = Auth::guard('sanctum')->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $user->refresh();

        if (! $user->expo_push_token) {
            return response()->json([
                'success' => false,
                'message' => 'User does not have an Expo push token registered',
                'user_id' => $user->id,
                'user_email' => $user->email,
                'hint' => 'Make sure you have logged in from the mobile app and granted notification permissions',
            ], 400);
        }

        $title = $request->input('title', '🧪 Test Push Notification');
        $body = $request->input('body', 'This is a test push notification from LionsGeek! If you see this, push notifications are working! 🎉');
        $data = $request->input('data', []);

        Log::info('Test push notification requested', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'has_token' => ! empty($user->expo_push_token),
            'token_preview' => $this->tokenPreview($user->expo_push_token),
            'title' => $title,
            'body' => $body,
        ]);

        try {
            $pushService = app(ExpoPushNotificationService::class);

            Log::info('Calling push service sendToUser', [
                'user_id' => $user->id,
            ]);

            $success = $pushService->sendToUser($user, $title, $body, array_merge([
                'type' => 'test',
                'timestamp' => now()->toIso8601String(),
                'test' => true,
            ], $data));

            Log::info('Push service returned', [
                'user_id' => $user->id,
                'success' => $success,
            ]);

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'Test notification sent successfully! Check your phone for the push notification.',
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'token_preview' => $this->tokenPreview($user->expo_push_token),
                    'title' => $title,
                    'body' => $body,
                    'note' => 'If you don\'t see the notification on your phone, check: 1) App is in background/closed, 2) Device notification settings, 3) Laravel logs for errors',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to send notification. The Expo API call returned false.',
                'user_id' => $user->id,
                'token_preview' => $this->tokenPreview($user->expo_push_token),
            ], 500);
        } catch (\Exception $e) {
            Log::error('Test push notification failed with exception', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to send the test notification. Please try again later.',
                'user_id' => $user->id,
                'token_preview' => $this->tokenPreview($user->expo_push_token),
            ], 500);
        }
    }

    /**
     * Get push token status for the authenticated user
     */
    public function status(Request $request)
    {
        $user = Auth::guard('sanctum')->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $user->refresh();

        return response()->json([
            'has_token' => ! empty($user->expo_push_token),
            'token_preview' => $user->expo_push_token ? $this->tokenPreview($user->expo_push_token) : null,
            'user_id' => $user->id,
            'user_email' => $user->email,
        ]);
    }

    private function tokenPreview(?string $token): string
    {
        $token = (string) $token;

        return substr($token, 0, 30).'...';
    }
}
