<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CatalogClient;
use App\Models\CatalogLoad;
use App\Models\CatalogProduct;
use App\Models\CatalogSetting;
use App\Services\Catalog\CatalogPuller;
use App\Services\Seo\SeoFiles;
use Illuminate\Http\Request;

/** Admin → AI Sales & Support Team → Product catalog: source (push / pull), loads, products. */
class CatalogController extends Controller
{
    public function index(Request $request)
    {
        $search = is_string($q = $request->query('q')) ? mb_substr(trim($q), 0, 100) : '';
        $category = is_string($c = $request->query('category')) && isset(CatalogProduct::CATEGORIES[$c]) ? $c : null;
        $hidden = $request->boolean('hidden');

        $products = CatalogProduct::query()
            ->when(! $hidden, fn ($q) => $q->available())
            ->when($hidden, fn ($q) => $q->where(fn ($w) => $w->where('in_feed', false)->orWhere('is_active', false)))
            ->when($category, fn ($q) => $q->where('category', $category))
            // "!" escapes % and _ the same way in SQLite and MySQL (a backslash needs ESCAPE in SQLite).
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereRaw("sku LIKE ? ESCAPE '!'", [$like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%'])
                ->orWhereRaw("model LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("brand LIKE ? ESCAPE '!'", [$like])))
            ->orderBy('category')->orderBy('brand')->orderBy('model')
            ->paginate(50)->withQueryString();

        return view('admin.catalog.index', [
            'settings' => CatalogSetting::current(),
            'loads' => CatalogLoad::latest('id')->limit(10)->get(),
            'products' => $products,
            'counts' => CatalogProduct::available()->toBase()->selectRaw('category, count(*) as n')->groupBy('category')->pluck('n', 'category'),
            'hiddenCount' => CatalogProduct::where(fn ($w) => $w->where('in_feed', false)->orWhere('is_active', false))->count(),
            'clientCounts' => CatalogClient::toBase()->selectRaw('price_group, count(*) as n')->groupBy('price_group')->pluck('n', 'price_group'),
            'endpoint' => SeoFiles::publicUrl(route('api.catalog')),
            'search' => $search, 'category' => $category, 'hidden' => $hidden,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'source' => ['required', 'in:push,pull'],
            'pull_url' => ['nullable', 'required_if:source,pull', 'url:https', 'max:500'],
            'pull_username' => ['nullable', 'required_if:source,pull', 'string', 'max:100'],
            'pull_password' => ['nullable', 'string', 'min:8', 'max:200'],
            'max_age_hours' => ['required', 'integer', 'min:1', 'max:720'],
        ], ['pull_url.url' => 'The 1C address must start with https://.']);

        $settings = CatalogSetting::current();
        if ($data['source'] === 'pull' && blank($data['pull_password'] ?? null) && blank($settings->pull_password)) {
            return back()->withInput()->withErrors(['pull_password' => 'Enter the password of the 1C user.']);
        }

        $settings->fill([
            'source' => $data['source'],
            'pull_url' => $data['pull_url'] ?? null,
            'pull_username' => $data['pull_username'] ?? null,
            'max_age_hours' => (int) $data['max_age_hours'],
        ]);
        // An empty password field keeps the saved one.
        if (filled($data['pull_password'] ?? null)) {
            $settings->pull_password = $data['pull_password'];
        }
        $settings->save();

        $redirect = redirect()->route('admin.catalog.index')->with('success', 'Catalog settings saved.');

        return $settings->source === 'push' && ! $settings->token_hash
            ? $redirect->with('catalog_token', $settings->regenerateToken())
            : $redirect;
    }

    public function token()
    {
        return redirect()->route('admin.catalog.index')
            ->with('success', 'New push token created. The old one no longer works — update it in 1C.')
            ->with('catalog_token', CatalogSetting::current()->regenerateToken());
    }

    public function pull(CatalogPuller $puller)
    {
        if (CatalogSetting::current()->source !== 'pull') {
            return redirect()->route('admin.catalog.index')->with('error', 'Switch the source to pull first.');
        }

        @set_time_limit(CatalogPuller::TIMEOUT + 60);
        $result = $puller->pull('manual');
        if ($result === null) {
            return redirect()->route('admin.catalog.index')->with('error', 'Another catalog load is running — try again in a minute.');
        }

        $load = $result['load'];

        return redirect()->route('admin.catalog.index')->with(
            $load->status === 'ok' ? 'success' : 'error',
            $load->status === 'ok'
                ? "Loaded: {$load->accepted} products".($load->rejected_count ? ", {$load->rejected_count} rejected" : '').($load->hidden ? ", {$load->hidden} hidden" : '').'.'
                : 'Not loaded: '.$load->error,
        );
    }
}
