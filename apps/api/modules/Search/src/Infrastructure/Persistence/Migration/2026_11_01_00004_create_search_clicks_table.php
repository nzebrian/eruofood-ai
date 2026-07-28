<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_clicks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('query_id')->index();
            $table->string('document_id')->index();
            $table->integer('position')->default(0);
            $table->boolean('from_recommendation')->default(false)->index();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_clicks');
    }
};
