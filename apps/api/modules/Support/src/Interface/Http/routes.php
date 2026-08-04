<?php

declare(strict_types=1);

use EruoFood\Support\Interface\Http\Controller\AgentTicketController;
use EruoFood\Support\Interface\Http\Controller\CrmController;
use EruoFood\Support\Interface\Http\Controller\KnowledgeBaseController;
use EruoFood\Support\Interface\Http\Controller\SupportAdminController;
use EruoFood\Support\Interface\Http\Controller\TicketController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Support routes — Customer Support, Helpdesk & CRM (mounted under /api/v1 by
| the module provider). The knowledge base is public to browse; raising and
| tracking tickets needs authentication; the agent workspace, CRM and admin
| dashboards require a support/admin role (enforced in the controllers).
| Everything lives under "support" so it never collides with other contexts.
| No business module manages tickets — all support flows through here.
|------------------------------------------------------------------------------
*/

// ---- Public knowledge base ----
Route::prefix('v1/support/kb')->group(function (): void {
    Route::get('articles', [KnowledgeBaseController::class, 'index']);
    Route::get('categories', [KnowledgeBaseController::class, 'categories']);
    Route::get('articles/{slug}', [KnowledgeBaseController::class, 'show']);
    Route::post('articles/{slug}/vote', [KnowledgeBaseController::class, 'vote']);
});

Route::prefix('v1/support')->middleware('auth.jwt')->group(function (): void {
    // ---- Customer portal ----
    Route::get('tickets', [TicketController::class, 'index']);
    Route::post('tickets', [TicketController::class, 'store']);
    Route::get('tickets/{id}', [TicketController::class, 'show']);
    Route::post('tickets/{id}/reply', [TicketController::class, 'reply']);
    Route::post('tickets/{id}/csat', [TicketController::class, 'csat']);

    // ---- Agent workspace ----
    Route::get('agent/tickets', [AgentTicketController::class, 'index']);
    Route::get('agent/tickets/{id}', [AgentTicketController::class, 'show']);
    Route::post('agent/tickets/{id}/assign', [AgentTicketController::class, 'assign']);
    Route::post('agent/tickets/{id}/reply', [AgentTicketController::class, 'reply']);
    Route::post('agent/tickets/{id}/notes', [AgentTicketController::class, 'note']);
    Route::put('agent/tickets/{id}/status', [AgentTicketController::class, 'status']);
    Route::put('agent/tickets/{id}/priority', [AgentTicketController::class, 'priority']);
    Route::post('agent/tickets/{id}/escalate', [AgentTicketController::class, 'escalate']);
    Route::post('agent/tickets/{id}/merge', [AgentTicketController::class, 'merge']);
    Route::post('agent/tickets/{id}/tags', [AgentTicketController::class, 'tag']);
    Route::get('agent/tickets/{id}/ai/summary', [AgentTicketController::class, 'summarise']);
    Route::get('agent/tickets/{id}/ai/suggest', [AgentTicketController::class, 'suggestReply']);

    // ---- Knowledge base authoring ----
    Route::get('kb/manage/articles', [KnowledgeBaseController::class, 'adminIndex']);
    Route::post('kb/manage/articles', [KnowledgeBaseController::class, 'store']);
    Route::put('kb/manage/articles/{id}', [KnowledgeBaseController::class, 'update']);
    Route::post('kb/manage/articles/{id}/publish', [KnowledgeBaseController::class, 'publish']);
    Route::post('kb/manage/articles/{id}/archive', [KnowledgeBaseController::class, 'archive']);
    Route::delete('kb/manage/articles/{id}', [KnowledgeBaseController::class, 'destroy']);

    // ---- CRM ----
    Route::get('crm/customers', [CrmController::class, 'index']);
    Route::get('crm/customers/{userId}', [CrmController::class, 'show']);
    Route::get('crm/customers/{userId}/timeline', [CrmController::class, 'timeline']);
    Route::post('crm/customers/{userId}/tags', [CrmController::class, 'tag']);
    Route::put('crm/customers/{userId}/notes', [CrmController::class, 'notes']);
    Route::post('crm/customers/{userId}/insight', [CrmController::class, 'insight']);
    Route::get('crm/segments', [CrmController::class, 'segments']);

    // ---- Admin dashboards + automation ----
    Route::get('admin/dashboard', [SupportAdminController::class, 'dashboard']);
    Route::get('admin/sla-report', [SupportAdminController::class, 'slaReport']);
    Route::get('admin/agents', [SupportAdminController::class, 'agentPerformance']);
    Route::get('admin/csat', [SupportAdminController::class, 'csatReport']);
    Route::get('admin/rules', [SupportAdminController::class, 'listRules']);
    Route::post('admin/rules', [SupportAdminController::class, 'createRule']);
    Route::put('admin/rules/{id}', [SupportAdminController::class, 'toggleRule']);
    Route::delete('admin/rules/{id}', [SupportAdminController::class, 'deleteRule']);
});
