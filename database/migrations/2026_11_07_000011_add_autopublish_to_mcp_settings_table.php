<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** MCP team tools: knowledge entries / AI notes Claude adds go live at once (true) or as drafts (false). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_settings', function (Blueprint $table) {
            $table->boolean('autopublish')->default(false)->after('rate_limit');
        });
    }

    public function down(): void
    {
        Schema::table('mcp_settings', function (Blueprint $table) {
            $table->dropColumn('autopublish');
        });
    }
};
