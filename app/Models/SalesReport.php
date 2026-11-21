<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/** One weekly sales report (App\Services\SalesReport\SalesReporter). Kept a year. */
class SalesReport extends Model
{
    use MassPrunable;

    public const KEEP_DAYS = 365;

    protected $fillable = [
        'period_start', 'period_end', 'trigger', 'status', 'chat_id', 'conversations', 'report', 'html', 'message_ids',
        'model', 'input_tokens', 'output_tokens', 'cost_usd', 'error',
    ];

    protected function casts(): array
    {
        return ['period_start' => 'datetime', 'period_end' => 'datetime', 'report' => 'array', 'message_ids' => 'array', 'cost_usd' => 'float'];
    }

    /**
     * The sent messages for the admin page: only <b>, <i>, <code> and links to http(s) addresses, without other attributes.
     *
     * @return list<string>
     */
    public function safeMessages(): array
    {
        return array_map(function (string $html) {
            $html = strip_tags($html, '<b><i><a><code>');
            $html = (string) preg_replace('/<(b|i|code)\b[^>]*>/i', '<$1>', $html);

            return (string) preg_replace_callback('/<a\b[^>]*>/i', fn ($m) => preg_match('/\bhref="(https?:\/\/[^"<>\s]+)"/i', $m[0], $h)
                ? '<a href="'.$h[1].'" target="_blank" rel="noopener noreferrer">' : '<a>', $html);
        }, array_values(array_filter(explode("\n\n— — —\n\n", (string) $this->html), 'strlen')));
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }
}
