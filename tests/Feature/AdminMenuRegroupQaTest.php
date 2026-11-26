<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/** QA of the regrouped admin menu: every page 200, one active item, breadcrumb = "Group / Item", menu links unique. */
class AdminMenuRegroupQaTest extends TestCase
{
    use RefreshDatabase;

    /** Old menu (HEAD) route names; each must stay in the menu. */
    private const FORMER_ROUTES = [
        'admin.dashboard', 'admin.analytics', 'admin.scheduled-tasks.index', 'admin.awaiting-replies.index',
        'admin.ai-team.shadow', 'admin.expert-questions.index', 'admin.client-requests.index', 'admin.chat-leads.index',
        'admin.chat-conversations.index', 'admin.telegram-chats.index', 'admin.telegram-users.index', 'admin.clients.index',
        'admin.ai-employees.index', 'admin.sales-knowledge.index', 'admin.knowledge.index', 'admin.catalog.index',
        'admin.sales-assistant.prompt', 'admin.ai-instructions.index', 'admin.ai-test-cases.index', 'admin.ai-team.sandbox',
        'admin.ai-team.traces', 'admin.sales-report.index', 'admin.invoice-messages.index', 'admin.company-info.edit',
        'admin.banners.index', 'admin.seo.edit', 'admin.color-themes.index', 'admin.brands.index', 'admin.industries.index',
        'admin.customers.index', 'admin.articles.index', 'admin.article-categories.index', 'admin.job-applications.index',
        'admin.vacancies.index', 'admin.telegram.edit', 'admin.sales-assistant.edit', 'admin.ai-models.index',
        'admin.invoice-parser.edit', 'admin.mcp.edit',
    ];

    private const SKIP = ['admin.login', 'admin.invoice-messages.image', 'admin.job-applications.cv', 'admin.telegram.media'];

    private function seedRows(): array
    {
        $now = now();
        $j = fn ($v) => json_encode(['uz' => $v, 'ru' => $v, 'en' => $v]);
        $id = fn (string $t, array $row) => DB::table($t)->insertGetId($row + ['created_at' => $now, 'updated_at' => $now]);

        $cat = $id('article_categories', ['name' => $j('Cat'), 'slug' => $j('cat')]);
        $industry = $id('industries', ['name' => $j('Ind')]);
        $tg = $id('telegram_users', ['telegram_id' => '9001', 'name' => 'Aziz', 'locale' => 'uz']);
        $conv = $id('chat_conversations', ['uuid' => (string) Str::uuid(), 'channel' => 'telegram', 'external_id' => '9001', 'locale' => 'uz',
            'messages' => json_encode([['role' => 'user', 'content' => 'Salom', 'at' => $now->toIso8601String()]]), 'message_count' => 1]);
        $client = $id('clients', ['name' => 'Acme']);
        $case = DB::table('ai_test_cases')->value('id');
        $emp = DB::table('ai_employees')->value('id');
        $prompt = DB::table('ai_employee_prompts')->where('ai_employee_id', $emp)->value('id');
        $vacancy = $id('vacancies', ['title' => $j('Manager'), 'department' => 'sales']);
        $inv = $id('invoice_messages', ['chat_id' => '-100', 'message_id' => 1, 'file_id' => 'f1']);

        return [
            'ai_employee' => $emp,
            'ai_instruction' => $id('ai_instructions', ['title' => 'T', 'content' => 'c']),
            'ai_model' => $id('ai_models', ['name' => 'M', 'provider' => 'gemini', 'model' => 'gemini-x', 'tasks' => json_encode(['chat'])]),
            'trace' => $id('ai_traces', ['status' => 'ok', 'steps' => json_encode([])]),
            'ai_test_case' => $case,
            'run' => $id('ai_test_runs', ['ai_test_case_id' => $case, 'ai_employee_id' => $emp, 'ai_employee_prompt_id' => $prompt,
                'prompt_version' => 1, 'prompt_hash' => md5('x')]),
            'article_category' => $cat,
            'article' => $id('articles', ['article_category_id' => $cat, 'title' => $j('Art'), 'slug' => $j('art'), 'content' => $j('Body')]),
            'banner' => $id('banners', ['title' => $j('Hero')]),
            'brand' => $id('brands', ['name' => $j('Zebra')]),
            'chat_conversation' => $conv,
            'clientPerson' => $id('client_people', ['name' => 'Ali', 'client_id' => $client]),
            'clientRequest' => $id('client_requests', ['locale' => 'uz', 'question' => 'Narxi?', 'chat_conversation_id' => $conv]),
            'client' => $client,
            'color_theme' => $id('color_themes', ['name' => 'QA', 'accent' => '#1d4ed8']),
            'customer' => $id('customers', ['name' => 'Cust', 'industry_id' => $industry]),
            'industry' => $industry,
            'invoice_message' => $inv,
            'job_application' => $id('job_applications', ['name' => 'Ann', 'phone' => '+998901234567', 'cv_name' => 'cv.pdf', 'vacancy_id' => $vacancy]),
            'knowledge' => $id('knowledge_entries', ['question' => $j('Q'), 'answer' => $j('A')]),
            'salesKnowledge' => DB::table('sales_knowledge')->value('id'),
            'salesReport' => $id('sales_reports', ['period_start' => $now->copy()->subWeek(), 'period_end' => $now, 'trigger' => 'manual', 'status' => 'sent']),
            'telegram_user' => $tg,
            'order' => $id('orders', ['telegram_user_id' => $tg, 'status' => 'confirmed', 'items' => json_encode([['product' => 'Zebra', 'details' => '1']])]),
            'vacancy' => $vacancy,
            'page' => 'audit',
        ];
    }

