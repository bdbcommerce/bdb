<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientPerson;
use App\Models\TelegramUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** People of client companies; "moved to another company" keeps the history (a new card, the old one becomes "left"). */
class ClientPersonController extends Controller
{
    public function create(Request $request)
    {
        $person = new ClientPerson(['status' => 'active', 'client_id' => $request->integer('client') ?: null]);
        $suggested = null;
        if ($request->integer('telegram_user') && ($user = TelegramUser::find($request->integer('telegram_user')))) {
            $suggested = ClientController::suggestion($user);
            $person->fill(array_intersect_key($suggested, array_flip(['telegram_user_id', 'name', 'phone'])));
            $person->client_id ??= isset($suggested['company']) ? Client::where('name', $suggested['company'])->value('id') : null;
        }

        return view('admin.clients.person', ['person' => $person, 'clients' => Client::orderBy('name')->get(['id', 'name']), 'suggested' => $suggested]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $person = DB::transaction(function () use ($data, $request) {
            // "New company" typed in the form (e.g. from the AI's suggestion) is created with the person.
            if (empty($data['client_id']) && filled($request->input('new_company'))) {
                $data['client_id'] = Client::create(['name' => mb_substr(trim((string) $request->input('new_company')), 0, 150), 'status' => 'prospect',
                    'city' => mb_substr((string) $request->input('new_city'), 0, 80) ?: null, 'industry' => mb_substr((string) $request->input('new_industry'), 0, 100) ?: null])->id;
            }

            return ClientPerson::create($data);
        });

        return $this->back($person, 'Person added.');
    }

    public function edit(ClientPerson $clientPerson)
    {
        return view('admin.clients.person', ['person' => $clientPerson, 'clients' => Client::orderBy('name')->get(['id', 'name']), 'suggested' => null]);
    }

    public function update(Request $request, ClientPerson $clientPerson)
    {
        $clientPerson->update($this->validated($request, $clientPerson));

        return $this->back($clientPerson, 'Person saved.');
    }

    public function destroy(ClientPerson $clientPerson)
    {
        $client = $clientPerson->client;
        $clientPerson->delete();

        return $client ? redirect()->route('admin.clients.show', $client)->with('success', 'Person deleted.') : redirect()->route('admin.clients.index')->with('success', 'Person deleted.');
    }

    /** The person moved to another company: the old card becomes "left", a new active card starts there (same Telegram link). */
    public function move(Request $request, ClientPerson $clientPerson)
    {
        $data = $request->validate([
            'client_id' => ['required', Rule::exists('clients', 'id'), Rule::notIn([$clientPerson->client_id])],
            'position' => ['nullable', 'string', 'max:100'],
        ], ['client_id.not_in' => 'Choose a different company.']);
        $new = DB::transaction(function () use ($clientPerson, $data) {
            $clientPerson->update(['status' => 'left']);

            return ClientPerson::create(['client_id' => $data['client_id'], 'previous_id' => $clientPerson->id, 'telegram_user_id' => $clientPerson->telegram_user_id,
                'name' => $clientPerson->name, 'position' => $data['position'] ?? null, 'style' => $clientPerson->style, 'phone' => $clientPerson->phone, 'status' => 'active']);
        });

        return redirect()->route('admin.clients.show', $new->client_id)->with('success', "{$new->name} moved here; the earlier card is kept as \"left\".");
    }

    private function back(ClientPerson $person, string $message)
    {
        return $person->client_id
            ? redirect()->route('admin.clients.show', $person->client_id)->with('success', $message)
            : redirect()->route('admin.clients.index')->with('success', $message);
    }

    private function validated(Request $request, ?ClientPerson $person = null): array
    {
        if ($request->has('notes')) {
            $request->merge(['notes' => str_replace(["\r\n", "\r"], "\n", (string) $request->input('notes'))]);
        }
        $telegramId = trim((string) $request->input('telegram_id'));
        $request->merge(['telegram_user_id' => $telegramId !== '' ? TelegramUser::where('telegram_id', $telegramId)->value('id') ?? 0 : null]);

        return $request->validate([
            'client_id' => ['nullable', Rule::exists('clients', 'id')],
            'telegram_user_id' => ['nullable', 'integer', Rule::exists('telegram_users', 'id')],
            'name' => ['required', 'string', 'max:100'],
            'position' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:100'],
            'part' => ['nullable', Rule::in(array_keys(ClientPerson::PARTS))],
            'status' => ['required', Rule::in(array_keys(ClientPerson::STATUSES))],
            'style' => ['nullable', 'string', 'max:300'],
            'phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['telegram_user_id.exists' => 'No Telegram customer has this Telegram ID (they must have written to the bot or the Premium account).']);
    }
}
