<?php

namespace Tests\Feature;

use App\Models\ChatLead;
use App\Models\KnowledgeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public static function adminPages(): array
    {
        return [
            'dashboard' => ['admin.dashboard', 'Dashboard'],
            'company' => ['admin.company-info.edit', 'Profile &amp; SEO'],
            'banners' => ['admin.banners.index', 'Home banners'],
            'brands' => ['admin.brands.index', 'Brands'],
            'industries' => ['admin.industries.index', 'Industries'],
            'customers' => ['admin.customers.index', 'Customers'],
            'articles' => ['admin.articles.index', 'Articles'],
            'categories' => ['admin.article-categories.index', 'Categories'],
            'chat leads' => ['admin.chat-leads.index', 'Chat leads'],
            'knowledge' => ['admin.knowledge.index', 'Knowledge base'],
            'conversations' => ['admin.chat-conversations.index', 'Conversations'],
            'telegram' => ['admin.telegram.edit', 'Telegram bot'],
        ];
    }

    #[DataProvider('adminPages')]
    public function test_each_admin_page_renders_with_its_menu_item_marked_current(string $route, string $label): void
    {
        $html = $this->actingAs($this->admin())->get(route($route))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/aria-current="page"[^>]*>.*?'.preg_quote($label, '/').'/s',
            $html,
        );
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
    }

    public function test_sidebar_shows_badge_for_unhandled_chat_leads(): void
    {
        ChatLead::create(['name' => 'A', 'phone' => '1', 'locale' => 'uz']);
        ChatLead::create(['name' => 'B', 'phone' => '2', 'locale' => 'ru']);
        ChatLead::create(['name' => 'C', 'phone' => '3', 'locale' => 'en', 'is_handled' => true]);

        $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Chat leads', '2', 'new leads'], false);
    }

    public function test_dashboard_shows_ai_assistant_summary(): void
    {
        ChatLead::create(['name' => 'Dilshod', 'phone' => '+998901234567', 'topic' => 'RFID labels', 'locale' => 'uz']);
        KnowledgeEntry::create(['type' => 'faq', 'question' => ['en' => 'Q'], 'answer' => ['en' => 'A'], 'is_active' => true]);
        KnowledgeEntry::create(['type' => 'faq', 'question' => ['en' => 'Q2'], 'answer' => ['en' => 'A2'], 'is_active' => false]);

        $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('New chat leads')
            ->assertSee('2 total, 1 active')
            ->assertSee('Dilshod')
            ->assertSee('RFID labels')
            ->assertSee(route('admin.knowledge.create'), false);
    }

    public function test_dashboard_shows_empty_state_without_leads(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('No chat leads yet.');
    }
}
