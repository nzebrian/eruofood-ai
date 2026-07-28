<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_automation_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('trigger')->index();
            $table->jsonb('conditions')->default('[]');
            $table->jsonb('actions')->default('[]');
            $table->boolean('enabled')->default(true)->index();
            $table->integer('sort_order')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_automation_rules');
    }
};
