<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Client portraits: a company and the people we talk to there (the AI team reads them before answering).
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('full_name', 300)->nullable(); // the official name (1C fullName / registry)
            $table->string('inn', 20)->nullable()->unique(); // digits only; one company per INN
            $table->string('onec_id', 40)->nullable()->unique(); // 1C GUID (crm/company id)
            $table->unsignedInteger('onec_code')->nullable(); // 1C client code
            $table->string('contract', 10)->nullable(); // annual | per_deal (1C one_time: a new contract every time)
            $table->string('price_type', 10)->nullable(); // end_user | dealer | partner — for contacts a manager linked; null = end user
            $table->string('source', 10)->default('local'); // local (typed here) | 1c | registry
            $table->string('address', 300)->nullable();
            $table->string('director', 150)->nullable();
            $table->json('lookup_data')->nullable(); // the last 1C / registry answer (fields we keep)
            $table->timestamp('looked_up_at')->nullable();
            $table->string('industry', 100)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('size', 150)->nullable();
            $table->string('status', 12)->default('prospect');
            $table->string('since', 20)->nullable();
            $table->string('owner', 100)->nullable();
            $table->text('installed')->nullable();
            $table->text('consumables')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('client_people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('telegram_user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('previous_id')->nullable()->constrained('client_people')->nullOnDelete(); // the card at the earlier company
            $table->string('name', 100);
            $table->string('position', 100)->nullable();
            $table->string('department', 100)->nullable();
            $table->string('part', 20)->nullable(); // decides | pays | uses | technical | recommends
            $table->string('status', 16)->default('active');
            $table->string('style', 300)->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['telegram_user_id', 'status']);
        });

        // A Telegram contact or group works with one or more companies. linked_by: ai (an INN the customer sent) | manager.
        Schema::create('company_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('telegram_user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('group_chat_id', 32)->nullable(); // a Telegram group (chat_conversations.external_id, channel group)
            $table->string('linked_by', 10)->default('manager');
            $table->string('linked_by_name', 100)->nullable();
            $table->timestamps();
            $table->unique(['client_id', 'telegram_user_id']);
            $table->unique(['client_id', 'group_chat_id']);
            $table->index('group_chat_id');
        });

        // A contact's orders: one open draft (the AI continues it until it is confirmed or cancelled), the last confirmed
        // ones kept in full (OrderBook::KEEP), older ones added up per month in order_months. Company details are copied.
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chat_conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('chat_lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->json('company')->nullable(); // {name, full_name, inn, address} as at the order
            $table->string('status', 10)->default('draft'); // draft | confirmed | cancelled
            $table->json('items')->nullable(); // [{product, details}]
            $table->json('quoted')->nullable(); // [{product, price}]
            $table->string('summary', 800)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('nudged_at')->nullable(); // the AI's one reminder about a quiet draft
            $table->timestamps();
            $table->index(['telegram_user_id', 'status']);
        });
        Schema::create('order_months', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_user_id')->constrained()->cascadeOnDelete();
            $table->string('month', 7); // YYYY-MM
            $table->unsignedInteger('orders')->default(0);
            $table->json('items')->nullable(); // {product: times ordered}
            $table->unsignedBigInteger('quoted_sum')->default(0); // the quoted prices added up (sum), when they could be read
            $table->timestamps();
            $table->unique(['telegram_user_id', 'month']);
        });

        // Items from 1C (crm/item, loaded by an admin): names, MXIK and the client company an item was made for.
        Schema::create('onec_items', function (Blueprint $table) {
            $table->id();
            $table->string('onec_id', 40)->unique();
            $table->unsignedInteger('code')->unique();
            $table->string('name', 200);
            $table->string('full_name', 300)->nullable(); // acc_name
            $table->string('short_name', 200)->nullable();
            $table->string('mxik', 20)->nullable();
            $table->string('barcode', 64)->nullable();
            $table->string('unit', 40)->nullable();
            $table->unsignedSmallInteger('category_id')->nullable();
            $table->string('company_onec_id', 40)->nullable()->index(); // the client company it was made for
            $table->string('description', 500)->nullable();
            $table->timestamps();
        });

        // Private persons from the 1C client list (no STIR): matched to Telegram contacts by phone.
        Schema::create('onec_people', function (Blueprint $table) {
            $table->id();
            $table->string('onec_id', 40)->unique();
            $table->unsignedInteger('code')->nullable();
            $table->string('name', 150);
            $table->string('phone', 20)->nullable()->index(); // normalised (+998…)
            $table->string('address', 300)->nullable();
            $table->timestamps();
        });

        // What a company usually orders, and what we plan to sell them (catalog categories, brands, SKUs or 1C items).
        Schema::create('company_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('list', 8); // usual | plan
            $table->string('kind', 10); // category | brand | sku | item1c (onec_items.code)
            $table->string('value', 64);
            $table->timestamp('moved_at')->nullable(); // moved from the sales plan to usual
            $table->string('moved_by', 120)->nullable(); // a manager's name, "Request #12" or "1C purchase"
            $table->timestamps();
            $table->unique(['client_id', 'list', 'kind', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_months');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('company_items');
        Schema::dropIfExists('onec_items');
        Schema::dropIfExists('onec_people');
        Schema::dropIfExists('company_links');
        Schema::dropIfExists('client_people');
        Schema::dropIfExists('clients');
    }
};
