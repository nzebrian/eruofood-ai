<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_feature_flags', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->boolean('enabled')->default(false)->index();
            $table->string('description')->nullable();
            $table->timestamp('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_feature_flags');
    }
};
