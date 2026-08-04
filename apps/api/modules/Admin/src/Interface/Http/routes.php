<?php

declare(strict_types=1);

use EruoFood\Admin\Interface\Http\Controller\AuditController;
use EruoFood\Admin\Interface\Http\Controller\CmsController;
use EruoFood\Admin\Interface\Http\Controller\ConfigController;
use EruoFood\Admin\Interface\Http\Controller\OperationsController;
use EruoFood\Admin\Interface\Http\Controller\RbacController;
use EruoFood\Admin\Interface\Http\Controller\SupportController;
use EruoFood\Admin\Interface\Http\Controller\UserAdminController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Admin routes — Platform Administration, CMS & Operations (mounted under
| /api/v1 by the module provider). Everything lives under "admin" so it never
| collides with other contexts. `auth.jwt` proves identity; each action then
| asserts the specific fine-grained permission in the controller (RBAC).
|------------------------------------------------------------------------------
*/

Route::prefix('v1/admin')->middleware('auth.jwt')->group(function (): void {
    // ---- Role & Permission Management ----
    Route::get('permissions', [RbacController::class, 'catalogue']);
    Route::get('accounts', [RbacController::class, 'index']);
    Route::get('accounts/{userId}', [RbacController::class, 'show']);
    Route::put('accounts/{userId}/roles', [RbacController::class, 'setRoles']);
    Route::post('accounts/{userId}/permissions', [RbacController::class, 'grantPermission']);
    Route::delete('accounts/{userId}/permissions', [RbacController::class, 'revokePermission']);
    Route::post('accounts/{userId}/suspend', [RbacController::class, 'suspend']);
    Route::post('accounts/{userId}/activate', [RbacController::class, 'activate']);
    Route::post('impersonations', [RbacController::class, 'startImpersonation']);
    Route::post('impersonations/{id}/end', [RbacController::class, 'endImpersonation']);

    // ---- Content Management (CMS) ----
    Route::get('cms/pages', [CmsController::class, 'listPages']);
    Route::post('cms/pages', [CmsController::class, 'createPage']);
    Route::get('cms/pages/{id}', [CmsController::class, 'showPage']);
    Route::put('cms/pages/{id}', [CmsController::class, 'updatePage']);
    Route::post('cms/pages/{id}/publish', [CmsController::class, 'publishPage']);
    Route::post('cms/pages/{id}/unpublish', [CmsController::class, 'unpublishPage']);
    Route::post('cms/pages/{id}/archive', [CmsController::class, 'archivePage']);
    Route::get('cms/banners', [CmsController::class, 'listBanners']);
    Route::post('cms/banners', [CmsController::class, 'createBanner']);
    Route::put('cms/banners/{id}/active', [CmsController::class, 'setBannerActive']);
    Route::delete('cms/banners/{id}', [CmsController::class, 'deleteBanner']);
    Route::get('cms/faqs', [CmsController::class, 'listFaqs']);
    Route::post('cms/faqs', [CmsController::class, 'createFaq']);
    Route::put('cms/faqs/{id}', [CmsController::class, 'updateFaq']);
    Route::delete('cms/faqs/{id}', [CmsController::class, 'deleteFaq']);

    // ---- System Configuration ----
    Route::get('settings', [ConfigController::class, 'listSettings']);
    Route::put('settings/{key}', [ConfigController::class, 'updateSetting']);
    Route::get('flags', [ConfigController::class, 'listFlags']);
    Route::put('flags/{key}', [ConfigController::class, 'setFlag']);
    Route::get('maintenance', [ConfigController::class, 'maintenance']);
    Route::put('maintenance', [ConfigController::class, 'setMaintenance']);

    // ---- User Administration ----
    Route::get('users', [UserAdminController::class, 'index']);
    Route::get('users/{userId}', [UserAdminController::class, 'show']);
    Route::post('users/{userId}/suspend', [UserAdminController::class, 'suspend']);
    Route::post('users/{userId}/reinstate', [UserAdminController::class, 'reinstate']);

    // ---- Restaurant & Vendor Operations ----
    Route::get('operations/approvals', [OperationsController::class, 'listApprovals']);
    Route::post('operations/approvals', [OperationsController::class, 'submitApproval']);
    Route::get('operations/approvals/{id}', [OperationsController::class, 'showApproval']);
    Route::post('operations/approvals/{id}/approve', [OperationsController::class, 'approve']);
    Route::post('operations/approvals/{id}/reject', [OperationsController::class, 'reject']);
    Route::get('operations/vendors', [OperationsController::class, 'vendors']);

    // ---- Support Centre ----
    Route::get('support/tickets', [SupportController::class, 'queue']);
    Route::post('support/tickets', [SupportController::class, 'open']);
    Route::get('support/tickets/{id}', [SupportController::class, 'show']);
    Route::post('support/tickets/{id}/assign', [SupportController::class, 'assign']);
    Route::post('support/tickets/{id}/reply', [SupportController::class, 'reply']);
    Route::post('support/tickets/{id}/notes', [SupportController::class, 'note']);
    Route::post('support/tickets/{id}/escalate', [SupportController::class, 'escalate']);
    Route::post('support/tickets/{id}/resolve', [SupportController::class, 'resolve']);
    Route::post('support/tickets/{id}/close', [SupportController::class, 'close']);

    // ---- Audit & Compliance ----
    Route::get('audit', [AuditController::class, 'index']);
});
