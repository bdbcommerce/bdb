<?php

namespace App\Services\Invoices;

/**
 * Claude API list prices in US dollars per million tokens (standard tier, no batch).
 * Source: Anthropic pricing (platform.claude.com/docs/en/about-claude/pricing) as of November 2026 —
 * update here when Anthropic changes them. Cache writes are the 5-minute TTL rate (the reader's default).
 * Haiku 5.5: the rate for prompts of 100K tokens or fewer (an invoice photo is far below that).
 */
class ClaudePricing
{
    /** model id prefix => [input, output, cache write, cache read] per million tokens */
    public const PRICES = [
        'claude-opus-5-5' => [4.00, 20.00, 5.00, 0.20],
        'claude-sonnet-5-5' => [2.00, 10.00, 2.50, 0.10],
        'claude-haiku-5-5' => [0.10, 0.50, 0.125, 0.01],
        'claude-opus-5' => [5.00, 25.00, 6.25, 0.50],
        'claude-sonnet-5' => [2.00, 10.00, 2.50, 0.20],
    ];

    /** Price row for a model id as Claude reports it (an id may carry a date suffix); null when unknown. */
    public static function for(?string $model): ?array
    {
        $model = strtolower(trim((string) $model));
        // Longest prefix first, so "claude-opus-5-5" wins over "claude-opus-5".
        $ids = array_keys(self::PRICES);
        usort($ids, fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($ids as $id) {
            if ($model === $id || str_starts_with($model, $id.'-')) {
                return self::PRICES[$id];
            }
        }

        return null;
    }

    /** Cost of one request in US dollars; null when the model's price is unknown. */
    public static function cost(?string $model, int $input, int $output, int $cacheWrite = 0, int $cacheRead = 0): ?float
    {
        $p = self::for($model);
        if (! $p) {
            return null;
        }

        return round(($input * $p[0] + $output * $p[1] + $cacheWrite * $p[2] + $cacheRead * $p[3]) / 1_000_000, 6);
    }

    /** $0.0123 → "$0.0123", $12.5 → "$12.50". */
    public static function format(?float $usd): string
    {
        if ($usd === null) {
            return '—';
        }

        if ($usd > 0 && $usd < 0.00005) {
            return '<$0.0001'; // a real cost too small for 4 decimals
        }
        // Decide the decimals on the rounded value, so 0.99996 shows as $1.00, not $1.0000.
        $decimals = $usd > 0 && round($usd, 4) < 1 ? 4 : 2;

        return '$'.number_format($usd, $decimals, '.', ' ');
    }
}
