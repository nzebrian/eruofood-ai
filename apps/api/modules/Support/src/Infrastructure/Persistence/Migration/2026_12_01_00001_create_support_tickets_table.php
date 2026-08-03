<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('ref')->unique();
            $table->uuid('requester_id')->index();
            $table->string('subject');
            $table->string('category')->index();
            $table->string('channel')->default('web');
            $table->string('status')->default('new')->index();
            $table->string('priority')->default('normal');
            $table->unsignedTinyInteger('priority_weight')->default(1)->index();
            $table->uuid('assignee_id')->nullable()->index();
            $table->uuid('sla_policy_id')->nullable();
            $table->timestamp('first_response_due_at')->nullable();
            $table->timestamp('resolution_due_at')->nullable()->index();
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->jsonb('tags')->default('[]');
            $table->uuid('related_order_id')->nullable();
            $table->uuid('merged_into_id')->nullable();
            $table->unsignedTinyInteger('csat_score')->nullable();
            $table->jsonb('messages')->default('[]');
            $table->timestamp('created_at');
            $table->timestamp('updated_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
