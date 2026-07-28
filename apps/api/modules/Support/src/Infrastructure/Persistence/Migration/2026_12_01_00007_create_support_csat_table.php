<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_csat', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id')->index();
            $table->uuid('user_id')->index();
            $table->unsignedTinyInteger('score');
            $table->text('comment')->nullable();
            $table->uuid('agent_id')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_csat');
    }
};
