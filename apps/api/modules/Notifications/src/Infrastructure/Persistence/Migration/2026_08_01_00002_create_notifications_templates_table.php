<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key')->index();
            $table->string('channel');
            $table->string('locale', 8)->default('en');
            $table->string('subject');
            $table->text('body');

            $table->unique(['key', 'channel', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications_templates');
    }
};
