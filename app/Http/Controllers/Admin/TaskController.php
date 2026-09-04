<?php

namespace App\Http\Controllers\Admin;

use Ably\AblyRest;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAssignmentNotification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TaskController extends Controller
{
    private function ensureProjectOwner(Project $project): void
    {
        if ((int) $project->created_by !== (int) Auth::id()) {
            abort(403, 'Only the project owner can manage project tasks.');
        }
    }

    private function canManageProjectTasks(Project $project, int $userId): bool
    {
        if ((int) $project->created_by === $userId) {
            return true;
        }

        return $project->users()
            ->where('users.id', $userId)
            ->wherePivot('role', 'admin')
            ->exists();
    }

    private function ensureCanManageProjectTasks(Project $project): void
    {
        if (! $this->canManageProjectTasks($project, (int) Auth::id())) {
            abort(403, 'Only project admins or the project owner can manage project tasks.');
        }
    }

    private function ensureProjectMember(Project $project): void
    {
        $userId = (int) Auth::id();

        if ((int) $project->created_by === $userId) {
            return;
        }

        if ($project->users()->where('users.id', $userId)->exists()) {
            return;
        }

        abort(403, 'You do not have access to this project.');
    }

    private function canDeleteTask(Task $task, int $userId): bool
    {
        if ($this->canManageProjectTasks($task->project, $userId)) {
            return true;
        }

        return (int) $task->created_by === $userId;
    }

    private function ensureCanWorkOnTask(Task $task): void
    {
        $userId = (int) Auth::id();

        if ($this->canManageProjectTasks($task->project, $userId)) {
            return;
        }

        if ((int) $task->assigned_to === $userId) {
            return;
        }

        if (in_array($userId, $this->taskAssigneeIds($task), true)) {
            return;
        }

        abort(403, 'You can only edit tasks assigned to you.');
    }

    private function taskAssigneeIds(Task $task): array
    {
        return collect($task->assignees ?? [])
            ->map(fn ($assignee) => is_array($assignee) ? ($assignee['id'] ?? null) : $assignee)
            ->filter()
            ->map(fn ($assigneeId) => (int) $assigneeId)
            ->unique()
            ->values()
            ->all();
    }

    private function ensureCanViewTask(Task $task): void
    {
        $userId = (int) Auth::id();

        if ((int) $task->project->created_by === $userId) {
            return;
        }

        if ((int) $task->assigned_to === $userId) {
            return;
        }

        if (in_array($userId, $this->taskAssigneeIds($task), true)) {
            return;
        }

        if ($task->project->users()->where('users.id', $userId)->exists()) {
            return;
        }

        abort(403, 'You do not have access to this task.');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // dd($request->all());
        try {
            $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'priority' => 'nullable|in:low,medium,high,urgent',
                'status' => 'nullable|in:todo,in_progress,review,completed',
                'project_id' => 'required|exists:projects,id',
                'assigned_to' => 'nullable|exists:users,id',
                'assignees' => 'nullable|array',
                'assignees.*' => 'nullable|exists:users,id',
                'due_date' => 'nullable|date',
                'subtasks' => 'nullable|array',
                'tags' => 'nullable|array',
                'progress' => 'nullable|integer|min:0|max:100',
            ]);

            $data = $request->all();
            $data['created_by'] = Auth::id();

            $project = Project::findOrFail($request->project_id);
            $this->ensureProjectMember($project);

            $currentUserId = (int) Auth::id();
            $canManageTasks = $this->canManageProjectTasks($project, $currentUserId);

            if (! $canManageTasks) {
                $data['assigned_to'] = $currentUserId;
                if (($data['status'] ?? null) === 'completed') {
                    $data['status'] = 'review';
                }
            }

            $projectMemberIds = $project->users()
                ->pluck('users.id')
                ->push($project->created_by)
                ->map(fn ($memberId) => (int) $memberId)
                ->unique()
                ->values();

            $assignedTo = isset($data['assigned_to']) && $data['assigned_to'] !== ''
                ? (int) $data['assigned_to']
                : null;

            if ($assignedTo && ! $projectMemberIds->contains($assignedTo)) {
                return redirect()->back()
                    ->with('error', 'Assigned user must be a project member.');
            }

            $assignees = collect($data['assignees'] ?? [])
                ->map(fn ($assignee) => is_array($assignee) ? ($assignee['id'] ?? null) : $assignee)
                ->filter()
                ->map(fn ($assigneeId) => (int) $assigneeId)
                ->unique()
                ->values();

            if ($assignees->diff($projectMemberIds)->isNotEmpty()) {
                return redirect()->back()
                    ->with('error', 'All task assignees must be project members.');
            }

            // Set default values
            $data['priority'] = $data['priority'] ?? 'medium';
            $data['status'] = $data['status'] ?? 'todo';
            $data['progress'] = $data['progress'] ?? 0;
            $data['is_pinned'] = $data['is_pinned'] ?? false;
            $data['is_editable'] = $data['is_editable'] ?? true;
            $data['subtasks'] = $data['subtasks'] ?? [];
            $data['tags'] = $data['tags'] ?? [];
            $data['assigned_to'] = $assignedTo;
            $data['assignees'] = $assignees
                ->reject(fn ($assigneeId) => $assignedTo && $assigneeId === $assignedTo)
                ->values()
                ->all();

            // Temporarily disable foreign key checks for SQLite
            DB::statement('PRAGMA foreign_keys=OFF');

            $task = Task::create($data);

            // Re-enable foreign key checks
            DB::statement('PRAGMA foreign_keys=ON');

            // Create notification if task is assigned to a user
            $assignedTo = $task->assigned_to ? (int) $task->assigned_to : null;
            $notificationUserIds = collect([$assignedTo, ...($task->assignees ?? [])])
                ->filter()
                ->map(fn ($assigneeId) => (int) $assigneeId)
                ->unique()
                ->values();

            foreach ($notificationUserIds as $notificationUserId) {
                Log::info('Creating task assignment notification on create', [
                    'task_id' => $task->id,
                    'assigned_to' => $notificationUserId,
                    'assigned_by' => $currentUserId,
                ]);
                $this->createTaskAssignmentNotification($task, $notificationUserId, $currentUserId);
            }

            // Update project last activity
            $project->update([
                'last_activity' => now(),
                'is_updated' => true,
            ]);

            return redirect()->back()
                ->with('success', 'Task created successfully!');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Failed to create task: '.$e->getMessage());
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Task $task)
    {
        try {
            $this->ensureCanWorkOnTask($task);

            $request->validate([
                'title' => 'nullable|string|max:255',
                'description' => 'nullable|string',
                'priority' => 'nullable|in:low,medium,high,urgent',
                'status' => 'nullable|in:todo,in_progress,review,completed',
                'assigned_to' => 'nullable|exists:users,id',
                'due_date' => 'nullable|date',
                'subtasks' => 'nullable|array',
                'tags' => 'nullable|array',
                'progress' => 'nullable|integer|min:0|max:100',
                'is_pinned' => 'nullable|boolean',
                'is_editable' => 'nullable|boolean',
            ]);

            $data = $request->only([
                'title',
                'description',
                'priority',
                'status',
                'assigned_to',
                'due_date',
                'subtasks',
                'tags',
                'progress',
                'is_pinned',
                'is_editable',
            ]);

            if (array_key_exists('assigned_to', $data) && ! $this->canManageProjectTasks($task->project, (int) Auth::id())) {
                unset($data['assigned_to']);
            }

            if (($data['status'] ?? null) === 'completed' && ! $this->canManageProjectTasks($task->project, (int) Auth::id())) {
                return redirect()->back()
                    ->with('error', 'Only project admins or the project owner can mark a task as completed.');
            }

            // Handle status changes
            if (isset($data['status'])) {
                if ($data['status'] === 'in_progress' && ! $task->started_at) {
                    $data['started_at'] = now();
                }

                if ($data['status'] === 'completed' && ! $task->completed_at) {
                    $data['completed_at'] = now();
                    $data['progress'] = 100;
                }
            }

            // Handle assigned_to - allow null to unassign
            if (isset($data['assigned_to']) && $data['assigned_to'] === '') {
                $data['assigned_to'] = null;
            }

            // Check if assigned_to is being changed
            $oldAssignedTo = $task->assigned_to ? (int) $task->assigned_to : null;
            $newAssignedTo = isset($data['assigned_to']) ? ($data['assigned_to'] ? (int) $data['assigned_to'] : null) : $oldAssignedTo;
            $currentUserId = (int) Auth::id();

            // Temporarily disable foreign key checks for SQLite
            DB::statement('PRAGMA foreign_keys=OFF');

            $task->update($data);

            // Refresh task to get updated data
            $task->refresh();

            // Re-enable foreign key checks
            DB::statement('PRAGMA foreign_keys=ON');

            // Create notification if assigned_to changed (always notify when task is assigned)
            if ($newAssignedTo && $newAssignedTo !== $oldAssignedTo) {
                Log::info('Creating task assignment notification on update', [
                    'task_id' => $task->id,
                    'assigned_to' => $newAssignedTo,
                    'assigned_by' => $currentUserId,
                    'old_assigned_to' => $oldAssignedTo,
                ]);
                $this->createTaskAssignmentNotification($task, $newAssignedTo, $currentUserId);
            }

            // Update project last activity
            $task->project->update([
                'last_activity' => now(),
                'is_updated' => true,
            ]);

            return redirect()->back()
                ->with('success', 'Task updated successfully!');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Failed to update task: '.$e->getMessage());
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Task $task)
    {
        try {
            if (! $this->canDeleteTask($task, (int) Auth::id())) {
                abort(403, 'You can only delete tasks you created.');
            }

            // Temporarily disable foreign key checks for SQLite
            DB::statement('PRAGMA foreign_keys=OFF');

            $task->delete();

            // Re-enable foreign key checks
            DB::statement('PRAGMA foreign_keys=ON');

            return redirect()->back()
                ->with('success', 'Task deleted successfully!');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Failed to delete task: '.$e->getMessage());
        }
    }

    /**
     * Update task status
     */
    public function updateStatus(Request $request, Task $task)
    {
        try {
            $this->ensureCanWorkOnTask($task);

            $request->validate([
                'status' => 'required|in:todo,in_progress,review,completed',
            ]);

            if ($request->status === 'completed' && ! $this->canManageProjectTasks($task->project, (int) Auth::id())) {
                return back()->withErrors(['message' => 'Only project admins or the project owner can mark a task as completed.']);
            }

            if ($request->status === 'completed') {
                $subtasks = $task->subtasks ?? [];
                $hasIncompleteSubtasks = collect($subtasks)->contains(fn ($subtask) => ! ($subtask['completed'] ?? false));

                if ($hasIncompleteSubtasks) {
                    return back()->withErrors(['message' => 'Cannot mark task as complete. Please complete all subtasks first.']);
                }
            }

            $data = ['status' => $request->status];

            // Handle status changes
            if ($request->status === 'in_progress' && ! $task->started_at) {
                $data['started_at'] = now();
            }

            if ($request->status === 'completed' && ! $task->completed_at) {
                $data['completed_at'] = now();
                $data['progress'] = 100;
            }

            // Temporarily disable foreign key checks for SQLite
            DB::statement('PRAGMA foreign_keys=OFF');

            $task->update($data);

            // Re-enable foreign key checks
            DB::statement('PRAGMA foreign_keys=ON');

            return back()->with(['success' => true, 'message' => 'Task status updated successfully!']);
        } catch (\Exception $e) {
            return back()->with(['success' => false, 'message' => 'Failed to update task status: '.$e->getMessage()], 500);
        }
    }

    /**
     * Add subtask to task
     */
    public function addSubtask(Request $request, Task $task)
    {
        $this->ensureCanWorkOnTask($task);

        $request->validate([
            'title' => 'required|string|max:255',
            'completed' => 'boolean',
        ]);

        $subtasks = $task->subtasks ?? [];
        $subtasks[] = [
            'id' => uniqid(),
            'title' => $request->title,
            'completed' => $request->completed ?? false,
            'created_at' => now()->toISOString(),
        ];

        $task->update(['subtasks' => $subtasks]);

        return redirect()->back()
            ->with('success', 'Subtask added successfully.');
    }

    /**
     * Update subtask
     */
    public function updateSubtask(Request $request, Task $task)
    {
        $this->ensureCanWorkOnTask($task);

        $request->validate([
            'subtask_id' => 'required|string',
            'title' => 'nullable|string|max:255',
            'completed' => 'nullable|boolean',
        ]);

        $subtasks = $task->subtasks ?? [];
        $subtaskIndex = array_search($request->subtask_id, array_column($subtasks, 'id'));

        if ($subtaskIndex !== false) {
            if ($request->has('title')) {
                $subtasks[$subtaskIndex]['title'] = $request->title;
            }
            if ($request->has('completed')) {
                $subtasks[$subtaskIndex]['completed'] = $request->completed;
            }
            $subtasks[$subtaskIndex]['updated_at'] = now()->toISOString();

            // Temporarily disable foreign key checks for SQLite
            DB::statement('PRAGMA foreign_keys=OFF');

            $task->update(['subtasks' => $subtasks]);

            // Re-enable foreign key checks
            DB::statement('PRAGMA foreign_keys=ON');

            return redirect()->back()
                ->with('success', 'Subtask updated successfully.');
        }

        return redirect()->back()
            ->with('error', 'Subtask not found.');
    }

    /**
     * Delete subtask
     */
    public function deleteSubtask(Request $request, Task $task)
    {
        $this->ensureCanWorkOnTask($task);

        $request->validate([
            'subtask_id' => 'required|string',
        ]);

        $subtasks = $task->subtasks ?? [];
        $subtasks = array_filter($subtasks, fn ($subtask) => $subtask['id'] !== $request->subtask_id);

        $task->update(['subtasks' => array_values($subtasks)]);

        return redirect()->back()
            ->with('success', 'Subtask deleted successfully.');
    }

    /**
     * Toggle task pin status
     */
    public function togglePin(Task $task)
    {
        try {
            $this->ensureCanManageProjectTasks($task->project);

            // Temporarily disable foreign key checks for SQLite
            DB::statement('PRAGMA foreign_keys=OFF');

            $task->update(['is_pinned' => ! $task->is_pinned]);

            // Re-enable foreign key checks
            DB::statement('PRAGMA foreign_keys=ON');

            return redirect()->back()
                ->with('success', $task->is_pinned ? 'Task pinned successfully!' : 'Task unpinned successfully!');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Failed to toggle task pin: '.$e->getMessage());
        }
    }

    /**
     * Update task title
     */
    public function updateTitle(Request $request, Task $task)
    {
        try {
            $this->ensureCanWorkOnTask($task);

            $request->validate([
                'title' => 'required|string|max:255',
            ]);

            // Temporarily disable foreign key checks for SQLite
            DB::statement('PRAGMA foreign_keys=OFF');

            $task->update(['title' => $request->title]);

            // Re-enable foreign key checks
            DB::statement('PRAGMA foreign_keys=ON');

            return back()->with('success', 'Task title updated successfully!');
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to update task title: '.$e->getMessage()], 500);
        }
    }

    /**
     * Update task description
     */
    public function updateDescription(Request $request, Task $task)
    {
        try {
            $this->ensureCanWorkOnTask($task);

            $request->validate([
                'description' => 'nullable|string',
            ]);

            // Temporarily disable foreign key checks for SQLite
            DB::statement('PRAGMA foreign_keys=OFF');

            $task->update(['description' => $request->description]);

            // Re-enable foreign key checks
            DB::statement('PRAGMA foreign_keys=ON');

            return redirect()->back()->with('success', 'Task description updated successfully!');
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to update task description: '.$e->getMessage()], 500);
        }
    }

    /**
     * Update task priority
     */
    public function updatePriority(Request $request, Task $task)
    {
        try {
            $this->ensureCanWorkOnTask($task);

            $request->validate([
                'priority' => 'required|in:low,medium,high,urgent',
            ]);

            // Temporarily disable foreign key checks for SQLite
            DB::statement('PRAGMA foreign_keys=OFF');

            $task->update(['priority' => $request->priority]);

            // Re-enable foreign key checks
            DB::statement('PRAGMA foreign_keys=ON');

            return redirect()->back()->with('success', 'Task priority updated successfully!');
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to update task priority: '.$e->getMessage()], 500);
        }
    }

    /**
     * Add attachment to task
     */
    public function addAttachment(Request $request, Task $task)
    {
        try {
            $this->ensureCanWorkOnTask($task);

            $request->validate([
                'file' => 'required|file|max:10240|mimes:jpeg,jpg,png,webp,gif,pdf,doc,docx,xls,xlsx,ppt,pptx,zip,mp3,mp4,webm',
                'name' => 'nullable|string|max:255',
            ]);

            $file = $request->file('file');
            $filename = time().'_'.$file->getClientOriginalName();
            $path = $file->storeAs('task-attachments', $filename, 'public');

            $attachments = $task->attachments ?? [];
            $attachments[] = [
                'id' => uniqid(),
                'name' => $request->name ?? $file->getClientOriginalName(),
                'path' => $path,
                'size' => $file->getSize(),
                'type' => $file->getMimeType(),
                'uploaded_by' => Auth::id(),
                'uploaded_at' => now()->toISOString(),
            ];

            $task->update(['attachments' => $attachments]);

            return redirect()->back()
                ->with('success', 'Attachment added successfully.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Failed to upload attachment: '.$e->getMessage());
        }
    }

    /**
     * Remove attachment from task
     */
    public function removeAttachment(Request $request, Task $task)
    {
        try {
            $this->ensureCanWorkOnTask($task);

            $request->validate([
                'attachment_id' => 'required|string',
            ]);

            $attachments = $task->attachments ?? [];
            $attachments = array_filter($attachments, fn ($attachment) => $attachment['id'] !== $request->attachment_id);

            $task->update(['attachments' => array_values($attachments)]);

            return redirect()->back()
                ->with('success', 'Attachment removed successfully.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Failed to remove attachment: '.$e->getMessage());
        }
    }

    /**
     * Toggle task collaborators.
     */
    public function updateAssignedTo(Request $request, Task $task)
    {
        try {
            $this->ensureCanManageProjectTasks($task->project);

            $request->validate([
                'assigned_to' => 'nullable|exists:users,id',
            ]);

            $assignedTo = $request->assigned_to ? (int) $request->assigned_to : null;
            $currentUserId = (int) Auth::id();
            $assignees = collect($task->assignees ?? [])
                ->map(fn ($assignee) => is_array($assignee) ? ($assignee['id'] ?? null) : $assignee)
                ->filter()
                ->map(fn ($assigneeId) => (int) $assigneeId)
                ->unique()
                ->values();

            if (! $assignedTo) {
                return back()->with('success', 'Task collaborators updated successfully!');
            }

            if ((int) $task->assigned_to === $assignedTo) {
                return back()->with('success', 'Task collaborators updated successfully!');
            }

            $wasAssigned = $assignees->contains($assignedTo);
            $updatedAssignees = $wasAssigned
                ? $assignees->reject(fn ($assigneeId) => $assigneeId === $assignedTo)->values()
                : $assignees->push($assignedTo)->unique()->values();

            // Temporarily disable foreign key checks for SQLite
            DB::statement('PRAGMA foreign_keys=OFF');

            $task->update(['assignees' => $updatedAssignees->all()]);

            // Refresh task to get updated data
            $task->refresh();

            // Re-enable foreign key checks
            DB::statement('PRAGMA foreign_keys=ON');

            if (! $wasAssigned) {
                Log::info('Creating task assignment notification', [
                    'task_id' => $task->id,
                    'assigned_to' => $assignedTo,
                    'assigned_by' => $currentUserId,
                ]);
                $this->createTaskAssignmentNotification($task, $assignedTo, $currentUserId);
            } else {
                Log::info('Removed task collaborator', [
                    'task_id' => $task->id,
                    'assigned_to' => $assignedTo,
                    'current_user_id' => $currentUserId,
                ]);
            }

            return back()->with('success', 'Task collaborators updated successfully!');
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add comment to task
     */
    public function addComment(Request $request, Task $task)
    {
        try {
            $this->ensureCanViewTask($task);

            $request->validate([
                'content' => 'required|string|max:1000',
            ]);

            $comments = $task->comments ?? [];
            $comments[] = [
                'id' => uniqid(),
                'content' => $request->content,
                'user_id' => Auth::id(),
                'user' => Auth::user()->only('id', 'name', 'email', 'image'),
                'created_at' => now()->toISOString(),
            ];

            $task->update(['comments' => $comments]);

            return redirect()->back()
                ->with('success', 'Comment added successfully.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Failed to add comment: '.$e->getMessage());
        }
    }

    /**
     * Update comment
     */
    public function updateComment(Request $request, Task $task, $commentId)
    {
        try {
            $this->ensureCanViewTask($task);

            $request->validate([
                'content' => 'required|string|max:1000',
            ]);

            $comments = $task->comments ?? [];
            $commentIndex = array_search($commentId, array_column($comments, 'id'));

            if ($commentIndex !== false) {
                $comments[$commentIndex]['content'] = $request->content;
                $comments[$commentIndex]['updated_at'] = now()->toISOString();
                $task->update(['comments' => $comments]);

                return redirect()->back()
                    ->with('success', 'Comment updated successfully.');
            }

            return redirect()->back()
                ->with('error', 'Comment not found.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Failed to update comment: '.$e->getMessage());
        }
    }

    /**
     * Delete comment
     */
    public function deleteComment(Request $request, Task $task, $commentId)
    {
        try {
            $this->ensureCanViewTask($task);

            $comments = $task->comments ?? [];
            $commentIndex = array_search($commentId, array_column($comments, 'id'));

            if ($commentIndex !== false) {
                unset($comments[$commentIndex]);
                $task->update(['comments' => array_values($comments)]);

                return redirect()->back()
                    ->with('success', 'Comment deleted successfully.');
            }

            return redirect()->back()
                ->with('error', 'Comment not found.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Failed to delete comment: '.$e->getMessage());
        }
    }

    /**
     * Create and broadcast task assignment notification
     */
    private function createTaskAssignmentNotification(Task $task, $assignedToUserId, $assignedByUserId)
    {
        try {
            $assignedToUser = User::find($assignedToUserId);
            $assignedByUser = User::find($assignedByUserId);

            if (! $assignedToUser) {
                Log::warning('Cannot create task assignment notification: assigned_to_user not found', [
                    'assigned_to_user_id' => $assignedToUserId,
                    'task_id' => $task->id,
                ]);

                return;
            }

            if (! $assignedByUser) {
                Log::warning('Cannot create task assignment notification: assigned_by_user not found', [
                    'assigned_by_user_id' => $assignedByUserId,
                    'task_id' => $task->id,
                ]);

                return;
            }

            $project = $task->project;
            $message = "{$assignedByUser->name} assigned you the task \"{$task->title}\"";
            if ($project) {
                $message .= " in project \"{$project->name}\"";
            }

            $path = "/admin/projects/{$task->project_id}?task={$task->id}";

            // Create notification
            $notification = TaskAssignmentNotification::create([
                'task_id' => $task->id,
                'assigned_to_user_id' => $assignedToUserId,
                'assigned_by_user_id' => $assignedByUserId,
                'message_notification' => $message,
                'path' => $path,
            ]);

            Log::info('Task assignment notification created successfully', [
                'notification_id' => $notification->id,
                'task_id' => $task->id,
                'assigned_to_user_id' => $assignedToUserId,
                'assigned_by_user_id' => $assignedByUserId,
            ]);

            // Send Expo push notification
            try {
                $assignedToUser->refresh();
                if ($assignedToUser->expo_push_token) {
                    $pushService = app(\App\Services\ExpoPushNotificationService::class);
                    Log::info('Sending push notification for task assignment', [
                        'assigned_to_user_id' => $assignedToUserId,
                        'task_id' => $task->id,
                    ]);
                    $success = $pushService->sendToUser($assignedToUser, 'Task Assigned', $message, [
                        'type' => 'task_assignment',
                        'notification_id' => $notification->id,
                        'task_id' => $task->id,
                        'assigned_by_user_id' => $assignedByUserId,
                        'assigned_by_name' => $assignedByUser->name,
                    ]);
                    if (! $success) {
                        Log::warning('Push notification send returned false for task assignment');
                    }
                }
            } catch (\Exception $e) {
                Log::error('Failed to send Expo push notification for task assignment', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'notification_id' => $notification->id ?? null,
                ]);
            }

            // Broadcast notification via Ably for real-time updates
            try {
                $ablyKey = config('services.ably.key');
                if ($ablyKey) {
                    $ably = new AblyRest($ablyKey);
                    $channel = $ably->channels->get("notifications:{$assignedToUserId}");

                    $channel->publish('new_notification', [
                        'id' => 'task-assignment-'.$notification->id,
                        'type' => 'task_assignment',
                        'sender_name' => $assignedByUser->name,
                        'sender_image' => $assignedByUser->image,
                        'message' => $message,
                        'link' => $path,
                        'icon_type' => 'briefcase',
                        'created_at' => $notification->created_at->format('Y-m-d H:i:s'),
                        'read_at' => null,
                    ]);

                    Log::info('Task assignment notification broadcasted via Ably', [
                        'notification_id' => $notification->id,
                        'channel' => "notifications:{$assignedToUserId}",
                    ]);
                } else {
                    Log::warning('Ably key not configured, skipping real-time broadcast');
                }
            } catch (\Exception $e) {
                Log::error('Failed to broadcast task assignment notification via Ably', [
                    'error' => $e->getMessage(),
                    'notification_id' => $notification->id ?? null,
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to create task assignment notification', [
                'error' => $e->getMessage(),
                'task_id' => $task->id ?? null,
                'assigned_to_user_id' => $assignedToUserId ?? null,
                'assigned_by_user_id' => $assignedByUserId ?? null,
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