    private function breadcrumb(string $html): string
    {
        preg_match('/<nav aria-label="Breadcrumb">\s*<p[^>]*>(.*?)<\/p>/s', $html, $m);

        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[1] ?? ''))));
    }

    private function sidebar(string $html): string
    {
        preg_match('/<nav class="flex-1[^>]*aria-label="Main".*?<\/nav>/s', $html, $nav);

        return $nav[0] ?? '';
    }

    public function test_every_former_menu_route_is_in_the_menu_exactly_once(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $nav = $this->sidebar($this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->getContent());
        preg_match_all('/<a href="([^"]+)"/', $nav, $links);
        $counts = array_count_values($links[1]);

        foreach (self::FORMER_ROUTES as $name) {
            $this->assertSame(1, $counts[route($name)] ?? 0, $name);
        }
        foreach (['audit', 'gaps'] as $page) {
            $this->assertSame(1, $counts[route('admin.ai-team.show', $page)] ?? 0, $page);
        }
        $this->assertSame(array_keys($counts), array_values(array_unique($links[1])));
        $this->assertCount(count(self::FORMER_ROUTES) + 2, $counts, implode("\n", array_keys($counts)));
    }

    public function test_every_admin_get_page_is_200_with_a_consistent_breadcrumb(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $params = $this->seedRows();
        $problems = [];
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if (! $name || ! str_starts_with($name, 'admin.') || in_array($name, self::SKIP, true) || ! in_array('GET', $route->methods())) {
                continue;
            }
            $args = [];
            foreach ($route->parameterNames() as $p) {
                $args[$p] = $params[$p] ?? null;
            }
            if (in_array(null, $args, true)) {
                $problems[] = "$name: no test value for ".implode(',', array_keys($args, null, true));

                continue;
            }
            $res = $this->actingAs($admin)->get(route($name, $args));
            $checked++;
            if ($res->status() !== 200) {
                $problems[] = "$name: HTTP ".$res->status();

                continue;
            }
            $html = $res->getContent();
            if (! str_contains($html, 'aria-label="Breadcrumb"')) {
                continue; // not an admin layout page
            }
            $nav = $this->sidebar($html);
            preg_match_all('/<a[^>]*aria-current="page"[^>]*>(.*?)<\/a>/s', $nav, $cur);
            $crumb = $this->breadcrumb($html);
            if (count($cur[1]) !== 1) {
                $problems[] = "$name: ".count($cur[1])." active items, breadcrumb '$crumb'";

                continue;
            }
            $label = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($cur[1][0]))));
            if (! str_contains($label, last(explode(' / ', $crumb)))) {
                $problems[] = "$name: breadcrumb '$crumb' vs active '$label'";
            }
        }

        $this->assertGreaterThan(70, $checked);
        $this->assertSame([], $problems, implode("\n", $problems));
    }
}
