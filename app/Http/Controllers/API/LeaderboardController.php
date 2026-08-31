<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class LeaderboardController extends Controller
{
    public function index(Request $request)
    {
        // Check authentication
        $checkResult = $this->checkRequestedUser();
        if ($checkResult) {
            return $checkResult;
        }

        $range = $request->get('range', 'this_week'); // this_week, week, month, alltime
        $promo = $request->get('promo', 'all');

        // Cache for 5 minutes
        $cacheKey = "mobile_leaderboard_{$range}_{$promo}";

        return Cache::remember($cacheKey, 300, function () use ($range, $promo) {
            $isThisWeek = $range === 'this_week';

            // Get users with Wakatime API keys
            $query = User::whereNotNull('wakatime_api_key')
                ->where('account_state', 0);

            if ($promo !== 'all') {
                if (is_array($promo)) {
                    $query->whereIn('promo', $promo);
                } else {
                    $query->where('promo', $promo);
                }
            }

            $users = $query->get();

            $startDate = Carbon::now()->startOfWeek()->toDateString();
            $endDate = Carbon::now()->endOfWeek()->toDateString();
            $endpoint = match ($range) {
                'week' => 'stats/last_7_days',
                'month' => 'stats/last_30_days',
                'alltime' => 'stats/all_time',
                default => 'stats/last_7_days',
            };

            // Fire every user's WakaTime request concurrently instead of one at a time.
            $responses = $users->isEmpty() ? [] : Http::pool(function (Pool $pool) use ($users, $isThisWeek, $endpoint, $startDate, $endDate) {
                $requests = [];

                foreach ($users as $user) {
                    $client = $pool->as($user->id)
                        ->timeout(15)
                        ->withHeaders([
                            'Authorization' => 'Basic ' . base64_encode($user->wakatime_api_key . ':'),
                        ]);

                    $requests[] = $isThisWeek
                        ? $client->get('https://wakatime.com/api/v1/users/current/summaries', [
                            'start' => $startDate,
                            'end' => $endDate,
                        ])
                        : $client->get("https://wakatime.com/api/v1/users/current/{$endpoint}");
                }

                return $requests;
            });

            $results = [];

            foreach ($users as $user) {
                $response = $responses[$user->id] ?? null;

                if (!($response instanceof Response) || !$response->successful()) {
                    continue;
                }

                try {
                    $data = $response->json();
                    $totalSeconds = 0;
                    $dailyAverage = 0;
                    $topLanguage = 'Unknown';
                    $languages = [];

                    if ($isThisWeek) {
                        // Sum up all days
                        $totalSeconds = collect($data['data'] ?? [])
                            ->sum(fn($day) => $day['grand_total']['total_seconds'] ?? 0);

                        // Aggregate languages from all days
                        foreach ($data['data'] ?? [] as $day) {
                            foreach ($day['languages'] ?? [] as $lang) {
                                $langName = $lang['name'] ?? 'Unknown';
                                if (!isset($languages[$langName])) {
                                    $languages[$langName] = ['total_seconds' => 0];
                                }
                                $languages[$langName]['total_seconds'] += $lang['total_seconds'] ?? 0;
                            }
                        }

                        // Calculate daily average
                        $daysCount = count($data['data'] ?? []);
                        $dailyAverage = $daysCount > 0 ? $totalSeconds / $daysCount : 0;
                    } else {
                        $totalSeconds = $data['data']['total_seconds'] ?? 0;
                        $dailyAverage = $data['data']['daily_average'] ?? 0;
                        foreach ($data['data']['languages'] ?? [] as $lang) {
                            $languages[$lang['name']] = ['total_seconds' => $lang['total_seconds'] ?? 0];
                        }
                    }

                    // Get top language
                    if (!empty($languages)) {
                        uasort($languages, fn($a, $b) => $b['total_seconds'] <=> $a['total_seconds']);
                        $topLanguage = array_key_first($languages);
                    }

                    // Format time strings
                    $totalTime = $this->formatTime($totalSeconds);
                    $dailyAvg = $this->formatTime($dailyAverage);

                    // Calculate user rank based on total hours
                    $userRank = $this->calculateRank($totalSeconds);

                    $results[] = [
                        'id' => $user->id,
                        'name' => $user->name,
                        'avatar' => $user->image ? url('storage/' . $user->image) : null,
                        'image' => $user->image,
                        'promo' => $user->promo,
                        'total_seconds' => $totalSeconds,
                        'total_time' => $totalTime,
                        'daily_avg' => $dailyAvg,
                        'top_language' => $topLanguage,
                        'user_rank' => $userRank,
                    ];
                } catch (\Exception $e) {
                    // Skip failed users
                    continue;
                }
            }

            // Sort by total_seconds descending
            usort($results, fn($a, $b) => ($b['total_seconds'] ?? 0) <=> ($a['total_seconds'] ?? 0));

            // Assign ranks
            foreach ($results as $index => &$item) {
                $item['rank'] = $index + 1;
            }

            return response()->json(['leaderboard' => $results]);
        });
    }

    private function formatTime($seconds)
    {
        if (!$seconds || $seconds <= 0) {
            return '0m';
        }

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        }

        return "{$minutes}m";
    }

    private function calculateRank($totalSeconds)
    {
        $totalHours = $totalSeconds / 3600;

        if ($totalHours >= 1000) return 'Master';
        if ($totalHours >= 500) return 'Expert';
        if ($totalHours >= 200) return 'Advanced';
        if ($totalHours >= 100) return 'Intermediate';
        if ($totalHours >= 50) return 'Beginner+';
        return 'Beginner';
    }
}
