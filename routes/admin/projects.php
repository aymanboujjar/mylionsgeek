<?php

use App\Http\Controllers\Admin\ProjectController;
use App\Http\Controllers\Admin\TaskController;
use App\Http\Controllers\Admin\TaskCommentController;
use App\Http\Controllers\Admin\AttachmentController;
use App\Http\Controllers\Admin\ProjectNoteController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

// Public join route (no auth required)
Route::get('projects/{project}/join/{token}', [ProjectController::class, 'join'])->name('projects.join');
Route::post('projects/{project}/repository-events/github', [ProjectController::class, 'recordGitHubRepositoryEvent'])
    ->name('projects.repository-events.github')
    ->withoutMiddleware([VerifyCsrfToken::class]);

Route::middleware(['auth', 'role:admin,super_admin,moderateur,coach,pro'])->prefix('admin')->name('admin.')->group(function () {
    Route::post('projects/attachments', [ProjectController::class, 'uploadAttachment'])->name('projects.upload-attachment');
    Route::post('projects/share/{project}', [ProjectController::class, 'shareProject'])->name('projects.share');
    Route::delete('projects/attachments/{attachment}', [ProjectController::class, 'deleteAttachment'])->name('projects.delete-attachment');
    Route::post('projects/invite', [ProjectController::class, 'invite'])->name('projects.invite');
    Route::post('projects/{project}/invite', [ProjectController::class, 'inviteUser'])->name('projects.invite-user');
    Route::get('projects-statistics', [ProjectController::class, 'statistics'])->name('projects.statistics');

    // Project resource routes
    Route::resource('projects', ProjectController::class);
    Route::post('projects/{project}', [ProjectController::class, 'update'])->name('projects.update-post');
    Route::delete('projects/{project}/users/{user}', [ProjectController::class, 'removeUser'])->name('projects.remove-user');
    Route::put('projects/{project}/users/{user}', [ProjectController::class, 'updateRole'])->name('projects.update-role');

    // Task routes
    Route::resource('tasks', TaskController::class)->except(['index', 'show', 'create', 'edit']);
    Route::patch('tasks/{task}/status', [TaskController::class, 'updateStatus'])->name('tasks.update-status');
    Route::patch('tasks/{task}/title', [TaskController::class, 'updateTitle'])->name('tasks.update-title');
    Route::patch('tasks/{task}/description', [TaskController::class, 'updateDescription'])->name('tasks.update-description');
    Route::patch('tasks/{task}/priority', [TaskController::class, 'updatePriority'])->name('tasks.update-priority');
    Route::patch('tasks/{task}/assigned-to', [TaskController::class, 'updateAssignedTo'])->name('tasks.update-assigned-to');
    Route::post('tasks/{task}/subtasks', [TaskController::class, 'addSubtask'])->name('tasks.add-subtask');
    Route::put('tasks/{task}/subtasks', [TaskController::class, 'updateSubtask'])->name('tasks.update-subtask');
    Route::delete('tasks/{task}/subtasks', [TaskController::class, 'deleteSubtask'])->name('tasks.delete-subtask');
    Route::post('tasks/{task}/comments', [TaskController::class, 'addComment'])->name('tasks.add-comment');
    Route::put('tasks/{task}/comments/{comment}', [TaskController::class, 'updateComment'])->name('tasks.update-comment');
    Route::delete('tasks/{task}/comments/{comment}', [TaskController::class, 'deleteComment'])->name('tasks.delete-comment');
    Route::post('tasks/{task}/attachments', [TaskController::class, 'addAttachment'])->name('tasks.add-attachment');
    Route::get('tasks/{task}/attachments/{attachmentId}/download', [TaskController::class, 'downloadAttachment'])->name('tasks.download-attachment');
    Route::delete('tasks/{task}/attachments', [TaskController::class, 'removeAttachment'])->name('tasks.remove-attachment');
    Route::post('tasks/{task}/pin', [TaskController::class, 'togglePin'])->name('tasks.toggle-pin');

    // Project Notes routes
    Route::resource('project-notes', ProjectNoteController::class);
    Route::post('project-notes/{projectNote}/pin', [ProjectNoteController::class, 'togglePin'])->name('project-notes.toggle-pin');

    // Task comment routes
    Route::resource('task-comments', TaskCommentController::class)->only(['store', 'destroy']);

    // Attachment routes
    Route::resource('attachments', AttachmentController::class)->only(['store', 'destroy']);
    Route::get('attachments/{attachment}/download', [AttachmentController::class, 'download'])->name('attachments.download');

    // Project chat routes
    Route::get('projects/{project}/messages', [ProjectController::class, 'getMessages'])->name('projects.messages');
    Route::get('projects/{project}/messages/unread-count', [ProjectController::class, 'getUnreadMessageCount'])->name('projects.messages.unread-count');
    Route::post('projects/{project}/messages', [ProjectController::class, 'sendMessage'])->name('projects.send-message');
    Route::put('projects/{project}/messages/{messageId}', [ProjectController::class, 'updateMessage'])->name('projects.messages.update');
    Route::delete('projects/{project}/messages/{messageId}', [ProjectController::class, 'deleteMessage'])->name('projects.messages.delete');
    Route::post('projects/{project}/messages/{messageId}/reactions', [ProjectController::class, 'toggleReaction'])->name('projects.messages.reactions');
});
