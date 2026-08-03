<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('developer_webhooks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('application_id')->index();
            $table->string('url');
            $table->jsonb('events')->default('[]');
            $table->text('secret');                       // encrypted at rest by the model cast
            $table->string('status')->default('active')->index();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('developer_webhooks');
    }
};
