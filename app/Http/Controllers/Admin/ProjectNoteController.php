<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectNote;
use App\Models\ProjectUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ProjectNoteController extends Controller
{
    /**
     * Same owner-or-member gate as ProjectController::show.
     * Staff role middleware is not sufficient on its own.
     */
    private function authorizeProjectAccess(Project $project): void
    {
        $userId = Auth::id();

        $isOwner = (int) $project->created_by === (int) $userId;
        $isTeamMember = ProjectUser::where('project_id', $project->id)
            ->where('user_id', $userId)
            ->exists();

        if (! $isOwner && ! $isTeamMember) {
            abort(403, 'You do not have access to this project.');
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'project_id' => 'required|integer|exists:projects,id',
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'tags' => 'nullable|array',
            'is_pinned' => 'sometimes|boolean',
            'color' => ['nullable', 'string', Rule::in(ProjectNote::ALLOWED_COLORS)],
        ]);

        $project = Project::query()->findOrFail($data['project_id']);
        $this->authorizeProjectAccess($project);

        $note = new ProjectNote;
        $note->title = $data['title'];
        $note->content = $data['content'];
        $note->tags = $data['tags'] ?? [];
        $note->is_pinned = $data['is_pinned'] ?? false;
        $note->color = $data['color'] ?? ProjectNote::DEFAULT_COLOR;
        $note->project_id = $project->id;
        $note->user_id = Auth::id();
        $note->save();

        return redirect()->back()
            ->with('success', 'Note created successfully.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ProjectNote $projectNote)
    {
        $project = Project::query()->findOrFail($projectNote->project_id);
        $this->authorizeProjectAccess($project);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'tags' => 'nullable|array',
            'is_pinned' => 'sometimes|boolean',
            'color' => ['nullable', 'string', Rule::in(ProjectNote::ALLOWED_COLORS)],
        ]);

        $projectNote->fill([
            'title' => $data['title'],
            'content' => $data['content'],
            'tags' => $data['tags'] ?? ($projectNote->tags ?? []),
            'is_pinned' => $data['is_pinned'] ?? $projectNote->is_pinned,
            'color' => $data['color'] ?? $projectNote->color,
        ]);
        $projectNote->save();

        return redirect()->back()
            ->with('success', 'Note updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ProjectNote $projectNote)
    {
        $project = Project::query()->findOrFail($projectNote->project_id);
        $this->authorizeProjectAccess($project);

        $projectNote->delete();

        return redirect()->back()
            ->with('success', 'Note deleted successfully.');
    }

    /**
     * Toggle note pin status
     */
    public function togglePin(ProjectNote $projectNote)
    {
        $project = Project::query()->findOrFail($projectNote->project_id);
        $this->authorizeProjectAccess($project);

        $projectNote->is_pinned = ! $projectNote->is_pinned;
        $projectNote->save();

        return redirect()->back()
            ->with('success', $projectNote->is_pinned ? 'Note pinned successfully.' : 'Note unpinned successfully.');
    }
}
