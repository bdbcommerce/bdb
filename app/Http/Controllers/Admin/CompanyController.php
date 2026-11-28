<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CatalogProduct;
use App\Models\Client;
use App\Models\CompanyItem;
use App\Models\CompanyLink;
use App\Models\OnecItem;
use App\Models\TelegramChat;
use App\Models\TelegramUser;
use App\Services\Companies\CompanyLookup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Companies: find or create by INN (our base → 1C → registry), the Telegram contacts and groups linked to a company,
 * and its two lists (usually orders / sales plan). Profile fields and people are edited in ClientController.
 */
class CompanyController extends Controller
{
    /** Companies → "Find by INN": ours, else from 1C / the registry (created), else a new one with the INN only. */
    public function lookup(Request $request, CompanyLookup $lookup)
    {
        $inn = Client::cleanInn((string) $request->input('inn'));
        $request->merge(['inn' => $inn]);
        $request->validate(['inn' => ['required', 'regex:/^(\d{9}|\d{12}|\d{14})$/']], ['inn.regex' => 'An INN is 9 digits (a sole trader: 12 or 14).']);
        $existed = Client::where('inn', $inn)->exists();
        $company = $lookup->find($inn);

        return redirect()->route('admin.clients.show', $company)->with('success', $existed ? 'This company is already in the base.'
            : 'Company added from '.(Client::SOURCES[$company->source] ?? $company->source).($company->source === 'local' ? ' (neither 1C nor the registry knows this INN — fill in the name).' : '.'));
    }

    /** "Update from 1C": asks 1C / the registry again. */
    public function refresh(Client $client, CompanyLookup $lookup)
    {
        try {
            $lookup->refresh($client);
        } catch (Throwable $e) {
            return back()->withErrors(['lookup' => $e->getMessage()]);
        }

        return back()->with('success', 'Updated from '.(Client::SOURCES[$client->source] ?? $client->source).'.');
    }

    /** Admin only: creates this company as a client in 1C (clients/newClient) — 1C keeps the card, so never automatic. */
    public function createInOneC(Client $client, CompanyLookup $lookup)
    {
        try {
            $lookup->createInOneC($client);
        } catch (Throwable $e) {
            return back()->withErrors(['lookup' => $e->getMessage()]);
        }

        return back()->with('success', 'Created in 1C'.($client->onec_code ? ' (client code '.$client->onec_code.')' : '').'.');
    }

    /** Companies → "Load companies from 1C" (crm/company, on demand). */
    public function importCompanies(CompanyLookup $lookup)
    {
        try {
            $n = $lookup->importCompanies();
        } catch (Throwable $e) {
            return back()->withErrors(['lookup' => $e->getMessage()]);
        }

        return back()->with('success', "From 1C: {$n['added']} companies added, {$n['updated']} updated; {$n['private']} private persons (no STIR) saved for matching contacts by phone; {$n['other']} without a usable number not loaded.");
    }

    /**
     * The progress window of "Load … from 1C" (admin/partials/onec-import): asks 1C for the whole list once and
     * answers with the row count; importStep then saves it IMPORT_STEP rows at a time.
     */
    public function importStart(string $what, CompanyLookup $lookup)
    {
        if (blank(config('services.onec.url')) || blank(config('services.onec.'.$what.'_path'))) {
            return response()->json(['message' => '1C is not set (ONEC_BASE_URL / ONEC_'.strtoupper($what).'_PATH).'], 422);
        }
        try {
            $total = $lookup->startImport($what);
        } catch (Throwable $e) {
            report($e);

            // Our own messages ("1C items: HTTP 500") are shown; a database or other error never is.
            return response()->json(['message' => get_class($e) === \RuntimeException::class ? $e->getMessage() : 'The list from 1C could not be kept. See the log.'], 422);
        }

        return response()->json(['total' => $total, 'step' => CompanyLookup::IMPORT_STEP]);
    }

