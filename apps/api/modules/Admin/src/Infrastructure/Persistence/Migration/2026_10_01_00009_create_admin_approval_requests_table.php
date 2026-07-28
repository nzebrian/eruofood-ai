<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_approval_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('subject_type')->index();  // vendor / restaurant
            $table->uuid('subject_id')->index();       // soft ref
            $table->string('kind')->index();
            $table->jsonb('details')->default('{}');
            $table->string('status')->default('pending')->index();
            $table->uuid('decided_by')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('submitted_at')->index();
            $table->timestamp('decided_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_approval_requests');
    }
};
