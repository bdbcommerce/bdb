<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MCP server (Model Context Protocol) at /mcp: lets Claude and other AI clients read the company's
 * public data. Singleton (id = 1). The access token is stored hashed; it is shown once when created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->boolean('require_token')->default(true);
            $table->string('token_hash', 64)->nullable();
            $table->string('token_hint', 8)->nullable();       // last characters, to recognise the token
            $table->json('tools')->nullable();                  // tool => enabled
            $table->unsignedSmallInteger('rate_limit')->default(60); // requests per minute per client
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('calls_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_settings');
    }
};
