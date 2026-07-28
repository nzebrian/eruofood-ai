<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_accounts', function (Blueprint $table): void {
            $table->uuid('user_id')->primary(); // soft ref to identity_users
            $table->jsonb('roles')->default('[]');
            $table->jsonb('extra_permissions')->default('[]');
            $table->string('status')->default('active')->index();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_accounts');
    }
};
