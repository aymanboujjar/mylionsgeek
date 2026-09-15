<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StoryReport;
use App\Models\StoryReportNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class StoryReportController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->get('status', StoryReport::STATUS_PENDING);
        $status = in_array($status, [StoryReport::STATUS_PENDING, StoryReport::STATUS_ACCEPTED, StoryReport::STATUS_REFUSED], true)
            ? $status
            : StoryReport::STATUS_PENDING;

        $reports = StoryReport::query()
            ->with([
                'story:id,user_id,media_path,media_type,created_at,is_hidden',
                'story.user:id,name,image',
                'reporter:id,name,image',
                'reviewer:id,name,image',
            ])
            ->where('status', $status)
            ->orderByDesc('created_at')
            ->paginate(25)
            ->through(function (StoryReport $r) {
                return [
                    'id' => (int) $r->id,
                    'status' => (string) $r->status,
                    'reason' => $r->reason,
                    'created_at' => $r->created_at?->toISOString(),
                    'reviewed_at' => $r->reviewed_at?->toISOString(),
                    'story' => $r->story ? [
                        'id' => (int) $r->story->id,
                        'media_type' => $r->story->media_type,
                        'media_url' => url('storage/'.ltrim((string) $r->story->media_path, '/')),
                        'is_hidden' => (bool) $r->story->is_hidden,
                        'user' => $r->story->user ? [
                            'id' => (int) $r->story->user->id,
                            'name' => (string) $r->story->user->name,
                        ] : null,
                    ] : null,
                    'reporter' => $r->reporter ? [
                        'id' => (int) $r->reporter->id,
                        'name' => (string) $r->reporter->name,
                    ] : null,
                    'reviewer' => $r->reviewer ? [
                        'id' => (int) $r->reviewer->id,
                        'name' => (string) $r->reviewer->name,
                    ] : null,
                ];
            });

        return Inertia::render('admin/story-reports/index', [
            'reports' => $reports,
            'filters' => ['status' => $status],
        ]);
    }

    public function accept(int $report)
    {
        return $this->resolve($report, StoryReport::STATUS_ACCEPTED);
    }

    public function refuse(int $report)
    {
        return $this->resolve($report, StoryReport::STATUS_REFUSED);
    }

    private function resolve(int $reportId, string $status)
    {
        $report = StoryReport::query()->with('story:id,is_hidden')->findOrFail($reportId);
        if ($report->status !== StoryReport::STATUS_PENDING) {
            return redirect()->route('admin.story-reports.index')->with('error', 'Already resolved.');
        }

        DB::transaction(function () use ($report, $status) {
            $report->status = $status;
            $report->reviewed_by = Auth::id();
            $report->reviewed_at = now();
            $report->save();
            if ($report->story) {
                $report->story->is_hidden = $status === StoryReport::STATUS_ACCEPTED;
                $report->story->save();
            }
            StoryReportNotification::query()
                ->where('story_report_id', $report->id)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        });

        return redirect()
            ->route('admin.story-reports.index')
            ->with('success', $status === StoryReport::STATUS_ACCEPTED ? 'Story hidden.' : 'Report refused.');
    }
}
