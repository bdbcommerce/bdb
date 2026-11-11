<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CVs are sent to the admin Telegram chat and removed from the server: only the Telegram
 * file_id is kept. cv_path stays for CVs whose upload failed (kept on the private disk).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_applications', function (Blueprint $table) {
            $table->string('cv_file_id')->nullable()->after('cv_path');
            // The chat message holding the CV, so deleting/pruning the application removes it there too.
            $table->string('cv_chat_id', 64)->nullable()->after('cv_file_id');
            $table->unsignedBigInteger('cv_message_id')->nullable()->after('cv_chat_id');
            $table->string('cv_path')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('job_applications', function (Blueprint $table) {
            $table->dropColumn(['cv_file_id', 'cv_chat_id', 'cv_message_id']);
        });
    }
};
