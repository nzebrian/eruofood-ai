<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_stores', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('owner_user_id')->index(); // soft ref to identity_users
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('verified')->default(false)->index();
            $table->text('description')->nullable();
            $table->string('logo')->nullable();
            $table->jsonb('address')->nullable();
            $table->string('support_email')->nullable();
            $table->string('support_phone')->nullable();
            $table->decimal('rating_average', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_stores');
    }
};
