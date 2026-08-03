<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('loyalty_redemptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('reward_id')->index();
            $table->uuid('user_id')->index();
            $table->string('code')->unique();
            $table->integer('points_spent');
            $table->string('benefit_type');
            $table->integer('benefit_value')->default(0);
            $table->string('status')->default('issued')->index();
            $table->timestamp('created_at')->index();
            $table->timestamp('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_redemptions');
    }
};
