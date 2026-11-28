<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OnecItem;
use App\Services\ChatAssistant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Conversations → Products: the items loaded from 1C (OnecItem) and, next to the 1C fields, what the AI team needs
 * to know about each (brand, model, category, summary, specs, compatibility, customer words, a sales note).
 * Staff type it or the AI drafts it from the 1C texts; AI drafts stay "AI draft — check it" until someone saves.
 */
class ProductController extends Controller
{
    /** Products one "Fill the next …" click sends to the AI (one call each). */
    public const BATCH = 8;

    /** A batch stops starting new products after this many seconds. */
    public const BATCH_SECONDS = 100;

    /** Products the AI failed on: [id => unix time], skipped by the batch for FAILED_HOURS. */
    public const FAILED_KEY = 'products:ai-failed';

    public const FAILED_HOURS = 12;

    /** This many failures with no success = the AI is down: the batch stops and sets nothing aside. */
    public const DOWN_AFTER = 3;

    public function index(Request $request)
    {
        // ?q[]=x must not crash the page.
        $text = fn (string $key) => is_string($request->query($key)) ? $request->query($key) : '';
        $search = mb_substr(trim($text('q')), 0, 100);
        $status = $text('info');
        $category = $text('category');
        $parked = array_keys(self::parked());

        $products = OnecItem::query()->search($search)
            ->when($status === 'empty', fn ($q) => $q->withoutInfo())
            ->when(isset(OnecItem::STATUSES[$status]), fn ($q) => $q->where('info_status', $status))
            ->when($status === 'hidden', fn ($q) => $q->where('ai_use', false))
            ->when($category === 'none', fn ($q) => $q->whereNull('ai_category'))
            ->when(isset(OnecItem::categories()[$category]), fn ($q) => $q->where('ai_category', $category))
            ->orderBy('name')->paginate(50)->withQueryString();

        return view('admin.products.index', [
            'products' => $products, 'search' => $search, 'status' => $status, 'category' => $category,
            'counts' => [
                'all' => OnecItem::count(),
                'empty' => OnecItem::withoutInfo()->count(),
                'to_describe' => OnecItem::toDescribe()->whereNotIn('id', $parked)->count(),
                'parked' => count($parked),
                'ai' => OnecItem::where('info_status', 'ai')->count(),
                'checked' => OnecItem::where('info_status', 'checked')->count(),
                'hidden' => OnecItem::where('ai_use', false)->count(),
            ],
            'lastLoad' => OnecItem::max('updated_at'),
        ]);
    }

    public function edit(OnecItem $product)
    {
        return view('admin.products.edit', ['product' => $product]);
    }

    /** Staff save = the information is checked (or, with nothing typed, empty again). */
    public function update(Request $request, OnecItem $product)
    {
        $rules = ['sales_note' => ['nullable', 'string', 'max:1000'], 'ai_use' => ['nullable', 'boolean']];
        foreach (OnecItem::AI_FIELDS as $field => [, $max]) {
            $rules[$field] = ['nullable', 'string', 'max:'.$max];
        }
        $rules['ai_category'][] = Rule::in(array_keys(OnecItem::categories()));
        // Browsers count a line break as one character (maxlength) but send two: count them the same way.
        $request->merge(array_map(fn ($v) => str_replace("\r\n", "\n", $v), array_filter($request->only(array_keys($rules)), 'is_string')));
        $data = $request->validate($rules);
        $was = $product->info_status;

        // A field missing from the request is saved empty, like one the admin cleared.
        foreach ([...array_keys(OnecItem::AI_FIELDS), 'sales_note'] as $field) {
            $product->$field = OnecItem::cleanText($field, $data[$field] ?? null);
        }
        $product->ai_use = $request->boolean('ai_use');
        $filled = count($product->emptyAiFields()) < count(OnecItem::AI_FIELDS) || filled($product->sales_note);
        if (! $filled && $was === 'ai') {
            // The AI looked and found nothing: saving it empty must not send it to the AI again.
            $product->save();

            return redirect()->route('admin.products.edit', $product)->with('success', 'Saved. Nothing is filled in yet.');
        }
        $product->fill(['info_status' => $filled ? 'checked' : null, 'info_by' => $filled ? $request->user()->name : null, 'info_at' => $filled ? now() : null])->save();

        return redirect()->route('admin.products.edit', $product)->with('success', $filled ? 'Saved and marked as checked.' : 'Saved. Nothing is filled in yet.');
    }

