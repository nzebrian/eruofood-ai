<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('notifications_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('category')->index();
            $table->string('channel')->index();
            $table->string('template_key');
            $table->jsonb('data')->default('{}');
            $table->string('subject');
            $table->text('body');
            $table->string('priority')->default('normal');
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('scheduled_for')->nullable()->index();
            $table->timestamp('read_at')->nullable();
            $table->jsonb('timeline')->default('[]');
            $table->timestamp('created_at')->index();

            $table->index(['user_id', 'read_at']);
            $table->index(['status', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications_notifications');
    }
};
