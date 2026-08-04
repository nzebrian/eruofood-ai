<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('nutrition_diary_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->date('entry_date');
            $table->string('meal_type');
            $table->string('item_name');
            $table->decimal('servings', 8, 2)->default(1);
            $table->uuid('nutrition_item_id')->nullable(); // soft ref to nutrition_items
            $table->jsonb('nutrition'); // snapshot of consumed nutrition
            $table->timestamps();

            $table->index(['user_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nutrition_diary_entries');
    }
};
