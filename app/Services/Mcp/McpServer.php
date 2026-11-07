<?php

namespace App\Services\Mcp;

use App\Models\Article;
use App\Models\Brand;
use App\Models\ChatLead;
use App\Models\CompanyInfo;
use App\Models\Industry;
use App\Models\KnowledgeEntry;
use App\Models\McpSetting;
use App\Models\SeoSetting;
use App\Models\TelegramUser;
use App\Services\Seo\SeoFiles;
use App\Services\Telegram\TelegramBot;
use App\Services\TelegramNotifier;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * A small MCP server (Model Context Protocol, JSON-RPC 2.0 over Streamable HTTP, JSON responses).
 * Read-only tools over the site's public content; `request_callback` (off by default) creates a lead.
 */
class McpServer
{
    public const PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    public const LOCALES = ['uz', 'ru', 'en'];

    public function __construct(private McpSetting $settings, private string $clientKey = 'mcp') {}

    /**
     * Handle one JSON-RPC message. Returns the response, or null for a notification.
     *
     * @return array<string, mixed>|null
     */
    public function handle(mixed $message): ?array
    {
        if (! is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0' || ! is_string($message['method'] ?? null)) {
            return $this->error($message['id'] ?? null, -32600, 'Invalid Request');
        }
        $id = $message['id'] ?? null;
        $isNotification = ! array_key_exists('id', $message);
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        if ($isNotification) {
            return null; // notifications/initialized, notifications/cancelled…
        }
        if ($id !== null && ! is_string($id) && ! is_int($id)) {
            return $this->error(null, -32600, 'Invalid Request: id must be a string or number');
        }

        return match ($message['method']) {
            'initialize' => $this->result($id, $this->initialize($params)),
            'ping' => $this->result($id, new \stdClass),
            'tools/list' => $this->result($id, ['tools' => $this->toolList()]),
            'tools/call' => $this->callTool($id, $params),
            'resources/list' => $this->result($id, ['resources' => []]),
            'prompts/list' => $this->result($id, ['prompts' => []]),
            default => $this->error($id, -32601, 'Method not found: '.mb_substr($message['method'], 0, 80)),
        };
    }

    private function initialize(array $params): array
    {
        $requested = is_string($params['protocolVersion'] ?? null) ? $params['protocolVersion'] : null;

        return [
            'protocolVersion' => in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'bdb-auto-id', 'title' => 'BDB Auto ID', 'version' => '1.0.0'],
            'instructions' => 'BDB Auto ID, an automatic identification (barcode, RFID, labels, Asl Belgi marking) company in Uzbekistan. '
                .($this->settings->require_token ? 'Team access: read customer conversations (list_conversations, get_conversation, list_open_questions) and teach the chat assistant with save_knowledge_entry (uz, ru and en texts), resolve_open_questions and add_ai_note. Use only facts found in BDB data; never invent prices or promises. ' : '')
                .'Use get_company_info for contacts, list_brands / list_industries for what BDB offers, search_faq for customer questions, '
                .'list_articles / get_article for articles. Texts are available in uz, ru and en (locale parameter). Cite the page links you are given.',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function toolList(): array
    {
        $locale = ['type' => 'string', 'enum' => self::LOCALES, 'description' => 'Language of the texts (default en)'];
        $schemas = [
            'get_company_info' => ['type' => 'object', 'properties' => ['locale' => $locale]],
            'list_brands' => ['type' => 'object', 'properties' => ['locale' => $locale]],
            'list_industries' => ['type' => 'object', 'properties' => ['locale' => $locale]],
            'search_faq' => ['type' => 'object', 'required' => ['query'], 'properties' => [
                'query' => ['type' => 'string', 'description' => 'The customer question or keywords', 'maxLength' => 300],
                'locale' => $locale,
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 5],
            ]],
            'list_articles' => ['type' => 'object', 'properties' => [
                'locale' => $locale,
                'query' => ['type' => 'string', 'description' => 'Optional words to search in titles and summaries', 'maxLength' => 200],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 10],
            ]],
            'get_article' => ['type' => 'object', 'required' => ['slug'], 'properties' => [
                'slug' => ['type' => 'string', 'description' => 'The article slug or its full link from list_articles', 'maxLength' => 300],
                'locale' => $locale,
            ]],
            'request_callback' => ['type' => 'object', 'required' => ['name', 'phone'], 'properties' => [
                'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 60],
                'phone' => ['type' => 'string', 'description' => 'With country code, e.g. +998901234567', 'maxLength' => 20],
                'topic' => ['type' => 'string', 'description' => 'What the customer needs', 'maxLength' => 500],
                'locale' => $locale,
            ]],
        ];

