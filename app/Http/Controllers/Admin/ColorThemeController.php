<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ColorTheme;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Site colour themes: pick the active one, preview, create and edit custom sets.
 * The locked built-in default (original BDB red) can always be switched back to.
 */
class ColorThemeController extends Controller
{
    public function index()
    {
        ColorTheme::installPresets(); // first visit (or after a fresh deploy): make sure the default exists

        return view('admin.color-themes.index', [
            'themes' => ColorTheme::orderByDesc('is_locked')->orderBy('id')->get(),
        ]);
    }

    public function create(Request $request)
    {
        // "Duplicate" pre-fills the form from an existing theme.
        $source = $request->filled('from') ? ColorTheme::find($request->integer('from')) : null;

        return $this->form(new ColorTheme([
            'name' => $source ? mb_substr($source->name, 0, 55).' copy' : '',
            'accent' => $source->accent ?? ColorTheme::DEFAULT['accent'],
            'neutral' => $source->neutral ?? 'gray',
            'components' => $source?->customComponents() ?? [],
        ]));
    }

    public function store(Request $request)
    {
        $theme = ColorTheme::create($this->validated($request));

        if ($request->boolean('activate')) {
            $theme->activate();
        }

        return redirect()->route('admin.color-themes.index')
            ->with('success', $theme->is_active ? "“{$theme->name}” is now live on the site." : "“{$theme->name}” saved.");
    }

    public function edit(ColorTheme $colorTheme)
    {
        if ($colorTheme->is_locked) {
            return redirect()->route('admin.color-themes.create', ['from' => $colorTheme->id])
                ->with('success', 'The default theme cannot be changed — edit this copy instead.');
        }

        return $this->form($colorTheme);
    }

    public function update(Request $request, ColorTheme $colorTheme)
    {
        abort_if($colorTheme->is_locked, 403, 'The default theme cannot be changed.');

        $colorTheme->update($this->validated($request, $colorTheme));

        if ($request->boolean('activate')) {
            $colorTheme->activate();
        }

        return redirect()->route('admin.color-themes.index')
            ->with('success', $colorTheme->is_active ? "“{$colorTheme->name}” saved — the site uses it now." : "“{$colorTheme->name}” saved.");
    }

    public function activate(ColorTheme $colorTheme)
    {
        $colorTheme->activate();

        return redirect()->route('admin.color-themes.index')->with('success', "“{$colorTheme->name}” is now live on the site.");
    }

    public function destroy(ColorTheme $colorTheme)
    {
        if ($colorTheme->is_locked) {
            return back()->withErrors(['theme' => 'The default theme cannot be deleted.']);
        }
        if ($colorTheme->is_active) {
            return back()->withErrors(['theme' => 'This theme is live on the site. Switch to another theme before deleting it.']);
        }

        $colorTheme->delete();

        return redirect()->route('admin.color-themes.index')->with('success', 'Theme deleted.');
    }

    private function form(ColorTheme $theme)
    {
        return view('admin.color-themes.form', [
            'theme' => $theme,
            'neutrals' => ColorTheme::NEUTRALS,
            'shades' => ColorTheme::SHADES,
            'groups' => ColorTheme::COMPONENTS,
            'pairs' => ColorTheme::CONTRAST_PAIRS,
        ]);
    }

    private function validated(Request $request, ?ColorTheme $theme = null): array
    {
        // Normalise: lower-case hex; empty / unknown component fields mean "Auto" and are dropped.
        $components = collect((array) $request->input('components'))
            ->only(ColorTheme::componentKeys())
            ->map(fn ($v) => $v === null || is_scalar($v) ? strtolower(trim((string) $v)) : 'invalid') // arrays etc. fail validation
            ->filter(fn ($v) => $v !== '')
            ->all();
        $request->merge(['accent' => strtolower(trim((string) $request->input('accent'))), 'components' => $components]);

        $data = $request->validate([
            'components' => ['array'],
            'components.*' => ['regex:/^#[0-9a-f]{6}$/'],
            'name' => ['required', 'string', 'max:60', Rule::unique('color_themes', 'name')->ignore($theme?->id)],
            'accent' => ['required', 'regex:/^#[0-9a-f]{6}$/', function ($attribute, $value, $fail) {
                $ratio = ColorTheme::contrastWithWhite($value);
                if ($ratio < ColorTheme::MIN_CONTRAST) {
                    $fail("White button text on this accent has a contrast of {$ratio}:1 — it needs at least "
                        .ColorTheme::MIN_CONTRAST.':1 to stay readable. Choose a darker colour.');
                }
            }],
            'neutral' => ['required', Rule::in(array_keys(ColorTheme::NEUTRALS))],
        ], [
            'accent.regex' => 'The accent must be a colour code like #1d4ed8.',
            'components.*.regex' => 'Each component colour must be a code like #1d4ed8 (or empty for Auto).',
        ]);

        // Customised text/background pairs must stay readable.
        $problems = (new ColorTheme($data))->contrastProblems();
        if ($problems) {
            throw ValidationException::withMessages(['components' => collect($problems)
                ->map(fn ($ratio, $label) => "{$label}: contrast {$ratio}:1, needs at least ".ColorTheme::MIN_CONTRAST.':1.')
                ->values()->all()]);
        }

        return $data;
    }
}
