<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('support_sla_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('priority')->index();
            $table->integer('first_response_minutes');
            $table->integer('resolution_minutes');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_sla_policies');
    }
};
