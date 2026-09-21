<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ChatController;

Route::middleware(['auth'])->prefix('chat')->name('chat.')->group(function () {
    Route::get('/', [ChatController::class, 'index'])->name('index');
    Route::get('/following-ids', [ChatController::class, 'getFollowingIds'])->name('following-ids');
    Route::get('/following-users', [ChatController::class, 'getFollowingUsers'])->name('following-users');
    Route::get('/unread-count', [ChatController::class, 'getUnreadCount'])->name('unread-count');
    Route::post('/groups', [ChatController::class, 'createGroup'])->name('groups.store');
    Route::get('/groups/{conversationId}', [ChatController::class, 'showGroup'])->name('groups.show');
    Route::put('/groups/{conversationId}', [ChatController::class, 'updateGroup'])->name('groups.update');
    Route::post('/groups/{conversationId}/members', [ChatController::class, 'addGroupMembers'])->name('groups.members.add');
    Route::delete('/groups/{conversationId}/members/{userId}', [ChatController::class, 'removeGroupMember'])->name('groups.members.remove');
    Route::get('/conversation/{userId}', [ChatController::class, 'getOrCreateConversation'])->name('conversation');
    Route::get('/conversation/{conversationId}/messages', [ChatController::class, 'getMessages'])->name('messages');
    Route::post('/conversation/{conversationId}/send', [ChatController::class, 'sendMessage'])->name('send');
    Route::post('/conversation/{conversationId}/read', [ChatController::class, 'markAsRead'])->name('mark-read');
    Route::put('/message/{messageId}', [ChatController::class, 'updateMessage'])->name('message.update');
    Route::post('/message/{messageId}/react', [ChatController::class, 'toggleReaction'])->name('message.react');
    Route::delete('/message/{messageId}', [ChatController::class, 'deleteMessage'])->name('message.delete');
    Route::get('/message/{messageId}/attachment', [ChatController::class, 'downloadAttachment'])->name('message.attachment');
    Route::delete('/conversation/{conversationId}', [ChatController::class, 'deleteConversation'])->name('conversation.delete');
    Route::get('/user/{userId}/posts', [ChatController::class, 'getUserPosts'])->name('user.posts');
    Route::get('/ably-token', [ChatController::class, 'getAblyToken'])->name('ably-token');
});
