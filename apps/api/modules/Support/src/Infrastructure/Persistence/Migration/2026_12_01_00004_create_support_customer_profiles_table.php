<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_customer_profiles', function (Blueprint $table): void {
            $table->uuid('user_id')->primary(); // soft ref to identity_users
            $table->string('display_name')->nullable();
            $table->string('email')->nullable();
            $table->string('segment')->default('new')->index();
            $table->integer('order_count')->default(0);
            $table->unsignedBigInteger('total_spent_minor')->default(0);
            $table->integer('ticket_count')->default(0);
            $table->jsonb('tags')->default('[]');
            $table->text('notes')->nullable();
            $table->text('insight')->nullable();
            $table->timestamp('insight_generated_at')->nullable();
            $table->timestamp('last_interaction_at')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_customer_profiles');
    }
};
