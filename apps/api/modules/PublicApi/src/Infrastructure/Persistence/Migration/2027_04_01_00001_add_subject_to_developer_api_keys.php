<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('developer_api_keys', function (Blueprint $table): void {
            // Customer-scoped keys act on behalf of one user (the BOLA subject);
            // null for application-level keys, which cannot reach customer data.
            $table->uuid('subject_user_id')->nullable()->index()->after('scopes');
        });
    }

    public function down(): void
    {
        Schema::table('developer_api_keys', function (Blueprint $table): void {
            $table->dropColumn('subject_user_id');
        });
    }
};
