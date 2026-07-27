<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_return_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('order_id')->index();
            $table->uuid('customer_user_id')->index();
            $table->text('reason');
            $table->unsignedBigInteger('refund_minor')->default(0);
            $table->string('currency', 3)->default('NGN');
            $table->string('status')->default('requested')->index();
            $table->text('resolution_note')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_return_requests');
    }
};