    /** "Fill with AI" on one product: only the empty fields; what staff typed is never replaced. */
    public function aiFill(OnecItem $product, ChatAssistant $assistant)
    {
        // Hidden items (made for one customer, internal) are not sent to an outside AI either.
        if (! $product->ai_use) {
            return back()->withErrors(['ai' => 'This product is hidden from the AI, so its texts are not sent to it. Tick "The AI may offer this product" and save first.']);
        }
        $result = $this->draft($product, $assistant);
        if ($result === null) {
            return back()->withErrors(['ai' => 'The AI did not answer: '.($assistant->lastError() ?: 'unknown reason').' Try again later (AI team → AI settings shows the models).']);
        }
        // Read after all: no longer set aside.
        if (isset(self::parked()[$product->id])) {
            Cache::put(self::FAILED_KEY, array_diff_key(self::parked(), [$product->id => 1]), now()->addHours(self::FAILED_HOURS));
        }

        return back()->with('success', $result
            ? 'The AI filled '.$result.' '.($result === 1 ? 'field' : 'fields').'. Check them and press Save.'
            : 'The AI found nothing more in the 1C texts for the empty fields.');
    }

    /**
     * "Fill the next N with AI": products nobody has described yet (hidden ones never), one AI call each.
     * A product the AI could not read while it read others is set aside for FAILED_HOURS (parked), so it cannot
     * hold up the ones after it. When nothing succeeds the AI itself is down: stop after DOWN_AFTER, park nothing.
     */
    public function aiFillBatch(ChatAssistant $assistant)
    {
        $lock = Cache::lock('products:ai-fill', 300);
        if (! $lock->get()) {
            return back()->withErrors(['ai' => 'Another AI fill is still running. Wait a minute.']);
        }
        try {
            set_time_limit(300);
            $started = microtime(true);
            $parked = self::parked();
            $failed = [];
            $done = 0;
            foreach (OnecItem::toDescribe()->whereNotIn('id', array_keys($parked))->orderBy('name')->limit(self::BATCH)->get() as $product) {
                // Wall clock: the time limit does not count waiting for the AI on Linux.
                if (microtime(true) - $started > self::BATCH_SECONDS) {
                    break;
                }
                if ($this->draft($product, $assistant) !== null) {
                    $done++;

                    continue;
                }
                $failed[$product->id] = now()->timestamp;
                if (! $done && count($failed) >= self::DOWN_AFTER) {
                    break;
                }
            }
            if ($done && $failed) {
                Cache::put(self::FAILED_KEY, $parked + $failed, now()->addHours(self::FAILED_HOURS));
            }
        } finally {
            $lock->release();
        }

        $left = OnecItem::toDescribe()->whereNotIn('id', array_keys(self::parked()))->count();
        $said = "The AI described {$done} ".($done === 1 ? 'product' : 'products').". {$left} more are waiting.";
        if ($failed) {
            $n = count($failed);

            return back()->withErrors(['ai' => $done
                ? $said." It could not read {$n} ".($n === 1 ? 'product' : 'products').' — set aside for '.self::FAILED_HOURS.' hours; open one and press "Fill empty fields with AI" to try it sooner. Reason: '.($assistant->lastError() ?: 'unknown')
                : 'The AI is not answering, nothing was changed: '.($assistant->lastError() ?: 'unknown reason').' Try again later (AI team → AI settings shows the models).']);
        }

        return back()->with('success', $done || $left ? $said : 'Every product the AI may read already has information.');
    }

    /** @return array<int, int> products the AI could not read lately: [id => unix time] */
    public static function parked(): array
    {
        return array_filter((array) Cache::get(self::FAILED_KEY, []), fn ($at) => $at > now()->subHours(self::FAILED_HOURS)->timestamp);
    }

    /** @return int|null fields filled (0 = nothing found), null = the AI failed */
    private function draft(OnecItem $product, ChatAssistant $assistant): ?int
    {
        $empty = $product->emptyAiFields();
        if (! $empty) {
            return 0;
        }
        $info = $assistant->productInfo($product->onecFacts(), OnecItem::categories());
        if ($info === null) {
            return null;
        }
        $filled = 0;
        foreach ($empty as $field) {
            $value = OnecItem::cleanText($field, mb_substr((string) ($info[$field] ?? ''), 0, OnecItem::AI_FIELDS[$field][1]));
            if ($value !== null) {
                $product->$field = $value;
                $filled++;
            }
        }
        // A checked product stays checked only if the AI added nothing new; "ai" also marks "looked at, nothing found".
        if ($filled || $product->info_status === null) {
            $product->fill(['info_status' => 'ai', 'info_by' => $assistant->lastModel()?->name ?: 'AI', 'info_at' => now()]);
        }
        $product->save();

        return $filled;
    }
}
