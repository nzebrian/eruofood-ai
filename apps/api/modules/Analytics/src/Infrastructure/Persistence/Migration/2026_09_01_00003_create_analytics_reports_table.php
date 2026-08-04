<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('analytics_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key')->index();
            $table->string('title');
            $table->date('range_from');
            $table->date('range_to');
            $table->jsonb('columns')->default('[]');
            $table->jsonb('rows')->default('[]');
            $table->string('status')->default('ready');
            $table->timestamp('generated_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_reports');
    }
};
