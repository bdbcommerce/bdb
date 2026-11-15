<?php

use App\Services\Invoices\ClaudePricing;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_messages', function (Blueprint $table) {
            $table->unsignedInteger('cache_write_tokens')->nullable()->after('output_tokens');
            $table->unsignedInteger('cache_read_tokens')->nullable()->after('cache_write_tokens');
            // Every paid read of this photo added up ("Read again" and retries included), in US dollars.
            $table->decimal('cost_usd', 12, 6)->default(0)->after('cache_read_tokens');
            $table->unsignedSmallInteger('read_count')->default(0)->after('cost_usd');
        });

        // One row per paid Claude read: spend per day / month and the daily limit count reads when they happened.
        Schema::create('invoice_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('model', 64)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->timestamp('created_at')->nullable()->index();
        });

        // Daily limit is optional now: empty = no limit. The untouched old default (100) becomes "no limit".
        Schema::table('invoice_parser_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('daily_limit')->nullable()->default(null)->change();
        });
        DB::table('invoice_parser_settings')->where('daily_limit', 100)->update(['daily_limit' => null]);

        // Earlier reads: estimate their cost from the stored tokens (one read each).
        DB::table('invoice_messages')->whereNotNull('read_at')->orderBy('id')->each(function ($row) {
            $cost = ClaudePricing::cost($row->model, (int) $row->input_tokens, (int) $row->output_tokens) ?? 0;
            DB::table('invoice_messages')->where('id', $row->id)->update(['cost_usd' => $cost, 'read_count' => 1]);
            DB::table('invoice_reads')->insert([
                'invoice_message_id' => $row->id, 'model' => $row->model, 'input_tokens' => (int) $row->input_tokens,
                'output_tokens' => (int) $row->output_tokens, 'cost_usd' => $cost, 'created_at' => $row->read_at,
            ]);
        });
    }

    public function down(): void
    {
        // Settings first, with "no limit" back to the old default, so the NOT NULL column accepts every row.
        DB::table('invoice_parser_settings')->whereNull('daily_limit')->update(['daily_limit' => 100]);
        Schema::table('invoice_parser_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('daily_limit')->default(100)->change();
        });
        Schema::dropIfExists('invoice_reads');
        Schema::table('invoice_messages', function (Blueprint $table) {
            $table->dropColumn(['cache_write_tokens', 'cache_read_tokens', 'cost_usd', 'read_count']);
        });
    }
};
