<?php

namespace Tests\Feature;

use App\Models\ChatLead;
use App\Models\KnowledgeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatAssistantTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_is_answered_from_site_knowledge(): void
    {
        config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-2.5-flash']);

        KnowledgeEntry::create([
            'type' => 'delivery',
            'question' => ['uz' => 'Kafolat?', 'ru' => 'Гарантия?', 'en' => 'Warranty?'],
            'answer' => ['uz' => '12 oy', 'ru' => '12 месяцев', 'en' => '12 months on all scanners'],
            'is_active' => true,
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'reply' => 'Scanners have a 12 month warranty.',
                    'suggestions' => ['How do I order?'],
                    'offer_callback' => false,
                ])]]]]],
            ]),
        ]);

        $this->postJson('/chat/message', [
            'locale' => 'en',
            'messages' => [['role' => 'user', 'content' => 'What warranty do you give?']],
        ])->assertOk()->assertJson([
            'reply' => 'Scanners have a 12 month warranty.',
            'suggestions' => ['How do I order?'],
            'offer_callback' => false,
        ]);

        Http::assertSent(function (Request $request) {
            $system = $request['system_instruction']['parts'][0]['text'];

            return $request->hasHeader('x-goog-api-key', 'test-key')
                && str_contains($system, '12 months on all scanners')
                && $request['contents'][0]['role'] === 'user';
        });
    }

    public function test_api_failure_returns_fallback_with_callback_offer(): void
    {
        config(['services.gemini.key' => 'test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 500)]);

        $this->postJson('/chat/message', [
            'locale' => 'ru',
            'messages' => [['role' => 'user', 'content' => 'Привет']],
        ])->assertOk()->assertJson(['offer_callback' => true]);
    }

    public function test_lead_is_stored(): void
    {
        $this->postJson('/chat/lead', [
            'locale' => 'uz',
            'name' => 'Aziz',
            'phone' => '+998 90 123 45 67',
            'topic' => 'Scanner for shop',
            'messages' => [['role' => 'user', 'content' => 'Hi']],
        ])->assertOk();

        $this->assertDatabaseHas('chat_leads', ['name' => 'Aziz', 'locale' => 'uz']);
        $this->assertSame([['role' => 'user', 'content' => 'Hi']], ChatLead::first()->transcript);
    }

    public function test_widget_renders_on_public_pages(): void
    {
        $this->get('/ru/brands')->assertOk()->assertSee('belgiChat', false)->assertSee('Belgi — ассистент BDB');
    }

    public function test_admin_can_manage_knowledge(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/admin/knowledge')->assertOk();
        $this->actingAs($admin)->get('/admin/knowledge/create')->assertOk();
        $this->actingAs($admin)->get('/admin/chat-leads')->assertOk();

        $this->actingAs($admin)->post('/admin/knowledge', [
            'type' => 'faq',
            'question_uz' => 'Q uz', 'question_ru' => 'Q ru', 'question_en' => 'Q en',
            'answer_uz' => 'A uz', 'answer_ru' => 'A ru', 'answer_en' => 'A en',
            'is_active' => '1',
        ])->assertRedirect('/admin/knowledge');

        $entry = KnowledgeEntry::first();
        $this->assertSame('A en', $entry->getTranslation('answer', 'en'));
        $this->actingAs($admin)->get("/admin/knowledge/{$entry->id}/edit")->assertOk()->assertSee('Q ru');
    }

    public function test_invalid_phone_is_rejected(): void
    {
        $this->postJson('/chat/lead', ['locale' => 'en', 'name' => 'X', 'phone' => '<script>'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }
}
