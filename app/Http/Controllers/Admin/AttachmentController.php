<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    public const DISK = 'attachments';

    private const MIMES = 'jpeg,jpg,png,webp,gif,pdf,doc,docx,xls,xlsx,ppt,pptx,mp3,mp4,webm';

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:10240|mimes:'.self::MIMES,
            'project_id' => 'required|exists:projects,id',
            'task_id' => 'nullable|exists:tasks,id',
        ]);

        $project = Project::findOrFail($request->project_id);
        $this->assertProjectAccess($project);

        $file = $request->file('file');
        $path = $file->store('attachments', self::DISK);

        Attachment::create([
            'name' => $file->hashName(),
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'project_id' => $request->project_id,
            'task_id' => $request->task_id,
            'uploaded_by' => Auth::id(),
        ]);

        $project->update([
            'last_activity' => now(),
            'is_updated' => true,
        ]);

        return redirect()->back()
            ->with('success', 'File uploaded successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Attachment $attachment)
    {
        $this->assertProjectAccess(Project::findOrFail($attachment->project_id));

        Storage::disk(self::DISK)->delete($attachment->path);
        Storage::disk('public')->delete($attachment->path);

        $attachment->delete();

        return redirect()->back()
            ->with('success', 'File deleted successfully.');
    }

    /**
     * Download the specified attachment (project members only).
     */
    public function download(Attachment $attachment)
    {
        $this->assertProjectAccess(Project::findOrFail($attachment->project_id));

        $disk = Storage::disk(self::DISK);
        if ($disk->exists($attachment->path)) {
            return $disk->download($attachment->path, $attachment->original_name);
        }

        $public = Storage::disk('public');
        if ($public->exists($attachment->path)) {
            return $public->download($attachment->path, $attachment->original_name);
        }

        abort(404);
    }

    private function assertProjectAccess(Project $project): void
    {
        $userId = Auth::id();
        if ($project->created_by === $userId) {
            return;
        }
        if ($project->users()->where('user_id', $userId)->exists()) {
            return;
        }

        abort(403);
    }
}
