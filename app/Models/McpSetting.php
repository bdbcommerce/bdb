<?php

namespace App\Models;

use App\Services\Mcp\TeamTools;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/** MCP server settings (singleton). Admin → AI Assistant → MCP server. */
class McpSetting extends Model
{
    /** Tool => [label, description, enabled by default]. All read public data except request_callback. */
    public const TOOLS = [
        'get_company_info' => ['Company info', 'Name, about, address, phone, email, opening hours, map, shop and social links', true],
        'list_brands' => ['Brands', 'Brands BDB supplies, with descriptions and websites', true],
        'list_industries' => ['Industries', 'Industries served, with the number of customers in each', true],
        'search_faq' => ['FAQ search', 'Questions and answers from the public FAQ types of the knowledge base', true],
        'list_articles' => ['Articles', 'Published articles: title, link, summary, date; optional search', true],
        'get_article' => ['Read an article', 'Full text of one published article', true],
        'request_callback' => ['Request a call back', 'Lets an AI agent leave a customer\'s name and phone as a chat lead (writes data)', false],
    ];

    protected $fillable = ['enabled', 'require_token', 'token_hash', 'token_hint', 'tools', 'rate_limit', 'autopublish', 'last_used_at', 'calls_count'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'require_token' => 'boolean', 'autopublish' => 'boolean', 'tools' => 'array', 'last_used_at' => 'datetime'];
    }

    public static function current(): self
    {
        try {
            return static::firstOrCreate(['id' => 1])->refresh();
        } catch (Throwable) {
            return new static(['enabled' => false, 'require_token' => true, 'rate_limit' => 60]);
        }
    }

    /**
     * Public tools follow their switch. Team tools (conversations, knowledge — TeamTools::TOOLS) are on
     * by default but only ever available with an access token: never on a public server.
     */
    public function toolEnabled(string $tool): bool
    {
        if (isset(TeamTools::TOOLS[$tool])) {
            return $this->require_token && (bool) ($this->tools[$tool] ?? true);
        }

        // FAQ search shows the public FAQ: when the FAQ page is off, so is the tool.
        if ($tool === 'search_faq' && ! SeoSetting::current()->faq_enabled) {
            return false;
        }

        return isset(self::TOOLS[$tool]) && (bool) ($this->tools[$tool] ?? self::TOOLS[$tool][2]);
    }

    /** @return list<string> */
    public function enabledTools(): array
    {
        return array_values(array_filter([...array_keys(self::TOOLS), ...array_keys(TeamTools::TOOLS)], fn ($t) => $this->toolEnabled($t)));
    }

    /** Create a new access token; returns it in plain text (only now — the database keeps a hash). */
    public function regenerateToken(): string
    {
        $token = 'bdb_mcp_'.Str::random(40);
        $this->forceFill(['token_hash' => hash('sha256', $token), 'token_hint' => substr($token, -4)])->save();

        return $token;
    }

    public function tokenMatches(?string $token): bool
    {
        return $this->token_hash !== null && is_string($token) && $token !== '' && hash_equals($this->token_hash, hash('sha256', $token));
    }
}