    public function importStep(Request $request, string $what, CompanyLookup $lookup)
    {
        // Checked by hand: a validation error on an admin page is a redirect, and the window needs JSON.
        $offset = filter_var($request->input('offset'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000]]);
        if ($offset === false) {
            return response()->json(['message' => 'Wrong row number.'], 422);
        }
        try {
            return response()->json($lookup->importPart($what, $offset));
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => get_class($e) === \RuntimeException::class ? $e->getMessage() : 'Saving failed at row '.$offset.'. See the log.'], 422);
        }
    }

    /** Companies → "Load items from 1C" (crm/item, on demand). */
    public function importItems(CompanyLookup $lookup)
    {
        try {
            $n = $lookup->importItems();
        } catch (Throwable $e) {
            return back()->withErrors(['lookup' => $e->getMessage()]);
        }

        return back()->with('success', "Items from 1C: {$n['saved']} saved".($n['skipped'] ? ", {$n['skipped']} skipped (no id, code or name)" : '').'.');
    }

    /** "Purchases from 1C": sales-plan items the company bought move to "usually orders". */
    public function purchases(Client $client, CompanyLookup $lookup)
    {
        try {
            $rows = $lookup->purchases((string) $client->inn, now()->subYear()->toDateString());
        } catch (Throwable $e) {
            return back()->withErrors(['lookup' => $e->getMessage()]);
        }
        $moved = $lookup->moveBought($client, $rows, '1C purchase');

        return back()->with('success', count($rows).' purchases read from 1C. '.($moved ? 'Moved to usually orders: '.implode('; ', $moved).'.' : 'No sales-plan item among them.'));
    }

    /** Links a contact (Telegram ID, @username or phone) or a recorded group to the company — as a manager. */
    public function link(Request $request, Client $client, CompanyLookup $lookup)
    {
        $data = $request->validate([
            'contact' => ['nullable', 'string', 'max:64', 'required_without:group_chat_id'],
            'group_chat_id' => ['nullable', 'string', 'max:32', 'regex:/^-\d+$/', 'required_without:contact'],
        ], ['group_chat_id.regex' => 'Pick a group from the list.']);
        $name = $request->user()->name;

        if (filled($data['group_chat_id'] ?? null)) {
            // A group the bot is in (TelegramChat), or one known from its recorded conversations.
            abort_unless(TelegramChat::options('groups')->has($data['group_chat_id']), 422, 'Unknown group.');
            $lookup->link($client, null, $data['group_chat_id'], 'manager', $name);

            return back()->with('success', 'Group linked.');
        }
        $user = self::findContact((string) $data['contact']);
        if (! $user) {
            return back()->withInput()->withErrors(['contact' => 'No contact with this Telegram ID or @username, or no single contact with this verified phone.']);
        }
        $lookup->link($client, $user, null, 'manager', $name);

        return back()->with('success', ($user->displayName() ?? 'Contact').' linked.');
    }

    /** A contact's page → "Link company by INN" (manager link). */
    public function linkContact(Request $request, TelegramUser $telegramUser, CompanyLookup $lookup)
    {
        $inn = Client::cleanInn((string) $request->input('inn'));
        $request->merge(['inn' => $inn]);
        $request->validate(['inn' => ['required', 'regex:/^(\d{9}|\d{12}|\d{14})$/']], ['inn.regex' => 'An INN is 9 digits (a sole trader: 12 or 14).']);
        $company = $lookup->find($inn);
        $lookup->link($company, $telegramUser, null, 'manager', $request->user()->name);

        return back()->with('success', 'Linked to '.$company->name.'.');
    }

    /**
     * A private person (no company) → a client card in 1C. PLACEHOLDER: the 1C method is not given yet, so nothing is
     * sent; the button shows what will be sent. Replace with the real call when the method exists.
     */
    public function createPrivateInOneC(TelegramUser $telegramUser)
    {
        $details = collect(['name' => $telegramUser->name ?: $telegramUser->profile_name, 'phone' => $telegramUser->phone, 'telegram' => $telegramUser->username ? '@'.$telegramUser->username : $telegramUser->telegram_id])
            ->filter()->map(fn ($v, $k) => "{$k}: {$v}")->implode(', ');

        return back()->with('success', 'Demo only — not sent to 1C yet (the method for private clients is not connected). Would send: '.$details.'.');
    }

    /** An AI link becomes a manager's: from now on the company's price type applies to this contact. */
    public function confirmLink(Request $request, CompanyLink $link)
    {
        $link->update(['linked_by' => 'manager', 'linked_by_name' => $request->user()->name]);

        return back()->with('success', 'Link confirmed: the company\'s price type applies now.');
    }

    public function unlink(CompanyLink $link)
    {
        $link->delete();

        return back()->with('success', 'Unlinked.');
    }

    public function addItem(Request $request, Client $client)
    {
        $data = $request->validate([
            'list' => ['required', Rule::in(array_keys(CompanyItem::LISTS))],
            'kind' => ['required', Rule::in(array_keys(CompanyItem::KINDS))],
            'value' => ['required', 'string', 'max:64'],
        ]);
        $value = trim($data['value']);
        $known = match ($data['kind']) {
            'category' => array_key_exists($value, CatalogProduct::CATEGORIES),
            'sku' => CatalogProduct::where('sku', $value)->exists(),
            'item1c' => ctype_digit($value) && OnecItem::where('code', (int) $value)->exists(),
            default => CatalogProduct::where('brand', $value)->exists(),
        };
        if (! $known) {
            return back()->withInput()->withErrors(['value' => 'Pick a '.mb_strtolower(CompanyItem::KINDS[$data['kind']]).' from the catalog list.']);
        }
        if (CompanyItem::where(['client_id' => $client->id, 'kind' => $data['kind'], 'value' => $value])->exists()) {
            return back()->withErrors(['value' => 'Already in a list of this company.']);
        }
        $client->items()->create(['list' => $data['list'], 'kind' => $data['kind'], 'value' => $value]);

        return back()->with('success', 'Added to '.mb_strtolower(CompanyItem::LISTS[$data['list']]).'.');
    }

    /** Sales plan → usually orders, by hand (logged). */
    public function moveItem(Request $request, CompanyItem $item)
    {
        abort_unless($item->list === 'plan', 422);
        $item->update(['list' => 'usual', 'moved_at' => now(), 'moved_by' => mb_substr($request->user()->name, 0, 120)]);

        return back()->with('success', $item->label().' moved to usually orders.');
    }

    public function removeItem(CompanyItem $item)
    {
        $item->delete();

        return back()->with('success', 'Removed.');
    }

    /**
     * A Telegram ID, @username or phone → the contact. Digits are a Telegram ID first; a phone (with + or 998) counts only
     * when it is verified and belongs to exactly one contact — a typed phone could be anyone's (the link gives prices).
     */
    public static function findContact(string $q): ?TelegramUser
    {
        $q = trim($q);
        if (str_starts_with($q, '@')) {
            return TelegramUser::where('username', ltrim($q, '@'))->first();
        }
        if (ctype_digit($q) && ($user = TelegramUser::where('telegram_id', $q)->first())) {
            return $user;
        }
        if ((str_starts_with($q, '+') || str_starts_with(preg_replace('/\D/', '', $q), '998')) && ($phone = TelegramUser::normalizePhone($q))) {
            $matches = TelegramUser::where('phone', $phone)->whereNotNull('phone_verified_at')->limit(2)->get();

            return $matches->count() === 1 ? $matches->first() : null;
        }

        return ctype_digit($q) ? null : TelegramUser::where('username', $q)->first();
    }
}
