<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('search_query_log', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('term')->index();
            $table->string('type')->default('global');
            $table->integer('result_count')->default(0)->index();
            $table->uuid('user_id')->nullable()->index();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_query_log');
    }
};
