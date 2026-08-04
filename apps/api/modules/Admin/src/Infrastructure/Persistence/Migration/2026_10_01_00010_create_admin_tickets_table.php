<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('admin_tickets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('requester_id')->index();
            $table->string('subject');
            $table->string('category')->index();
            $table->string('status')->default('open')->index();
            $table->string('priority')->default('normal');
            $table->unsignedTinyInteger('priority_weight')->default(1)->index();
            $table->uuid('assignee_id')->nullable()->index();
            $table->jsonb('messages')->default('[]');
            $table->timestamp('created_at');
            $table->timestamp('updated_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_tickets');
    }
};
