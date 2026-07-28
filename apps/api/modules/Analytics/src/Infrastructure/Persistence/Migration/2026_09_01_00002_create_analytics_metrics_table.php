<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_metrics', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('metric')->index();
            $table->string('category');
            $table->date('bucket_date')->index();
            $table->string('dimension_key')->nullable();
            $table->string('dimension_value')->nullable();
            $table->unsignedBigInteger('count')->default(0);
            $table->bigInteger('sum_value')->default(0);

            // One row per (metric, day, dimension). Null dimension = the total.
            $table->unique(['metric', 'bucket_date', 'dimension_key', 'dimension_value'], 'analytics_metric_bucket_unique');
            $table->index(['metric', 'dimension_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_metrics');
    }
};
