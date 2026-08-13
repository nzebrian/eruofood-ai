<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('verification_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('case_id')->index();

            $table->string('document_type', 32);
            $table->char('issuing_country', 2)->nullable();

            /*
             * The last four characters of the document number, encrypted.
             *
             * Note what this table does not have and will not be given: an image
             * column, a file path, a storage disk reference, or the full number.
             * The provider holds the artefact; we hold enough to reconcile a
             * support call and nothing an attacker could use.
             */
            $table->text('number_last4')->nullable();

            $table->date('expires_on')->nullable();
            $table->string('provider_reference')->nullable();
            $table->timestamp('created_at');

            $table->index(['case_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_documents');
    }
};
