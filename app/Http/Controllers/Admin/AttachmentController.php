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
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:10240|mimes:jpeg,jpg,png,webp,gif,pdf,doc,docx,xls,xlsx,ppt,pptx,zip,mp3,mp4,webm',
            'project_id' => 'required|exists:projects,id',
            'task_id' => 'nullable|exists:tasks,id',
        ]);

        $file = $request->file('file');
        $path = $file->store('attachments', 'public');

        $attachment = Attachment::create([
            'name' => $file->hashName(),
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'project_id' => $request->project_id,
            'task_id' => $request->task_id,
            'uploaded_by' => Auth::id(),
        ]);

        // Update project last activity
        $project = Project::find($request->project_id);
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
        // Delete file from storage
        Storage::disk('public')->delete($attachment->path);

        $attachment->delete();

        return redirect()->back()
            ->with('success', 'File deleted successfully.');
    }

    /**
     * Download the specified attachment.
     */
    public function download(Attachment $attachment)
    {
        return Storage::disk('public')->download($attachment->path, $attachment->original_name);
    }
}
