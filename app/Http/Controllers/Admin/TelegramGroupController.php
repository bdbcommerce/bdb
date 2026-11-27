<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\Client;
use App\Models\CompanyLink;
use App\Models\InvoiceParserSetting;
use App\Models\TelegramChat;
use App\Services\Companies\CompanyLookup;
use App\Services\Telegram\TelegramApi;
use Illuminate\Http\Request;

/**
 * Conversations → Telegram groups: every group the bot is in or was in (TelegramChat), what each is used for,
 * its companies and the people who wrote there; recording on/off and "leave the group".
 */
class TelegramGroupController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('q', ''));
        $groups = TelegramChat::whereIn('type', TelegramChat::GROUP_TYPES)
            // ESCAPE: SQLite has no default escape character for LIKE.
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->whereRaw("title LIKE ? ESCAPE '\\'", ['%'.addcslashes($search, '%_\\').'%'])
                ->orWhereRaw("chat_id LIKE ? ESCAPE '\\'", ['%'.addcslashes($search, '%_\\').'%'])))
            ->orderByDesc('last_seen_at')->paginate(50)->withQueryString();
        $ids = $groups->pluck('chat_id');
        $links = CompanyLink::whereIn('group_chat_id', $ids)->selectRaw('group_chat_id, count(*) as n')->groupBy('group_chat_id')->pluck('n', 'group_chat_id');
        $sessions = ChatConversation::where('channel', 'group')->whereIn('external_id', $ids)
            ->selectRaw('external_id, count(*) as n, sum(message_count) as messages, max(updated_at) as last')->groupBy('external_id')->get()->keyBy('external_id');

        return view('admin.telegram-groups.index', compact('groups', 'links', 'sessions', 'search'));
    }

    public function show(TelegramChat $telegramGroup)
    {
        abort_unless($telegramGroup->isGroup(), 404);

        return view('admin.telegram-groups.show', [
            'group' => $telegramGroup,
            'roles' => $telegramGroup->roles(),
            'links' => CompanyLink::where('group_chat_id', $telegramGroup->chat_id)->with('client')->get(),
            'companies' => Client::orderBy('name')->limit(1000)->get(['id', 'name', 'inn']),
            'members' => $telegramGroup->members(),
            'conversations' => $telegramGroup->conversations()->orderByDesc('id')->limit(20)->get(['id', 'title', 'message_count', 'created_at', 'updated_at']),
        ]);
    }

    public function recording(Request $request, TelegramChat $telegramGroup)
    {
        abort_unless($telegramGroup->isGroup(), 404);
        $on = $request->boolean('is_recorded');
        $telegramGroup->update(['is_recorded' => $on]);

        return back()->with('success', $on ? 'New messages of this group are recorded again.' : 'New messages of this group are no longer recorded (what is stored stays).');
    }

    /** Make this group the invoice source / invoice chat, or take the role away (same rules as Invoice parser → Settings). */
    public function role(Request $request, TelegramChat $telegramGroup)
    {
        abort_unless($telegramGroup->isGroup(), 404);
        $data = $request->validate(['role' => ['required', 'in:'.implode(',', array_keys(TelegramChat::SETTABLE_ROLES))], 'on' => ['required', 'boolean']]);
        $field = TelegramChat::SETTABLE_ROLES[$data['role']];
        $settings = InvoiceParserSetting::current();
        $id = $telegramGroup->chat_id;

        if (! $request->boolean('on')) {
            if (trim((string) $settings->{$field}) === $id) {
                $settings->update([$field => null]);
            }

            return back()->with('success', 'Role removed.');
        }
        $roles = $telegramGroup->roles();
        $error = match (true) {
            $telegramGroup->isGone() => 'The bot is not in this group any more — add it back first.',
            $telegramGroup->type === 'channel' => 'This is a channel: the bot does not read channel posts. Use a group.',
            $data['role'] === 'invoice_chat' && array_intersect($roles, ['sales', 'alerts']) !== [] => 'This is the sales / alerts chat: alerts would reach the accountants. Use another group.',
            $data['role'] === 'invoice_chat' && in_array('invoice_source', $roles, true) => 'This group is the invoice source: the invoice chat must be another group.',
            $data['role'] === 'invoice_source' && in_array('invoice_chat', $roles, true) => 'This group is the invoice chat: the source must be another group.',
            in_array('json', $roles, true) => 'This group is the JSON recipient: errors would reach it. Change the JSON recipient first.',
            default => null,
        };
        if ($error) {
            return back()->withErrors(['role' => $error]);
        }
        $settings->update([$field => $id]);

        return back()->with('success', $data['role'] === 'invoice_source'
            ? 'The bot now reads invoice photos in this group (its messages are no longer recorded as conversations).'
            : 'Read invoices are now posted to this group.');
    }

    public function link(Request $request, TelegramChat $telegramGroup, CompanyLookup $lookup)
    {
        abort_unless($telegramGroup->isGroup(), 404);
        $data = $request->validate(['client_id' => ['required', 'integer', 'exists:clients,id']]);
        $lookup->link(Client::findOrFail($data['client_id']), null, $telegramGroup->chat_id, 'manager', $request->user()->name);

        return back()->with('success', 'Company linked.');
    }

    public function leave(TelegramChat $telegramGroup, TelegramApi $api)
    {
        abort_unless($telegramGroup->isGroup(), 404);
        if ($telegramGroup->isGone()) {
            return back()->withErrors(['leave' => 'The bot is not in this group any more.']);
        }
        if ($roles = $telegramGroup->roles()) {
            return back()->withErrors(['leave' => 'This group is used as: '.implode(', ', array_map(fn ($r) => TelegramChat::ROLE_NAMES[$r], $roles)).'. Change that first.']);
        }
        if ($api->call('leaveChat', ['chat_id' => $telegramGroup->chat_id]) === null) {
            return back()->withErrors(['leave' => 'Telegram did not accept it (the bot may have left already). Try again later.']);
        }
        $telegramGroup->update(['bot_status' => 'left']);

        return back()->with('success', 'The bot left the group. Its stored conversations stay.');
    }
}
