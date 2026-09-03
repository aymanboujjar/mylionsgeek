<?php

namespace App\Http\Controllers\Admin;

use Ably\AblyRest;
use App\Http\Controllers\Controller;
use App\Mail\ProjectInvitationMail;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\ProjectInvitation;
use App\Models\ProjectMessage;
use App\Models\ProjectMessageNotification;
use App\Models\ProjectMessageReaction;
use App\Models\ProjectRepositoryEvent;
use App\Models\ProjectUser;
use App\Models\Task;
use App\Models\User;
use App\Services\GitHubWebhookVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ProjectController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $userId = Auth::id();

        // Filter projects: show only projects where user is owner or team member
        $query = Project::with(['creator', 'users:id,name,image', 'tasks'])
            ->withCount(['tasks', 'users'])
            ->where(function ($q) use ($userId) {
                // User is the owner
                $q->where('created_by', $userId)
                    // OR user is a team member
                    ->orWhereHas('users', function ($userQuery) use ($userId) {
                        $userQuery->where('users.id', $userId);
                    });
            });

        // Search functionality
        if ($request->has('search') && $request->search) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'like', '%'.$searchTerm.'%')
                    ->orWhere('description', 'like', '%'.$searchTerm.'%');
            });
        }

        // Filter by status
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        // Sort functionality
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $projects = $query->paginate(12);

        // Statistics - only count projects user has access to
        $accessibleProjectsQuery = Project::where(function ($q) use ($userId) {
            $q->where('created_by', $userId)
                ->orWhereHas('users', function ($userQuery) use ($userId) {
                    $userQuery->where('users.id', $userId);
                });
        });

        $stats = [
            'total' => $accessibleProjectsQuery->count(),
            'active' => (clone $accessibleProjectsQuery)->where('status', 'active')->count(),
            'completed' => (clone $accessibleProjectsQuery)->where('status', 'completed')->count(),
            'on_hold' => (clone $accessibleProjectsQuery)->where('status', 'on_hold')->count(),
            'cancelled' => (clone $accessibleProjectsQuery)->where('status', 'cancelled')->count(),
        ];

        // Get all users for invite suggestions
        $users = User::select('id', 'name', 'email', 'image')->get();

        return Inertia::render('admin/projects/index', [
            'projects' => $projects,
            'stats' => $stats,
            'filters' => $request->only(['search', 'status', 'sort_by', 'sort_order']),
            'users' => $users,
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after:start_date',
                'status' => 'nullable|in:active,completed,on_hold,cancelled',
            ]);

            $data = $request->all();
            $data['created_by'] = Auth::id();
            $data['status'] = $data['status'] ?? 'active';

            if ($request->hasFile('photo')) {
                $data['photo'] = $request->file('photo')->store('projects', 'public');
            }

            // Temporarily disable foreign key checks for SQLite
            DB::statement('PRAGMA foreign_keys = OFF');

            $project = Project::create($data);

            // Add creator as owner - use raw SQL to avoid foreign key issues
            DB::table('project_users')->insert([
                'project_id' => $project->id,
                'user_id' => Auth::id(),
                'role' => 'owner',
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Re-enable foreign key checks
            DB::statement('PRAGMA foreign_keys = ON');

            // Create predefined tasks if any were selected
            if ($request->has('predefined_tasks')) {
                $predefinedTasks = $request->predefined_tasks;

                // Handle different input types: string (JSON), array, or null/empty
                if (is_string($predefinedTasks) && ! empty($predefinedTasks)) {
                    $decoded = json_decode($predefinedTasks, true);
                    $predefinedTasks = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
                } elseif (! is_array($predefinedTasks)) {
                    $predefinedTasks = [];
                }

                // Ensure we have a valid array with items
                if (is_array($predefinedTasks) && count($predefinedTasks) > 0) {
                    // Map task values to their titles
                    $taskTitles = [
                        'creation_du_site_web' => 'Creation du site web',
                        'creation_de_contenue_reseaux_sociaux' => 'Creation de contenue sur les reseau sociaux',
                        'shooting_images_videos' => 'Shooting and images and videos',
                    ];

                    // Temporarily disable foreign key checks for SQLite
                    DB::statement('PRAGMA foreign_keys = OFF');

                    foreach ($predefinedTasks as $taskValue) {
                        if (isset($taskTitles[$taskValue])) {
                            Task::create([
                                'title' => $taskTitles[$taskValue],
                                'description' => null,
                                'project_id' => $project->id,
                                'created_by' => Auth::id(),
                                'priority' => 'medium',
                                'status' => 'todo',
                                'progress' => 0,
                                'sort_order' => 0,
                            ]);
                        }
                    }

                    // Re-enable foreign key checks
                    DB::statement('PRAGMA foreign_keys = ON');
                }
            }

            return redirect()->route('admin.projects.index')
                ->with('success', 'Project created successfully.');
        } catch (\Exception $e) {
            Log::error('Project creation failed: '.$e->getMessage());

            return redirect()->route('admin.projects.index')
                ->with('error', 'Failed to create project: '.$e->getMessage());
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Project $project)
    {
        $userId = Auth::id();

        // Check if user has access: owner or team member
        $isOwner = (int) $project->created_by === (int) $userId;
        $isTeamMember = ProjectUser::where('project_id', $project->id)
            ->where('user_id', $userId)
            ->exists();

        if (! $isOwner && ! $isTeamMember) {
            abort(403, 'You do not have access to this project.');
        }

        $project->load([
            'creator',
            'users',
            'tasks.assignedTo',
            'tasks.creator',
            'attachments.uploader',
            'repositoryEvents',
        ]);

        $teamMembers = ProjectUser::with('user')
            ->where('project_id', $project->id)
            ->get()
            ->filter(function ($projectUser) {
                return $projectUser->user !== null && $projectUser->user->id !== null;
            })
            ->map(function ($projectUser) use ($project) {
                // Check if this user is the project owner (created_by or has owner role)
                $isOwner = (int) $project->created_by === (int) $projectUser->user_id || $projectUser->role === 'owner';

                return [
                    'id' => $projectUser->user->id,
                    'name' => $projectUser->user->name ?? 'Unknown',
                    'email' => $projectUser->user->email ?? '',
                    'image' => $projectUser->user->image ?? null,
                    'last_online' => $projectUser->user->last_online ?? null,
                    'role' => $projectUser->role ?? 'member',
                    'project_user_id' => $projectUser->id,
                    'isOwner' => $isOwner,
                ];
            })
            ->values();

        $tasks = $project->tasks()->with(['assignedTo', 'creator'])->get();
        $attachments = $project->attachments()->with(['uploader:id,name,image,last_online'])->get();
        $notes = $project->notes()->with('user')->orderBy('is_pinned', 'desc')->orderBy('created_at', 'desc')->get();
        $repositoryEvents = $project->repositoryEvents()->latest('occurred_at')->limit(50)->get();

        // Get current user's role in this project
        $currentUserProjectRole = ProjectUser::where('project_id', $project->id)
            ->where('user_id', $userId)
            ->first();

        $canManageTeam = $isOwner;

        return Inertia::render('admin/projects/[id]', [
            'project' => $project,
            'teamMembers' => $teamMembers,
            'tasks' => $tasks,
            'attachments' => $attachments,
            'notes' => $notes,
            'repositoryEvents' => $repositoryEvents,
            'currentUserProjectRole' => $currentUserProjectRole ? $currentUserProjectRole->role : null,
            'canManageTeam' => $canManageTeam,
            'isProjectOwner' => $isOwner,
        ]);
    }

    public function recordGitHubRepositoryEvent(Request $request, Project $project, GitHubWebhookVerifier $verifier)
    {
        $secret = $verifier->configuredSecret();
        if ($secret === null) {
            return response()->json(['error' => 'GitHub webhook is not configured.'], 503);
        }

        if (! $verifier->signatureIsValid($request, $secret)) {
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        $eventName = $request->header('X-GitHub-Event', 'repository');
        $payload = $request->all();

        if ($eventName === 'ping') {
            return response()->json(['ok' => true, 'message' => 'Webhook connected']);
        }

        $repository = $payload['repository'] ?? [];
        $sender = $payload['sender'] ?? [];
        $eventType = 'github_event';
        $action = $payload['action'] ?? null;
        $branch = null;
        $commitSha = null;
        $title = ucfirst(str_replace('_', ' ', $eventName));
        $url = $repository['html_url'] ?? null;

        if ($eventName === 'push') {
            $eventType = 'github_push';
            $branch = isset($payload['ref']) ? basename($payload['ref']) : null;
            $headCommit = $payload['head_commit'] ?? null;
            $commitSha = isset($payload['after']) ? substr($payload['after'], 0, 7) : null;
            $commitCount = count($payload['commits'] ?? []);
            $title = 'Pushed '.$commitCount.' commit'.($commitCount === 1 ? '' : 's').($branch ? " to {$branch}" : '');
            $url = $headCommit['url'] ?? $repository['html_url'] ?? null;
        } elseif ($eventName === 'pull_request') {
            $pullRequest = $payload['pull_request'] ?? [];
            $isMerged = ($payload['action'] ?? null) === 'closed' && ($pullRequest['merged'] ?? false);
            $eventType = $isMerged ? 'github_merge' : 'github_pull_request';
            $branch = $pullRequest['head']['ref'] ?? null;
            $title = ($isMerged ? 'Merged' : ucfirst($payload['action'] ?? 'updated')).' pull request #'.($payload['number'] ?? '');
            if (! empty($pullRequest['title'])) {
                $title .= ': '.$pullRequest['title'];
            }
            $url = $pullRequest['html_url'] ?? $repository['html_url'] ?? null;
        } elseif ($eventName === 'fork') {
            $eventType = 'github_fork';
            $forkee = $payload['forkee'] ?? [];
            $title = 'Forked repository'.(! empty($forkee['full_name']) ? ' to '.$forkee['full_name'] : '');
            $url = $forkee['html_url'] ?? $repository['html_url'] ?? null;
        }

        ProjectRepositoryEvent::create([
            'project_id' => $project->id,
            'provider' => 'github',
            'event_type' => $eventType,
            'action' => $action,
            'actor_name' => $sender['login'] ?? null,
            'actor_avatar' => $sender['avatar_url'] ?? null,
            'repository_name' => $repository['full_name'] ?? $repository['name'] ?? null,
            'repository_url' => $repository['html_url'] ?? null,
            'branch' => $branch,
            'commit_sha' => $commitSha,
            'title' => $title,
            'url' => $url,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);

        $project->update([
            'last_activity' => now(),
            'is_updated' => true,
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Project $project)
    {
        $userId = Auth::id();

        if ((int) $project->created_by !== (int) $userId) {
            abort(403, 'Only the project owner can edit this project.');
        }

        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                // 'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
                'status' => 'required|in:active,completed,on_hold,cancelled',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after:start_date',
            ]);

            $data = $request->only(['name', 'description', 'status', 'start_date', 'end_date']);
            $data['is_updated'] = true;
            $data['last_activity'] = now();

            // Debug: Log what we're receiving
            Log::info('Update request data:', [
                'all' => $request->all(),
                'hasFile' => $request->hasFile('photo'),
                'file' => $request->file('photo'),
                'data' => $data,
            ]);

            // Only update photo if a new one is uploaded
            if ($request->hasFile('photo')) {
                if ($project->photo) {
                    Storage::disk('public')->delete($project->photo);
                }
                $data['photo'] = $request->file('photo')->store('projects', 'public');
            }

            $project->update($data);

            return redirect()->route('admin.projects.index')
                ->with('success', 'Project updated successfully.');
        } catch (\Exception $e) {
            return redirect()->route('admin.projects.index')
                ->with('error', 'Failed to update project: '.$e->getMessage());
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Project $project)
    {
        // Only owner can delete the project
        $userId = Auth::id();
        $isOwner = (int) $project->created_by === (int) $userId;

        if (! $isOwner) {
            abort(403, 'Only the project owner can delete this project.');
        }

        try {
            // Delete project photo if it exists
            if ($project->photo) {
                Storage::disk('public')->delete($project->photo);
            }

            // Delete the project (cascade will handle related records)
            $project->delete();

            return redirect()
                ->route('admin.projects.index')
                ->with('success', 'Project deleted successfully.');
        } catch (\Exception $e) {
            Log::error('Project deletion failed: '.$e->getMessage());

            return redirect()
                ->route('admin.projects.index')
                ->with('error', 'Failed to delete project: '.$e->getMessage());
        }
    }

    /**
     * Invite users to project via email
     */
    public function invite(Request $request)
    {
        try {
            Log::info('Invite request received:', [
                'project_id' => $request->project_id,
                'emails' => $request->emails ?? [],
                'usernames' => $request->usernames ?? [],
                'emails_count' => count($request->emails ?? []),
                'usernames_count' => count($request->usernames ?? []),
                'role' => $request->role,
                'all_request_data' => $request->all(),
            ]);

            $request->validate([
                'emails' => 'nullable|array',
                'emails.*' => 'required|email',
                'usernames' => 'nullable|array',
                'usernames.*' => 'required|string',
                'role' => 'required|in:admin,member',
                'message' => 'nullable|string|max:500',
                'project_id' => 'required|exists:projects,id',
            ]);

            $project = Project::findOrFail($request->project_id);

            if ((int) $project->created_by !== (int) Auth::id()) {
                abort(403, 'Only the project owner can invite members.');
            }

            $emails = $request->emails ?? [];
            $usernames = $request->usernames ?? [];
            $role = $request->role;
            $message = $request->message;

            // Validate that at least one email or username is provided
            if (empty($emails) && empty($usernames)) {
                return back()->with('error', 'Please provide at least one email address or username to invite.');
            }

            $invitationsSent = 0;
            $invitationsCreated = 0;
            $emailsLogged = 0;
            $errors = [];
            $mailDriver = config('mail.default');

            // Process email invitations - always create invitations, never add directly
            foreach ($emails as $email) {
                // Check if user is already in project
                $user = User::where('email', $email)->first();
                if ($user && $project->users()->where('user_id', $user->id)->exists()) {
                    $errors[] = "{$email} is already a member of this project.";

                    continue;
                }

                // Check if invitation already exists and is still valid
                $existingInvitation = ProjectInvitation::where('project_id', $project->id)
                    ->where('email', $email)
                    ->where('is_used', false)
                    ->where('expires_at', '>', now())
                    ->first();

                if ($existingInvitation) {
                    $errors[] = "An invitation has already been sent to {$email}.";

                    continue;
                }

                // Create invitation
                $invitation = ProjectInvitation::createInvitation($project->id, $email, null, $role, $message);
                $invitationsCreated++;

                // Send email invitation
                try {
                    $mailHost = config('mail.mailers.smtp.host');
                    $mailPort = config('mail.mailers.smtp.port');
                    $mailEncryption = config('mail.mailers.smtp.encryption');
                    $mailFrom = config('mail.from.address');

                    Log::info("Attempting to send project invitation email to: {$email}");
                    Log::info("Mail config - Driver: {$mailDriver}, Host: {$mailHost}, Port: {$mailPort}, Encryption: {$mailEncryption}, From: {$mailFrom}");

                    Mail::to($email)->send(new ProjectInvitationMail($project, $invitation, $message));

                    if ($mailDriver === 'log') {
                        Log::warning('Email logged to storage/logs/laravel.log (driver: log) - Email was NOT actually sent!');
                        $emailsLogged++;
                    } else {
                        Log::info("✅ Project invitation email sent successfully to: {$email}");
                        $invitationsSent++;
                    }
                } catch (\Exception $e) {
                    $errorMessage = $e->getMessage();
                    Log::error("❌ Failed to send project invitation email to {$email}: {$errorMessage}");
                    Log::error('Exception class: '.get_class($e));
                    Log::error('Exception trace: '.$e->getTraceAsString());

                    // Provide more helpful error messages
                    if (str_contains($errorMessage, 'Connection') || str_contains($errorMessage, 'timeout')) {
                        $errors[] = 'Failed to connect to mail server. Check your SMTP settings in .env file.';
                    } elseif (str_contains($errorMessage, 'Authentication') || str_contains($errorMessage, 'password')) {
                        $errors[] = 'SMTP authentication failed. Check your MAIL_USERNAME and MAIL_PASSWORD in .env file.';
                    } else {
                        $errors[] = "Failed to send invitation to {$email}: {$errorMessage}";
                    }
                }
            }

            // Process username invitations - always create invitations, never add directly
            foreach ($usernames as $username) {
                $user = User::where('name', $username)->first();

                if (! $user) {
                    $errors[] = "User @{$username} not found.";

                    continue;
                }

                // Check if user is already in project
                if ($project->users()->where('user_id', $user->id)->exists()) {
                    $errors[] = "@{$username} is already a member of this project.";

                    continue;
                }

                // Check if invitation already exists and is still valid
                $existingInvitation = ProjectInvitation::where('project_id', $project->id)
                    ->where('username', $username)
                    ->where('is_used', false)
                    ->where('expires_at', '>', now())
                    ->first();

                if ($existingInvitation) {
                    $errors[] = "An invitation has already been sent to @{$username}.";

                    continue;
                }

                // Create invitation
                $invitation = ProjectInvitation::createInvitation($project->id, $user->email, $username, $role, $message);
                $invitationsCreated++;

                // Send email invitation
                try {
                    $mailHost = config('mail.mailers.smtp.host');
                    $mailPort = config('mail.mailers.smtp.port');
                    $mailEncryption = config('mail.mailers.smtp.encryption');

                    Log::info("Attempting to send project invitation email to: {$user->email} (@{$username})");
                    Log::info("Mail config - Driver: {$mailDriver}, Host: {$mailHost}, Port: {$mailPort}, Encryption: {$mailEncryption}");

                    Mail::to($user->email)->send(new ProjectInvitationMail($project, $invitation, $message));

                    if ($mailDriver === 'log') {
                        Log::warning('Email logged to storage/logs/laravel.log (driver: log) - Email was NOT actually sent!');
                        $emailsLogged++;
                    } else {
                        Log::info("✅ Project invitation email sent successfully to: {$user->email} (@{$username})");
                        $invitationsSent++;
                    }
                } catch (\Exception $e) {
                    $errorMessage = $e->getMessage();
                    Log::error("❌ Failed to send project invitation email to {$user->email}: {$errorMessage}");
                    Log::error('Exception class: '.get_class($e));
                    Log::error('Exception trace: '.$e->getTraceAsString());

                    // Provide more helpful error messages
                    if (str_contains($errorMessage, 'Connection') || str_contains($errorMessage, 'timeout')) {
                        $errors[] = "Failed to connect to mail server for @{$username}. Check your SMTP settings.";
                    } elseif (str_contains($errorMessage, 'Authentication') || str_contains($errorMessage, 'password')) {
                        $errors[] = "SMTP authentication failed for @{$username}. Check your mail credentials.";
                    } else {
                        $errors[] = "Failed to send invitation to @{$username}: {$errorMessage}";
                    }
                }
            }

            // Build response message
            if ($invitationsCreated > 0) {
                $messages = [];

                if ($invitationsSent > 0) {
                    $messages[] = "{$invitationsSent} invitation email(s) sent successfully.";
                }

                if ($emailsLogged > 0) {
                    $logPath = storage_path('logs/laravel.log');
                    $messages[] = "⚠️ {$emailsLogged} invitation(s) created but emails were NOT sent (logged only). Mail driver is set to 'log'. To actually send emails, configure SMTP in your .env file. Check {$logPath} to see the email content.";
                }

                if (! empty($errors)) {
                    $messages[] = implode(' ', $errors);
                }

                $message = implode(' ', $messages);

                // Use warning if emails were only logged, success if actually sent
                if ($invitationsSent > 0 && $emailsLogged === 0) {
                    return back()->with('success', $message);
                } elseif ($emailsLogged > 0) {
                    return back()->with('warning', $message);
                } else {
                    return back()->with('success', $message);
                }
            } else {
                return back()->with('error', ! empty($errors) ? implode(' ', $errors) : 'No invitations were created.');
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation failed for project invitation:', [
                'errors' => $e->errors(),
                'request_data' => $request->all(),
            ]);

            return back()->withErrors($e->errors())->withInput();
        } catch (\Exception $e) {
            Log::error('Failed to process project invitation: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all(),
            ]);

            return back()->with('error', 'Failed to process invitation: '.$e->getMessage());
        }
    }

    /**
     * Invite user to project
     */
    public function inviteUser(Request $request, Project $project)
    {
        if ((int) $project->created_by !== (int) Auth::id()) {
            abort(403, 'Only the project owner can invite members.');
        }

        $request->validate([
            'email' => 'required|email',
            'role' => 'required|in:admin,member',
            'message' => 'nullable|string|max:500',
        ]);

        $email = $request->email;
        $role = $request->role;
        $message = $request->message;

        // Find user by email
        $user = User::where('email', $email)->first();
        if (! $user) {
            session()->flash('error', 'email  not found');

            return Inertia::location(url()->previous());
        }
        // Check if user is already in project
        if ($user && $project->users()->where('user_id', $user->id)->exists()) {
            return back()->with('error', 'User is already a member of this project.');
        }

        // Check if invitation already exists and is still valid
        $existingInvitation = ProjectInvitation::where('project_id', $project->id)
            ->where('email', $email)
            ->where('is_used', false)
            ->where('expires_at', '>', now())
            ->first();

        if ($existingInvitation) {
            return back()->with('error', 'An invitation has already been sent to this email address.');
        }

        // Create invitation
        $invitation = ProjectInvitation::createInvitation($project->id, $email, null, $role, $message);

        // Send email invitation
        try {
            $mailDriver = config('mail.default');
            Log::info("Attempting to send project invitation email to: {$email} (Mail driver: {$mailDriver})");

            // Check mail configuration
            if ($mailDriver === 'log') {
                Log::warning("Mail driver is set to 'log'. Emails will be logged to storage/logs/laravel.log instead of being sent.");
            }

            Mail::to($email)->send(new ProjectInvitationMail($project, $invitation, $message));

            if ($mailDriver === 'log') {
                Log::info('Email logged to storage/logs/laravel.log (driver: log)');

                return back()->with('success', 'Invitation created. Email logged (mail driver is set to "log"). Check storage/logs/laravel.log for the email content.');
            } else {
                Log::info("Project invitation email sent successfully to: {$email}");

                return back()->with('success', 'Invitation sent successfully via email.');
            }
        } catch (\Exception $e) {
            Log::error('Failed to send project invitation email to '.$email.': '.$e->getMessage());
            Log::error('Exception trace: '.$e->getTraceAsString());

            return back()->with('error', 'Failed to send invitation email: '.$e->getMessage());
        }
    }

    /**
     * Remove user from project
     */
    public function removeUser(Project $project, User $user)
    {
        if ((int) $project->created_by !== (int) Auth::id()) {
            abort(403, 'Only the project owner can manage team members.');
        }

        // Check if user is the project owner
        $isProjectOwner = $project->created_by === $user->id;

        // Check if user has owner role in project
        $projectUser = ProjectUser::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->first();

        $hasOwnerRole = $projectUser && $projectUser->role === 'owner';

        if ($isProjectOwner || $hasOwnerRole) {
            return back()->with('error', 'Cannot remove the project owner from the project.');
        }

        $project->users()->detach($user->id);

        return back()->with('success', 'User removed from project successfully.');
    }

    /**
     * Update user role in project
     */
    public function updateRole(Request $request, Project $project, User $user)
    {
        if ((int) $project->created_by !== (int) Auth::id()) {
            abort(403, 'Only the project owner can manage team members.');
        }

        $request->validate([
            'role' => 'required|in:admin,member',
        ]);

        // Check if user is the project owner
        $isProjectOwner = $project->created_by === $user->id;

        // Check if user has owner role in project
        $projectUser = ProjectUser::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->first();

        $hasOwnerRole = $projectUser && $projectUser->role === 'owner';

        if ($isProjectOwner || $hasOwnerRole) {
            return back()->with('error', 'Cannot change the role of the project owner.');
        }

        $project->users()->updateExistingPivot($user->id, [
            'role' => $request->role,
        ]);

        return back()->with('success', 'User role updated successfully.');
    }

    /**
     * Get project statistics
     */
    public function statistics()
    {
        $stats = [
            'total_projects' => Project::count(),
            'active_projects' => Project::where('status', 'active')->count(),
            'completed_projects' => Project::where('status', 'completed')->count(),
            'total_tasks' => Task::count(),
            'completed_tasks' => Task::where('status', 'completed')->count(),
            'overdue_tasks' => Task::where('due_date', '<', now())->where('status', '!=', 'completed')->count(),
        ];

        return response()->json($stats);
    }

    /**
     * Join project via invitation token
     */
    public function join(Project $project, $token)
    {
        $invitation = ProjectInvitation::where('token', $token)
            ->where('project_id', $project->id)
            ->first();

        if (! $invitation) {
            return redirect('/')->with('error', 'Invalid invitation link.');
        }

        if (! $invitation->isValid()) {
            return redirect('/')->with('error', 'This invitation has expired or has already been used.');
        }

        // Check if user is authenticated
        if (! Auth::check()) {
            return redirect()->route('login')->with('error', 'Please log in to join the project.');
        }

        $user = Auth::user();

        // Check if user matches invitation (by email or username)
        $canJoin = false;
        if ($invitation->email && $user->email === $invitation->email) {
            $canJoin = true;
        } elseif ($invitation->username && $user->name === $invitation->username) {
            $canJoin = true;
        }

        if (! $canJoin) {
            return redirect('/')->with('error', 'This invitation is not for your account.');
        }

        // Check if user is already in the project
        if ($project->users()->where('user_id', $user->id)->exists()) {
            return redirect()->route('admin.projects.show', $project->id)
                ->with('info', 'You are already a member of this project.');
        }

        // Add user to project
        $project->users()->attach($user->id, [
            'role' => $invitation->role,
            'invited_at' => $invitation->created_at,
            'joined_at' => now(),
        ]);

        // Mark invitation as used
        $invitation->update(['is_used' => true]);

        return redirect()->route('admin.projects.show', $project->id)
            ->with('success', "You have successfully joined the project: {$project->name}");
    }

    /**
     * Upload attachment to project
     */
    public function uploadAttachment(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:10240', // 10MB max
            'project_id' => 'required|exists:projects,id',
        ]);

        $project = Project::findOrFail($request->project_id);

        // Check if user has access to this project
        if (! $project->users()->where('user_id', Auth::id())->exists() && $project->created_by !== Auth::id()) {
            return redirect()->back()->with('error', 'You do not have permission to upload files to this project.');
        }

        try {
            $file = $request->file('file');
            $originalName = $file->getClientOriginalName();
            $mimeType = $file->getMimeType();
            $size = $file->getSize();

            // Generate unique filename
            $filename = time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
            $path = $file->storeAs('attachments', $filename, 'public');

            // Create attachment record
            $attachment = $project->attachments()->create([
                'name' => $filename,
                'original_name' => $originalName,
                'path' => $path,
                'mime_type' => $mimeType,
                'size' => $size,
                'uploaded_by' => Auth::id(),
            ]);

            return redirect()->back()->with('success', 'File uploaded successfully.');
        } catch (\Exception $e) {
            Log::error('File upload failed: '.$e->getMessage());

            return redirect()->back()->with('error', 'Failed to upload file: '.$e->getMessage());
        }
    }

    /**
     * Delete project attachment
     */
    public function deleteAttachment(Attachment $attachment)
    {
        try {
            // Check if user has permission to delete this attachment
            $project = $attachment->project;
            if (! $project->users()->where('user_id', Auth::id())->exists() && $project->created_by !== Auth::id()) {
                return redirect()->back()->with('error', 'You do not have permission to delete this file.');
            }

            // Delete file from storage
            if ($attachment->path && Storage::disk('public')->exists($attachment->path)) {
                Storage::disk('public')->delete($attachment->path);
            }

            // Delete attachment record
            $attachment->delete();

            return redirect()->back()->with('success', 'File deleted successfully.');
        } catch (\Exception $e) {
            Log::error('File deletion failed: '.$e->getMessage());

            return redirect()->back()->with('error', 'Failed to delete file: '.$e->getMessage());
        }
    }

    /**
     * Generate share link for project (creates a general invitation)
     */
    public function shareProject(Request $request, Project $project)
    {
        if ((int) $project->created_by !== (int) Auth::id()) {
            abort(403, 'Only the project owner can invite members.');
        }

        $request->validate([
            'email' => 'required|email',
            'role' => 'required|in:admin,member',
            'message' => 'nullable|string|max:500',
        ]);

        $email = $request->email;
        $role = $request->role;
        $message = $request->message;

        // Check if user is already in project
        $user = User::where('email', $email)->first();
        if ($user && $project->users()->where('user_id', $user->id)->exists()) {
            return back()->with('error', 'User is already a member of this project.');
        }

        // Check if invitation already exists and is still valid
        $existingInvitation = ProjectInvitation::where('project_id', $project->id)
            ->where('email', $email)
            ->where('is_used', false)
            ->where('expires_at', '>', now())
            ->first();

        if ($existingInvitation) {
            $inviteUrl = route('projects.join', [
                'project' => $project->id,
                'token' => $existingInvitation->token,
            ]);

            return back()->with('info', "An invitation already exists. Share this link: {$inviteUrl}");
        }

        // Create invitation
        $invitation = ProjectInvitation::createInvitation($project->id, $email, null, $role, $message);

        // Generate invitation URL
        $inviteUrl = route('projects.join', [
            'project' => $project->id,
            'token' => $invitation->token,
        ]);

        // Send email invitation
        try {
            Mail::to($email)->send(new ProjectInvitationMail($project, $invitation, $message));

            return back()->with('success', "Invitation sent successfully. Share link: {$inviteUrl}");
        } catch (\Exception $e) {
            Log::error('Failed to send project invitation email: '.$e->getMessage());

            return back()->with('info', "Invitation created. Share this link: {$inviteUrl}");
        }
    }

    /**
     * Get messages for a project
     */
    public function getMessages(Project $project)
    {
        // Verify user is a member of the project
        $isMember = $project->users()->where('users.id', Auth::id())->exists()
            || $project->created_by === Auth::id();

        if (! $isMember) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $messages = $project->messages()
            ->with(['user:id,name,image', 'replyTo.user:id,name', 'reactions.user:id,name'])
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($message) {
                // Group reactions by emoji
                $reactionsGrouped = $message->reactions && $message->reactions->count() > 0
                    ? $message->reactions->groupBy('reaction')->map(function ($reactions, $reaction) {
                        return [
                            'reaction' => $reaction,
                            'count' => $reactions->count(),
                            'users' => $reactions->pluck('user.name')->toArray(),
                        ];
                    })->values()
                    : [];

                return [
                    'id' => $message->id,
                    'content' => $message->content,
                    'timestamp' => $message->created_at->toISOString(),
                    'updated_at' => $message->updated_at->toISOString(),
                    'reply_to' => $message->reply_to ? [
                        'id' => $message->replyTo->id,
                        'content' => $message->replyTo->content,
                        'user' => [
                            'id' => $message->replyTo->user->id,
                            'name' => $message->replyTo->user->name,
                        ],
                    ] : null,
                    'attachment_path' => $message->attachment_path ? asset('storage/'.$message->attachment_path) : null,
                    'attachment_type' => $message->attachment_type,
                    'attachment_name' => $message->attachment_name,
                    'audio_duration' => $message->audio_duration,
                    'reactions' => $reactionsGrouped,
                    'user' => [
                        'id' => $message->user->id,
                        'name' => $message->user->name,
                        'avatar' => $message->user->image ? asset('storage/'.$message->user->image) : null,
                    ],
                ];
            });

        return response()->json(['messages' => $messages]);
    }

    /**
     * Get unread message count for a project
     */
    public function getUnreadMessageCount(Project $project)
    {
        try {
            $userId = Auth::id();

            if (! Schema::hasTable('project_message_notifications')) {
                return response()->json(['count' => 0]);
            }

            $count = ProjectMessageNotification::where('project_id', $project->id)
                ->where('notified_user_id', $userId)
                ->whereNull('read_at')
                ->count();

            return response()->json(['count' => $count]);
        } catch (\Exception $e) {
            Log::error('Failed to get unread message count: '.$e->getMessage());

            return response()->json(['count' => 0]);
        }
    }

    /**
     * Send a message to project chat
     */
    public function sendMessage(Request $request, Project $project)
    {
        // Verify user is a member of the project
        $isMember = $project->users()->where('users.id', Auth::id())->exists()
            || $project->created_by === Auth::id();

        if (! $isMember) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'content' => 'nullable|string|max:5000',
            'reply_to' => 'nullable|exists:project_messages,id',
            'audio' => 'nullable|file|mimes:webm,mp3,mp4,m4a,aac,ogg,wav|max:10240', // 10MB max
            'audio_duration' => 'nullable|integer|min:0|max:600', // Max 10 minutes
        ]);

        // Ensure either content or audio is provided
        $hasContent = $request->has('content') && trim($request->input('content', '')) !== '';
        $hasAudio = $request->hasFile('audio');

        if (! $hasContent && ! $hasAudio) {
            return response()->json(['error' => 'Either content or audio must be provided'], 422);
        }

        $attachmentPath = null;
        $attachmentType = null;
        $attachmentName = null;
        $audioDuration = null;

        // Handle audio file upload
        if ($request->hasFile('audio')) {
            $audioFile = $request->file('audio');

            // Validate file was uploaded successfully
            if (! $audioFile->isValid()) {
                return response()->json(['error' => 'Invalid audio file'], 422);
            }

            $extension = $audioFile->getClientOriginalExtension() ?: 'webm';
            $attachmentName = 'voice_message_'.time().'_'.uniqid().'.'.$extension;
            $attachmentPath = $audioFile->storeAs('project_messages/audio', $attachmentName, 'public');

            if (! $attachmentPath) {
                \Log::error('Failed to store audio file', [
                    'original_name' => $audioFile->getClientOriginalName(),
                    'size' => $audioFile->getSize(),
                ]);

                return response()->json(['error' => 'Failed to store audio file'], 500);
            }

            $attachmentType = 'audio';
            $audioDuration = (int) $request->input('audio_duration', 0);

            \Log::info('Audio file stored successfully', [
                'path' => $attachmentPath,
                'size' => $audioFile->getSize(),
                'duration' => $audioDuration,
            ]);
        }

        $message = ProjectMessage::create([
            'project_id' => $project->id,
            'user_id' => Auth::id(),
            'content' => $request->content ?? '',
            'reply_to' => $request->reply_to ?? null,
            'attachment_path' => $attachmentPath,
            'attachment_type' => $attachmentType,
            'attachment_name' => $attachmentName,
            'audio_duration' => $audioDuration,
        ]);

        $message->load(['user:id,name,image', 'replyTo.user:id,name']);

        // Get all project members (creator + team members) excluding the sender
        $projectMembers = collect();

        // Add creator if not the sender
        if ($project->created_by && $project->created_by != Auth::id()) {
            $creator = User::find($project->created_by);
            if ($creator) {
                $projectMembers->push($creator);
            }
        }

        // Add team members excluding the sender
        $teamMembers = $project->users()->where('users.id', '!=', Auth::id())->get();
        $projectMembers = $projectMembers->merge($teamMembers)->unique('id');

        // Create notifications for all project members
        $sender = Auth::user();
        $messageText = $message->content ?: ($message->attachment_type === 'audio' ? 'sent a voice message' : 'sent an attachment');
        $notificationMessage = "{$sender->name} sent a message in project \"{$project->name}\": {$messageText}";
        $path = "/admin/projects/{$project->id}";

        foreach ($projectMembers as $member) {
            try {
                $notification = ProjectMessageNotification::create([
                    'project_id' => $project->id,
                    'message_id' => $message->id,
                    'notified_user_id' => $member->id,
                    'sender_user_id' => Auth::id(),
                    'message_notification' => $notificationMessage,
                    'path' => $path,
                ]);

                // Send Expo push notification
                try {
                    $memberUser = \App\Models\User::find($member->id);
                    if ($memberUser) {
                        $memberUser->refresh();
                        if ($memberUser->expo_push_token) {
                            $pushService = app(\App\Services\ExpoPushNotificationService::class);
                            \Illuminate\Support\Facades\Log::info('Sending push notification for project message', [
                                'member_id' => $member->id,
                                'project_id' => $project->id,
                            ]);
                            $success = $pushService->sendToUser($memberUser, 'New Project Message', $notificationMessage, [
                                'type' => 'project_message',
                                'notification_id' => $notification->id,
                                'project_id' => $project->id,
                                'message_id' => $message->id,
                                'sender_user_id' => Auth::id(),
                            ]);
                            if (! $success) {
                                \Illuminate\Support\Facades\Log::warning('Push notification send returned false for project message');
                            }
                        }
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Failed to send Expo push notification for project message', [
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
                        $channel = $ably->channels->get("notifications:{$member->id}");

                        $channel->publish('new_notification', [
                            'id' => 'project-message-'.$notification->id,
                            'type' => 'project_message',
                            'sender_name' => $sender->name,
                            'sender_image' => $sender->image,
                            'message' => $notificationMessage,
                            'link' => $path,
                            'icon_type' => 'message-square',
                            'created_at' => $notification->created_at->format('Y-m-d H:i:s'),
                            'read_at' => null,
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to broadcast project message notification via Ably', [
                        'error' => $e->getMessage(),
                        'notification_id' => $notification->id ?? null,
                        'user_id' => $member->id,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Failed to create project message notification', [
                    'error' => $e->getMessage(),
                    'project_id' => $project->id,
                    'message_id' => $message->id,
                    'user_id' => $member->id,
                ]);
            }
        }

        // Broadcast message via Ably
        try {
            $ablyKey = config('services.ably.key');
            if ($ablyKey) {
                $ably = new AblyRest($ablyKey);
                $channel = $ably->channels->get("project:{$project->id}");

                $broadcastData = [
                    'id' => $message->id,
                    'content' => $message->content,
                    'timestamp' => $message->created_at->toISOString(),
                    'reply_to' => $message->reply_to ? [
                        'id' => $message->replyTo->id,
                        'content' => $message->replyTo->content,
                        'user' => [
                            'id' => $message->replyTo->user->id,
                            'name' => $message->replyTo->user->name,
                        ],
                    ] : null,
                    'attachment_path' => $message->attachment_path ? asset('storage/'.$message->attachment_path) : null,
                    'attachment_type' => $message->attachment_type,
                    'attachment_name' => $message->attachment_name,
                    'audio_duration' => $message->audio_duration,
                    'reactions' => [],
                    'user' => [
                        'id' => $message->user->id,
                        'name' => $message->user->name,
                        'avatar' => $message->user->image ? asset('storage/'.$message->user->image) : null,
                    ],
                ];

                $channel->publish('new-message', $broadcastData);
            }
        } catch (\Exception $e) {
            Log::error('Failed to broadcast project message via Ably: '.$e->getMessage());
        }

        return response()->json([
            'message' => [
                'id' => $message->id,
                'content' => $message->content,
                'timestamp' => $message->created_at->toISOString(),
                'reply_to' => $message->reply_to ? [
                    'id' => $message->replyTo->id,
                    'content' => $message->replyTo->content,
                    'user' => [
                        'id' => $message->replyTo->user->id,
                        'name' => $message->replyTo->user->name,
                    ],
                ] : null,
                'attachment_path' => $message->attachment_path ? asset('storage/'.$message->attachment_path) : null,
                'attachment_type' => $message->attachment_type,
                'attachment_name' => $message->attachment_name,
                'audio_duration' => $message->audio_duration,
                'reactions' => [],
                'user' => [
                    'id' => $message->user->id,
                    'name' => $message->user->name,
                    'avatar' => $message->user->image ? asset('storage/'.$message->user->image) : null,
                ],
            ],
        ]);
    }

    /**
     * Add or remove a reaction to a message
     */
    public function toggleReaction(Request $request, Project $project, $messageId)
    {
        // Verify user is a member of the project
        $isMember = $project->users()->where('users.id', Auth::id())->exists()
            || $project->created_by === Auth::id();

        if (! $isMember) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'reaction' => 'required|string|max:10',
        ]);

        $message = ProjectMessage::where('id', $messageId)
            ->where('project_id', $project->id)
            ->firstOrFail();

        // Check if reaction already exists
        $existingReaction = ProjectMessageReaction::where('message_id', $messageId)
            ->where('user_id', Auth::id())
            ->where('reaction', $request->reaction)
            ->first();

        if ($existingReaction) {
            // Remove reaction
            $existingReaction->delete();
            $action = 'removed';
        } else {
            // Remove any other reaction from this user on this message (one reaction per user per message)
            ProjectMessageReaction::where('message_id', $messageId)
                ->where('user_id', Auth::id())
                ->delete();

            // Add new reaction
            ProjectMessageReaction::create([
                'message_id' => $messageId,
                'user_id' => Auth::id(),
                'reaction' => $request->reaction,
            ]);
            $action = 'added';
        }

        // Reload message with reactions
        $message->load(['reactions.user:id,name']);
        $reactionsGrouped = $message->reactions->groupBy('reaction')->map(function ($reactions, $reaction) {
            return [
                'reaction' => $reaction,
                'count' => $reactions->count(),
                'users' => $reactions->pluck('user.name')->toArray(),
            ];
        })->values()->toArray();

        // Broadcast reaction update via Ably to all users in the project
        try {
            $ablyKey = config('services.ably.key');
            if ($ablyKey) {
                $ably = new AblyRest($ablyKey);
                $channelName = "project:{$project->id}";
                $channel = $ably->channels->get($channelName);

                $broadcastData = [
                    'message_id' => (int) $messageId,
                    'reactions' => $reactionsGrouped,
                    'action' => $action,
                    'reaction' => $request->reaction,
                    'user_id' => Auth::id(),
                ];

                $channel->publish('message-reaction-updated', $broadcastData);

                Log::info('✅ Broadcasted reaction update via Ably', [
                    'channel' => $channelName,
                    'message_id' => $messageId,
                    'action' => $action,
                ]);
            } else {
                Log::warning('Ably key not configured - reaction update not broadcasted');
            }
        } catch (\Exception $e) {
            Log::error('Failed to broadcast reaction update via Ably: '.$e->getMessage());
        }

        return response()->json([
            'success' => true,
            'reactions' => $reactionsGrouped,
        ]);
    }

    /**
     * Update a message
     */
    public function updateMessage(Request $request, Project $project, $messageId)
    {
        // Verify user is a member of the project
        $isMember = $project->users()->where('users.id', Auth::id())->exists()
            || $project->created_by === Auth::id();

        if (! $isMember) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'content' => 'required|string|max:5000',
        ]);

        $message = ProjectMessage::where('id', $messageId)
            ->where('project_id', $project->id)
            ->where('user_id', Auth::id()) // Only allow users to edit their own messages
            ->firstOrFail();

        $message->update([
            'content' => $request->content,
        ]);

        $message->load(['user:id,name,image', 'replyTo.user:id,name']);

        // Reload message with reactions
        $message->load(['reactions.user:id,name']);
        $reactionsGrouped = $message->reactions->groupBy('reaction')->map(function ($reactions, $reaction) {
            return [
                'reaction' => $reaction,
                'count' => $reactions->count(),
                'users' => $reactions->pluck('user.name')->toArray(),
            ];
        })->values()->toArray();

        // Broadcast message update via Ably
        try {
            $ablyKey = config('services.ably.key');
            if ($ablyKey) {
                $ably = new AblyRest($ablyKey);
                $channel = $ably->channels->get("project:{$project->id}");

                $broadcastData = [
                    'id' => $message->id,
                    'content' => $message->content,
                    'timestamp' => $message->created_at->toISOString(),
                    'updated_at' => $message->updated_at->toISOString(),
                    'reply_to' => $message->reply_to ? [
                        'id' => $message->replyTo->id,
                        'content' => $message->replyTo->content,
                        'user' => [
                            'id' => $message->replyTo->user->id,
                            'name' => $message->replyTo->user->name,
                        ],
                    ] : null,
                    'attachment_path' => $message->attachment_path ? asset('storage/'.$message->attachment_path) : null,
                    'attachment_type' => $message->attachment_type,
                    'attachment_name' => $message->attachment_name,
                    'audio_duration' => $message->audio_duration,
                    'reactions' => $reactionsGrouped,
                    'user' => [
                        'id' => $message->user->id,
                        'name' => $message->user->name,
                        'avatar' => $message->user->image ? asset('storage/'.$message->user->image) : null,
                    ],
                ];

                $channel->publish('message-updated', $broadcastData);
            }
        } catch (\Exception $e) {
            Log::error('Failed to broadcast message update via Ably: '.$e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => [
                'id' => $message->id,
                'content' => $message->content,
                'timestamp' => $message->created_at->toISOString(),
                'updated_at' => $message->updated_at->toISOString(),
                'reply_to' => $message->reply_to ? [
                    'id' => $message->replyTo->id,
                    'content' => $message->replyTo->content,
                    'user' => [
                        'id' => $message->replyTo->user->id,
                        'name' => $message->replyTo->user->name,
                    ],
                ] : null,
                'reactions' => $reactionsGrouped,
                'user' => [
                    'id' => $message->user->id,
                    'name' => $message->user->name,
                    'avatar' => $message->user->image ? asset('storage/'.$message->user->image) : null,
                ],
            ],
        ]);
    }

    /**
     * Delete a message
     */
    public function deleteMessage(Project $project, $messageId)
    {
        // Verify user is a member of the project
        $isMember = $project->users()->where('users.id', Auth::id())->exists()
            || $project->created_by === Auth::id();

        if (! $isMember) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $message = ProjectMessage::where('id', $messageId)
            ->where('project_id', $project->id)
            ->where('user_id', Auth::id()) // Only allow users to delete their own messages
            ->firstOrFail();

        $message->delete();

        // Broadcast message deletion via Ably to all users in the project
        try {
            $ablyKey = config('services.ably.key');
            if ($ablyKey) {
                $ably = new AblyRest($ablyKey);
                $channelName = "project:{$project->id}";
                $channel = $ably->channels->get($channelName);

                $broadcastData = [
                    'message_id' => $messageId,
                    'project_id' => $project->id,
                    'deleted_by' => Auth::id(),
                ];

                $channel->publish('message-deleted', $broadcastData);

                Log::info('✅ Broadcasted message deletion via Ably', [
                    'channel' => $channelName,
                    'message_id' => $messageId,
                    'deleted_by' => Auth::id(),
                ]);
            } else {
                Log::warning('Ably key not configured - message deletion not broadcasted');
            }
        } catch (\Exception $e) {
            Log::error('Failed to broadcast message deletion via Ably: '.$e->getMessage());
        }

        return response()->json([
            'success' => true,
        ]);
    }
}
