<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientPerson;
use App\Models\TelegramUser;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Client portraits: companies and the people we talk to there (with their part in decisions and status). */
class ClientController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('q', ''));
        $status = array_key_exists((string) $request->query('status'), Client::STATUSES) ? (string) $request->query('status') : null;

        return view('admin.clients.index', [
            'clients' => Client::query()->with('people.telegramUser')
                ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')
                    ->orWhere('inn', 'like', '%'.addcslashes($search, '%_\\').'%')
                    ->orWhereHas('people', fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))))
                ->when($status, fn ($q) => $q->where('status', $status))
                ->orderBy('name')->paginate(20)->withQueryString(),
            'search' => $search,
            'status' => $status,
            'unassigned' => ClientPerson::whereNull('client_id')->count(),
        ]);
    }

    public function create()
    {
        return view('admin.clients.form', ['client' => new Client(['status' => 'prospect'])]);
    }

    public function store(Request $request)
    {
        $client = Client::create($this->validated($request));

        return redirect()->route('admin.clients.show', $client)->with('success', 'Company added. Add the people you talk to there.');
    }

    public function show(Client $client)
    {
        return view('admin.clients.show', ['client' => $client->load('people.telegramUser', 'people.previous.client', 'people.next.client')]);
    }

    public function edit(Client $client)
    {
        return view('admin.clients.form', ['client' => $client]);
    }

    public function update(Request $request, Client $client)
    {
        $client->update($this->validated($request));

        return redirect()->route('admin.clients.show', $client)->with('success', 'Company saved.');
    }

    public function destroy(Client $client)
    {
        $client->delete(); // its people stay, without a company

        return redirect()->route('admin.clients.index')->with('success', 'Company deleted; its people are kept without a company.');
    }

    private function validated(Request $request): array
    {
        foreach (['installed', 'consumables', 'notes'] as $k) {
            if ($request->has($k)) {
                $request->merge([$k => str_replace(["\r\n", "\r"], "\n", (string) $request->input($k))]);
            }
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'inn' => ['nullable', 'string', 'max:20', 'regex:/^[0-9 ]+$/'],
            'industry' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:80'],
            'size' => ['nullable', 'string', 'max:150'],
            'status' => ['required', Rule::in(array_keys(Client::STATUSES))],
            'since' => ['nullable', 'string', 'max:20'],
            'owner' => ['nullable', 'string', 'max:100'],
            'installed' => ['nullable', 'string', 'max:3000'],
            'consumables' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['inn.regex' => 'The INN is digits only.']);
    }

    /** Telegram user → the prefilled person form (the AI collected these details; a manager confirms them). */
    public static function suggestion(TelegramUser $user): array
    {
        $state = (array) ($user->conversations()->whereNotNull('sales_state')->latest('id')->value('sales_state') ?? []);
        $state = is_string($state) ? (json_decode($state, true) ?: []) : $state;

        return array_filter([
            'telegram_user_id' => $user->id,
            'name' => $user->name ?: $user->profile_name,
            'phone' => $user->phone,
            'company' => $user->company ?: ($state['customer']['company'] ?? null),
            'city' => $state['customer']['city'] ?? null,
            'industry' => $state['business_type'] ?? null,
        ]);
    }
}
