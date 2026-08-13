<?php

declare(strict_types=1);

use EruoFood\Notifications\Interface\Http\Controller\Admin\BroadcastAdminController;
use EruoFood\Notifications\Interface\Http\Controller\Admin\NotificationsAdminController;
use EruoFood\Notifications\Interface\Http\Controller\Admin\TemplateAdminController;
use EruoFood\Notifications\Interface\Http\Controller\MessagingController;
use EruoFood\Notifications\Interface\Http\Controller\NotificationController;
use EruoFood\Notifications\Interface\Http\Controller\PreferenceController;
use EruoFood\Notifications\Interface\Http\Controller\PresenceController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Notifications routes — Notifications, Messaging & Real-Time Communication
| (mounted under /api/v1 by the module provider). All paths are under
| "notifications" so they never collide with other contexts.
|------------------------------------------------------------------------------
*/

/*
 * Unsubscribe is deliberately outside the authenticated group: it is clicked
 * from an email client that has no session, and the token in the URL is what
 * stands in for authentication. Throttled, because a public endpoint that
 * mutates preferences is worth rate-limiting even when it cannot leak anything.
 */
Route::post('v1/notifications/unsubscribe/{token}', [PreferenceController::class, 'unsubscribe'])
    ->middleware('throttle:30,1');

Route::prefix('v1/notifications')->middleware('auth.jwt')->group(function (): void {
    // ---- Notification centre ----
    Route::get('/', [NotificationController::class, 'index']);
    Route::get('unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('{id}/read', [NotificationController::class, 'markRead']);
    Route::post('read-all', [NotificationController::class, 'markAllRead']);

    // ---- Preferences ----
    Route::get('preferences', [PreferenceController::class, 'show']);
    Route::put('preferences', [PreferenceController::class, 'update']);
    Route::put('preferences/channels', [PreferenceController::class, 'setChannels']);
    Route::put('preferences/quiet-hours', [PreferenceController::class, 'setQuietHours']);
    Route::put('preferences/marketing', [PreferenceController::class, 'setMarketing']);

    // ---- Messaging (chat) ----
    Route::get('conversations', [MessagingController::class, 'index']);
    Route::post('conversations', [MessagingController::class, 'store']);
    Route::get('conversations/{id}/messages', [MessagingController::class, 'messages']);
    Route::post('conversations/{id}/messages', [MessagingController::class, 'send']);
    Route::post('conversations/{id}/typing', [MessagingController::class, 'typing']);
    Route::post('messages/{messageId}/read', [MessagingController::class, 'markRead']);

    // ---- Real-time presence ----
    Route::post('presence/heartbeat', [PresenceController::class, 'heartbeat']);
    Route::get('presence', [PresenceController::class, 'show']);

    // ---- Admin (RBAC) ----
    Route::middleware('role:admin')->prefix('admin')->group(function (): void {
        Route::get('broadcasts', [BroadcastAdminController::class, 'index']);
        Route::post('broadcasts', [BroadcastAdminController::class, 'store']);
        Route::post('broadcasts/{id}/send', [BroadcastAdminController::class, 'send']);
        Route::get('templates', [TemplateAdminController::class, 'index']);
        Route::post('templates', [TemplateAdminController::class, 'upsert']);
        Route::get('report', [NotificationsAdminController::class, 'report']);
    });
});
