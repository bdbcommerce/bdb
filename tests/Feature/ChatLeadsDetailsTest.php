<?php

namespace Tests\Feature;

use App\Models\ChatLead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Leads list: website leads keep the chat, sales assistant leads keep the request details — both must render. */
class ChatLeadsDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_kinds_of_stored_transcript_are_shown(): void
    {
        ChatLead::create(['name' => 'Web', 'phone' => '+998901112233', 'locale' => 'ru', 'channel' => 'web',
            'transcript' => [['role' => 'user', 'content' => 'Нужен сканер'], ['content' => 'no role here'], 'junk']]);
        ChatLead::create(['name' => 'Sales', 'phone' => '', 'locale' => 'uz', 'channel' => 'telegram',
            'transcript' => ['items' => ['Zebra ZD421: 2 pcs', ['x']], 'city' => 'Tashkent', 'company' => '<b>Korzinka</b>', 'business_type' => null, 'software' => null, 'conversation_id' => 999]]);

        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.chat-leads.index'))->assertOk()
            ->assertSee('Нужен сканер')->assertSee('no role here')->assertSee('Zebra ZD421: 2 pcs')->assertSee('Tashkent')
            ->assertSee('&lt;b&gt;Korzinka&lt;/b&gt;', false)->assertDontSee('Open the conversation');
    }
}
