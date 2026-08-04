<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('notifications_broadcasts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('body');
            $table->string('category');
            $table->jsonb('channels')->default('[]');
            $table->string('segment');
            $table->timestamp('scheduled_for')->nullable();
            $table->boolean('sent')->default(false);
            $table->unsignedInteger('recipient_count')->default(0);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications_broadcasts');
    }
};
