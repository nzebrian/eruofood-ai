<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('loyalty_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->unique();
            $table->integer('balance')->default(0);
            $table->integer('lifetime_points')->default(0);
            $table->string('tier_key')->default('bronze')->index();
            $table->timestamp('created_at');
            $table->timestamp('updated_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_accounts');
    }
};
