<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * A colour set for the public site: one accent colour (buttons, links, highlights) and a
 * neutral grey family (text, borders, backgrounds). Exactly one theme is active.
 *
 * The site's CSS uses `brand-*` (accent) and `gray-*` (neutral) Tailwind colours. The built-in
 * default (locked) is the original BDB red + gray compiled into app.css, so it adds no CSS;
 * any other theme overrides those CSS variables with a small <style> in the site layout.
 */
class ColorTheme extends Model
{
    public const CACHE_KEY = 'site.color_theme';

    public const SHADES = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];

    /** White text on the accent (buttons) must reach WCAG AA for normal text. */
    public const MIN_CONTRAST = 4.5;

    /** Tailwind v4 grey families (oklch), used as the site's text/border/background greys. */
    public const NEUTRALS = [
        'gray' => ['label' => 'Gray (cool, default)', 'scale' => [
            '98.5% 0.002 247.839', '96.7% 0.003 264.542', '92.8% 0.006 264.531', '87.2% 0.01 258.338',
            '70.7% 0.022 261.325', '55.1% 0.027 264.364', '44.6% 0.03 256.802', '37.3% 0.034 259.733',
            '27.8% 0.033 256.848', '21% 0.034 264.665', '13% 0.028 261.692']],
        'slate' => ['label' => 'Slate (blue-grey)', 'scale' => [
            '98.4% 0.003 247.858', '96.8% 0.007 247.896', '92.9% 0.013 255.508', '86.9% 0.022 252.894',
            '70.4% 0.04 256.788', '55.4% 0.046 257.417', '44.6% 0.043 257.281', '37.2% 0.044 257.287',
            '27.9% 0.041 260.031', '20.8% 0.042 265.755', '12.9% 0.042 264.695']],
        'zinc' => ['label' => 'Zinc (cool neutral)', 'scale' => [
            '98.5% 0 0', '96.7% 0.001 286.375', '92% 0.004 286.32', '87.1% 0.006 286.286',
            '70.5% 0.015 286.067', '55.2% 0.016 285.938', '44.2% 0.017 285.786', '37% 0.013 285.805',
            '27.4% 0.006 286.033', '21% 0.006 285.885', '14.1% 0.005 285.823']],
        'neutral' => ['label' => 'Neutral (pure grey)', 'scale' => [
            '98.5% 0 0', '97% 0 0', '92.2% 0 0', '87% 0 0', '70.8% 0 0', '55.6% 0 0',
            '43.9% 0 0', '37.1% 0 0', '26.9% 0 0', '20.5% 0 0', '14.5% 0 0']],
        'stone' => ['label' => 'Stone (warm grey)', 'scale' => [
            '98.5% 0.001 106.423', '97% 0.001 106.424', '92.3% 0.003 48.717', '86.9% 0.005 56.366',
            '70.9% 0.01 56.259', '55.3% 0.013 58.071', '44.4% 0.011 73.639', '37.4% 0.01 67.558',
            '26.8% 0.007 34.298', '21.6% 0.006 56.043', '14.7% 0.004 49.25']],
    ];

    /**
     * Per-component colours, grouped for the admin form. Each has a default: "brand:600" (accent
     * shade), "gray:900" (neutral shade) or "#ffffff". A theme stores only the ones changed; the
     * defaults are mirrored as --site-* variables in resources/css/app.css (keep both in sync).
     */
    public const COMPONENTS = [
        'header' => ['label' => 'Header', 'fields' => [
            'header-bg' => ['Background', '#ffffff'],
            'header-border' => ['Bottom border', 'gray:200'],
        ]],
        'nav' => ['label' => 'Navigation menu', 'fields' => [
            'nav-text' => ['Link text', 'gray:600'],
            'nav-hover' => ['Link text on hover', 'gray:900'],
            'nav-hover-bg' => ['Link background on hover', 'gray:50'],
            'nav-active' => ['Current page text', 'brand:600'],
            'nav-active-bg' => ['Current page background', 'brand:50'],
        ]],
        'buttons' => ['label' => 'Buttons & tabs', 'fields' => [
            'btn-bg' => ['Main button', 'brand:600'],
            'btn-hover' => ['Main button on hover', 'brand:700'],
            'btn-text' => ['Main button text', '#ffffff'],
            'btn2-border' => ['Second button border', 'gray:300'],
            'btn2-text' => ['Second button text', 'gray:900'],
        ]],
        'links' => ['label' => 'Links', 'fields' => [
            'link' => ['Link text', 'brand:600'],
            'link-hover' => ['Link text on hover', 'brand:700'],
        ]],
        'footer' => ['label' => 'Footer', 'fields' => [
            'footer-bg' => ['Background', 'gray:900'],
            'footer-text' => ['Text and links', 'gray:400'],
            'footer-heading' => ['Headings', '#ffffff'],
            'footer-link-hover' => ['Link on hover', '#ffffff'],
            'footer-border' => ['Dividers and outlines', 'gray:800'],
        ]],
    ];

    /** Text/background pairs that must stay readable (WCAG AA) when either side is customised. */
    public const CONTRAST_PAIRS = [
        ['btn-text', 'btn-bg', 'Main button text on the main button'],
        ['btn-text', 'btn-hover', 'Main button text on its hover color'],
        ['nav-text', 'header-bg', 'Navigation links on the header'],
        ['nav-hover', 'nav-hover-bg', 'Navigation link on hover'],
        ['nav-active', 'nav-active-bg', 'Current page text on its background'],
        ['btn2-text', '#ffffff', 'Second button text'],
        ['link', '#ffffff', 'Links on the page'],
        ['footer-text', 'footer-bg', 'Footer text on the footer'],
        ['footer-heading', 'footer-bg', 'Footer headings on the footer'],
        ['footer-link-hover', 'footer-bg', 'Footer links on hover'],
        ['link-hover', '#ffffff', 'Links on hover'],
    ];

    /** Approximate sRGB of the neutral shades, only for contrast maths (CSS uses the exact oklch). */
    private const GRAY_HEX = [50 => '#f9fafb', 100 => '#f3f4f6', 200 => '#e5e7eb', 300 => '#d1d5db', 400 => '#9ca3af',
        500 => '#6b7280', 600 => '#4b5563', 700 => '#374151', 800 => '#1f2937', 900 => '#111827', 950 => '#030712'];

    /** The built-in default: the site's original look. */
    public const DEFAULT = ['name' => 'BDB Red (default)', 'accent' => '#dc2626', 'neutral' => 'gray'];

    /** Designer presets, each checked for button contrast. */
    public const PRESETS = [
        ['name' => 'Ocean Blue', 'accent' => '#1d4ed8', 'neutral' => 'slate'],
        ['name' => 'Royal Indigo', 'accent' => '#4f46e5', 'neutral' => 'slate'],
        ['name' => 'Emerald', 'accent' => '#047857', 'neutral' => 'zinc'],
        ['name' => 'Deep Teal', 'accent' => '#0f766e', 'neutral' => 'zinc'],
        ['name' => 'Copper', 'accent' => '#b45309', 'neutral' => 'stone'],
        ['name' => 'Graphite', 'accent' => '#1f2937', 'neutral' => 'neutral'],
    ];

    /**
     * How far each accent shade is mixed toward white (below 600) or black (above 600).
     * 600 is the chosen colour itself; 700 is the hover state.
     */
    private const MIX = [
        50 => ['#ffffff', 0.94], 100 => ['#ffffff', 0.88], 200 => ['#ffffff', 0.76], 300 => ['#ffffff', 0.58],
        400 => ['#ffffff', 0.34], 500 => ['#ffffff', 0.15], 600 => null,
        700 => ['#000000', 0.18], 800 => ['#000000', 0.34], 900 => ['#000000', 0.46], 950 => ['#000000', 0.66],
    ];

    protected $fillable = ['name', 'accent', 'neutral', 'components', 'is_locked', 'is_active'];

    protected function casts(): array
    {
        return ['is_locked' => 'boolean', 'is_active' => 'boolean', 'components' => 'array'];
    }

    protected static function booted(): void
    {
        $flush = fn () => Cache::forget(self::CACHE_KEY);
        static::saved($flush);
        static::deleted($flush);
    }

    /** The theme shown on the site; null means the compiled default. */
    public static function active(): ?self
    {
        try {
            $attributes = Cache::rememberForever(self::CACHE_KEY,
                fn () => static::where('is_active', true)->first()?->getAttributes() ?? []);
        } catch (\Throwable) {
            return null; // table missing (migrations pending) — never break the public site
        }

        // Raw attributes (components still JSON), so the cast decodes them as on a fresh query.
        return $attributes ? (new static)->newFromBuilder($attributes) : null;
    }

    /**
     * The theme for this site request: an admin may preview any theme with ?theme_preview=ID,
     * everyone else sees the active one.
     */
    public static function forRequest(Request $request): ?self
    {
        return static::previewing($request) ?? static::active();
    }

    /**
     * The theme an admin is previewing. ?theme_preview=ID starts it (kept in the session so it
     * follows the admin across pages), ?theme_preview=off ends it. Visitors never see previews.
     */
    public static function previewing(Request $request): ?self
    {
        if (! $request->user()?->is_admin || ! $request->hasSession()) {
            return null;
        }

        $param = $request->query('theme_preview');
        if ($param === 'off') {
            $request->session()->forget('theme_preview');

            return null;
        }
        if (is_scalar($param) && ctype_digit((string) $param)) {
            $request->session()->put('theme_preview', (int) $param);
        }

        $id = $request->session()->get('theme_preview');
        $theme = $id ? static::find($id) : null;
        if ($id && ! $theme) {
            $request->session()->forget('theme_preview'); // theme was deleted
        }

        return $theme;
    }

    /** Make this the site's theme (and the only active one). */
    public function activate(): void
    {
        static::whereKeyNot($this->getKey())->where('is_active', true)->update(['is_active' => false]);
        $this->update(['is_active' => true]);
        Cache::forget(self::CACHE_KEY);
    }

    /** Accent shades 50–950 as #rrggbb, derived from the chosen colour. */
    public function accentScale(): array
    {
        $scale = [];
        foreach (self::MIX as $shade => $mix) {
            $scale[$shade] = $mix ? self::mix($this->accent, $mix[0], $mix[1]) : strtolower($this->accent);
        }

        return $scale;
    }

    /** CSS overriding the site's colour variables; empty for the built-in default. */
    public function css(): string
    {
        if ($this->is_locked) {
            return '';
        }

        $vars = [];
        foreach ($this->accentScale() as $shade => $hex) {
            $vars[] = "--color-brand-{$shade}:{$hex}";
        }
        if ($this->neutral !== 'gray' && isset(self::NEUTRALS[$this->neutral])) {
            foreach (self::NEUTRALS[$this->neutral]['scale'] as $i => $oklch) {
                $vars[] = '--color-gray-'.self::SHADES[$i].":oklch({$oklch})";
            }
        }

        foreach ($this->customComponents() as $key => $hex) {
            $vars[] = "--site-{$key}:{$hex}";
        }

        return ':root{'.implode(';', $vars).'}';
    }

    /** Only the valid, known component colours this theme changes. */
    public function customComponents(): array
    {
        $known = self::componentKeys();

        return collect((array) $this->components)
            ->filter(fn ($hex, $key) => in_array($key, $known, true) && is_string($hex) && preg_match('/^#[0-9a-f]{6}$/', $hex))
            ->all();
    }

    public static function componentKeys(): array
    {
        return collect(self::COMPONENTS)->flatMap(fn ($group) => array_keys($group['fields']))->all();
    }

    /** The colour a component shows, as CSS (exact neutral oklch, or #hex). */
    public function componentColor(string $key): string
    {
        return $this->customComponents()[$key] ?? $this->resolveDefault(self::componentDefault($key), exact: true);
    }

    /** The whole palette (accent, greys, every component) as inline custom properties, for admin previews. */
    public function previewStyle(): string
    {
        $scale = self::NEUTRALS[$this->neutral]['scale'] ?? self::NEUTRALS['gray']['scale'];

        return collect($this->accentScale())->map(fn ($hex, $shade) => "--color-brand-{$shade}:{$hex}")
            ->merge(collect(self::SHADES)->map(fn ($shade, $i) => "--color-gray-{$shade}:oklch({$scale[$i]})"))
            ->merge(collect(self::componentKeys())->map(fn ($key) => "--site-{$key}:".$this->componentColor($key)))
            ->implode(';');
    }

    /** Unreadable pairs (label => ratio) among the pairs this theme customises. */
    public function contrastProblems(): array
    {
        $custom = $this->customComponents();
        $problems = [];
        foreach (self::CONTRAST_PAIRS as [$text, $bg, $label]) {
            if (! isset($custom[$text]) && ! isset($custom[$bg])) {
                continue; // automatic colours are designed to work together
            }
            $ratio = self::contrast($this->hexFor($text), $this->hexFor($bg));
            if ($ratio < self::MIN_CONTRAST) {
                $problems[$label] = $ratio;
            }
        }

        return $problems;
    }

    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return round((max($la, $lb) + 0.05) / (min($la, $lb) + 0.05), 2);
    }

    private static function componentDefault(string $key): string
    {
        foreach (self::COMPONENTS as $group) {
            if (isset($group['fields'][$key])) {
                return $group['fields'][$key][1];
            }
        }

        return '#ffffff';
    }

    /** A component key (or a literal #hex) as #hex, for contrast maths. */
    private function hexFor(string $keyOrHex): string
    {
        if (str_starts_with($keyOrHex, '#')) {
            return $keyOrHex;
        }

        return $this->customComponents()[$keyOrHex] ?? $this->resolveDefault(self::componentDefault($keyOrHex), exact: false);
    }

    private function resolveDefault(string $default, bool $exact): string
    {
        if (str_starts_with($default, '#')) {
            return $default;
        }
        [$family, $shade] = explode(':', $default);
        if ($family === 'brand') {
            return $this->accentScale()[(int) $shade];
        }
        if (! $exact) {
            return self::GRAY_HEX[(int) $shade];
        }
        $scale = self::NEUTRALS[$this->neutral]['scale'] ?? self::NEUTRALS['gray']['scale'];

        return 'oklch('.$scale[array_search((int) $shade, self::SHADES, true)].')';
    }

    /** Contrast ratio of white text on the accent colour (1–21). */
    public function accentContrast(): float
    {
        return self::contrastWithWhite($this->accent);
    }

    public static function contrastWithWhite(string $hex): float
    {
        return self::contrast($hex, '#ffffff');
    }

    /**
     * Make sure the locked default exists and something is active. The designer presets are added
     * only to an empty table, so presets an admin deleted stay deleted (safe to run on production).
     */
    public static function installPresets(): void
    {
        $fresh = ! static::exists();
        $default = static::where('is_locked', true)->first();
        if (! $default) {
            $default = static::firstOrNew(['name' => self::DEFAULT['name']]);
            $default->forceFill(self::DEFAULT + ['is_locked' => true, 'components' => null])->save();
        }

        if ($fresh) {
            foreach (self::PRESETS as $preset) {
                static::firstOrCreate(['name' => $preset['name']], $preset);
            }
        }

        if (! static::where('is_active', true)->exists()) {
            $default->activate();
        }
    }

    private static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(function (int $c) {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /** Mix $hex toward $with by $amount (0 = $hex, 1 = $with). */
    private static function mix(string $hex, string $with, float $amount): string
    {
        $a = self::rgb($hex);
        $b = self::rgb($with);

        return '#'.implode('', array_map(
            fn ($i) => sprintf('%02x', (int) round($a[$i] + ($b[$i] - $a[$i]) * $amount)),
            [0, 1, 2],
        ));
    }

    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