        $schemas += (new TeamTools($this->settings))->schemas();
        $info = McpSetting::TOOLS + TeamTools::TOOLS;

        return array_map(fn (string $tool) => [
            'name' => $tool,
            'title' => $info[$tool][0],
            'description' => $info[$tool][1],
            'inputSchema' => $schemas[$tool],
            'annotations' => [
                'readOnlyHint' => $tool !== 'request_callback' && ! (TeamTools::TOOLS[$tool][2] ?? false),
                'openWorldHint' => false,
            ],
        ], $this->settings->enabledTools());
    }

    private function callTool(int|string|null $id, array $params): array
    {
        $tool = is_string($params['name'] ?? null) ? $params['name'] : '';
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if (! $this->settings->toolEnabled($tool)) {
            return $this->error($id, -32602, 'Unknown or disabled tool: '.mb_substr($tool, 0, 60));
        }

        $locale = in_array($args['locale'] ?? null, self::LOCALES, true) ? $args['locale'] : 'en';
        try {
            $data = match ($tool) {
                'get_company_info' => $this->companyInfo($locale),
                'list_brands' => $this->brands($locale),
                'list_industries' => $this->industries($locale),
                'search_faq' => $this->searchFaq($args, $locale),
                'list_articles' => $this->articles($args, $locale),
                'get_article' => $this->article($args, $locale),
                'request_callback' => $this->requestCallback($args, $locale),
                default => (new TeamTools($this->settings))->call($tool, $args),
            };
        } catch (ToolError $e) {
            return $this->result($id, ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true]);
        } catch (Throwable $e) {
            report($e);

            return $this->result($id, ['content' => [['type' => 'text', 'text' => 'The tool failed. Please try again later.']], 'isError' => true]);
        }

        return $this->result($id, [
            'content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]],
            'structuredContent' => $data,
            'isError' => false,
        ]);
    }

    // Tools -------------------------------------------------------------------

    private function companyInfo(string $locale): array
    {
        $info = CompanyInfo::instance();
        $seo = SeoSetting::current();

        return array_filter([
            'name' => 'BDB Auto ID',
            'website' => SeoFiles::publicUrl(route('site.home', $locale)),
            'about' => $this->plain($this->tr($info, 'about', $locale), 2000),
            'slogan' => $this->tr($info, 'slogan', $locale),
            'address' => $this->tr($info, 'address', $locale),
            'phone' => $info->phone,
            'email' => $info->email,
            'telegram_bot' => $info->telegramBot()['url'] ?? null,
            'opening_hours' => $seo->opening_hours,
            'map' => $info->yandexMapLink(),
            'online_shop' => $info->shopUrl('mcp'),
            'social' => $info->socialLinks() ?: null,
            'contact_page' => SeoFiles::publicUrl(route('site.contact', $locale)),
        ], fn ($v) => filled($v));
    }

    private function brands(string $locale): array
    {
        return ['brands' => Brand::active()->get()->map(fn (Brand $b) => array_filter([
            'name' => $this->tr($b, 'name', $locale),
            'description' => $this->plain($this->tr($b, 'description', $locale), 500),
            'website' => $b->website,
        ]))->values()->all(), 'page' => SeoFiles::publicUrl(route('site.brands', $locale))];
    }

    private function industries(string $locale): array
    {
        return ['industries' => Industry::active()->withCount(['customers' => fn ($q) => $q->active()])->get()
            ->map(fn (Industry $i) => array_filter([
                'name' => $this->tr($i, 'name', $locale),
                'description' => $this->plain($this->tr($i, 'description', $locale), 500),
                'customers' => $i->customerTotal(),
            ], fn ($v) => $v !== null && $v !== ''))->values()->all(), 'page' => SeoFiles::publicUrl(route('site.industries', $locale))];
    }

    private function searchFaq(array $args, string $locale): array
    {
        $query = $this->stringArg($args, 'query', 300, required: true);
        $limit = $this->intArg($args, 'limit', 5, 1, 20);
        $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)), fn ($w) => mb_strlen($w) >= 3));

        $entries = KnowledgeEntry::active()->whereIn('type', SeoSetting::current()->faqTypes())->get()
            ->map(function (KnowledgeEntry $e) use ($locale, $words) {
                $question = $this->tr($e, 'question', $locale);
                $answer = $this->tr($e, 'answer', $locale);
                $haystack = mb_strtolower($question.' '.$answer.' '.$e->keywords);
                $score = collect($words)->sum(fn ($w) => str_contains($haystack, $w) ? (str_contains(mb_strtolower($question), $w) ? 2 : 1) : 0);

                return ['question' => $question, 'answer' => $this->plain($answer, 2000), 'link' => $e->link ?: null, 'score' => $score];
            })
            ->filter(fn ($e) => $e['score'] > 0 && filled($e['question']) && filled($e['answer']))
            ->sortByDesc('score')->take($limit)
            ->map(fn ($e) => array_filter(array_diff_key($e, ['score' => 1])))->values()->all();

        $faq = SeoSetting::current()->faq_enabled ? SeoFiles::publicUrl(route('site.faq', $locale)) : null;

        return array_filter(['results' => $entries, 'faq_page' => $faq], fn ($v) => $v !== null);
    }

    private function articles(array $args, string $locale): array
    {
        $query = mb_strtolower($this->stringArg($args, 'query', 200));
        $limit = $this->intArg($args, 'limit', 10, 1, 20);

        $list = Article::published()->with('category')->take(200)->get()
            ->map(fn (Article $a) => $this->articleSummary($a, $locale))
            ->filter()
            ->filter(fn ($a) => $query === '' || str_contains(mb_strtolower($a['title'].' '.($a['summary'] ?? '')), $query))
            ->take($limit)->values()->all();

        return ['articles' => $list];
    }

    private function article(array $args, string $locale): array
    {
        $slug = $this->stringArg($args, 'slug', 300, required: true);
        if (str_contains($slug, '/')) {
            $slug = basename(parse_url($slug, PHP_URL_PATH) ?: $slug);
        }

        $article = Article::published()->with('category')->get()->first(function (Article $a) use ($slug) {
            foreach (self::LOCALES as $l) {
                if ($a->getTranslation('slug', $l, false) === $slug) {
                    return true;
                }
            }

            return false;
        });
        if (! $article || ! ($summary = $this->articleSummary($article, $locale))) {
            throw new ToolError('No published article with this slug.');
        }

        return $summary + ['text' => $this->plain($this->tr($article, 'content', $locale), 20000)];
    }

    private function requestCallback(array $args, string $locale): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $this->stringArg($args, 'name', 60, required: true)));
        if (! TelegramBot::isValidName($name)) {
            throw new ToolError('name must be a person\'s name (2–60 letters).');
        }
        $phone = TelegramUser::normalizePhone($this->stringArg($args, 'phone', 20, required: true));
        if ($phone === null) {
            throw new ToolError('phone must be a phone number with country code, e.g. +998901234567.');
        }
        if (! RateLimiter::attempt('mcp-callback:'.$this->clientKey, 5, fn () => true, 3600)) {
            throw new ToolError('Too many call-back requests from this client. Try again later.');
        }

        $lead = ChatLead::create([
            'channel' => 'mcp', 'name' => $name, 'phone' => $phone, 'locale' => $locale,
            'topic' => $this->stringArg($args, 'topic', 500) ?: null,
        ]);
        app(TelegramNotifier::class)->newLead($lead);

        return ['ok' => true, 'message' => 'Thank you — BDB will call this number back.'];
    }

    // Helpers -----------------------------------------------------------------

    private function articleSummary(Article $article, string $locale): ?array
    {
        foreach (array_unique([$locale, 'en', 'ru', 'uz']) as $l) {
            $slug = $article->getTranslation('slug', $l, false);
            $category = $article->category?->getTranslation('slug', $l, false);
            $title = $article->getTranslation('title', $l, false);
            if ($slug && $category && $title) {
                return array_filter([
                    'title' => $title,
                    'slug' => $slug,
                    'url' => SeoFiles::publicUrl(route('site.news.show', [$l, $category, $slug])),
                    'summary' => $this->plain($article->getTranslation('excerpt', $l, false), 400),
                    'published' => $article->published_at?->toDateString(),
                    'locale' => $l,
                ]);
            }
        }

        return null;
    }

    private function tr($model, string $field, string $locale): string
    {
        foreach (array_unique([$locale, 'en', 'ru', 'uz']) as $l) {
            if (filled($text = $model->getTranslation($field, $l, false))) {
                return (string) $text;
            }
        }

        return '';
    }

    private function plain(?string $html, int $max): string
    {
        return Str::limit(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5))), $max);
    }

    private function stringArg(array $args, string $key, int $max, bool $required = false): string
    {
        $value = $args[$key] ?? null;
        if ($value !== null && ! is_string($value)) {
            throw new ToolError("{$key} must be a string.");
        }
        $value = trim((string) $value);
        if ($required && $value === '') {
            throw new ToolError("{$key} is required.");
        }

        return mb_substr($value, 0, $max);
    }

    private function intArg(array $args, string $key, int $default, int $min, int $max): int
    {
        $value = $args[$key] ?? $default;

        return is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
    }

    private function result(int|string|null $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => is_string($id) || is_int($id) ? $id : null, 'error' => ['code' => $code, 'message' => $message]];
    }
}
