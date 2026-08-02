<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_referrals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code')->index();
            $table->uuid('referrer_user_id')->index();
            $table->uuid('referee_user_id')->unique();
            $table->string('status')->default('pending')->index();
            $table->timestamp('created_at');
            $table->timestamp('qualified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_referrals');
    }
};
