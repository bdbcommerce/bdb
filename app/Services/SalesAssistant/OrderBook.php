<?php

namespace App\Services\SalesAssistant;

use App\Models\ChatConversation;
use App\Models\Order;
use App\Models\OrderMonth;
use App\Models\SalesAssistantSetting;
use App\Models\TelegramUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A contact's orders from the chats. While the customer collects a request it is the open draft (one per contact, kept
 * until confirmed or cancelled; the AI continues it when they come back). The customer's confirmation makes it
 * confirmed; the last KEEP confirmed orders stay in full, older ones are added up per month (OrderMonth).
 */
class OrderBook
{
    public const KEEP = 5;

    public function draft(TelegramUser $user): ?Order
    {
        return Order::where('telegram_user_id', $user->id)->where('status', 'draft')->latest('id')->first();
    }

    /** The conversation's items become the open draft (created when the customer starts asking for products). */
    public function syncDraft(TelegramUser $user, ChatConversation $conversation, array $state): ?Order
    {
        $items = array_values(array_filter((array) ($state['items'] ?? []), fn ($i) => is_array($i) && filled($i['product'] ?? null)));
        if (! $items) {
            return $this->draft($user);
        }
        $draft = $this->draft($user);
        // The items of the order just confirmed in this chat (Claude may keep listing them) are not a new draft.
        $same = fn (?Order $o) => $o && json_encode(array_column((array) $o->items, 'product')) === json_encode(array_column(array_slice($items, 0, 20), 'product'));
        if (! $draft && $same(Order::where('chat_conversation_id', $conversation->id)->whereIn('status', ['confirmed', 'cancelled'])->latest('id')->first())) {
            return null;
        }
        $draft ??= new Order(['telegram_user_id' => $user->id, 'status' => 'draft']);
        $draft->fill(['chat_conversation_id' => $conversation->id, 'items' => array_slice($items, 0, 20), 'quoted' => array_slice((array) ($state['quoted'] ?? []), 0, 20) ?: null,
            'summary' => filled($state['summary'] ?? null) ? mb_substr($state['summary'], 0, 800) : $draft->summary] + $this->company($user))->save();

        return $draft;
    }

    /** The customer confirmed: the draft (or a new order) is confirmed, linked to the request; old ones roll into months. */
    public function confirm(TelegramUser $user, ChatConversation $conversation, array $state, ?int $leadId): Order
    {
        $order = DB::transaction(function () use ($user, $conversation, $state, $leadId) {
            $existing = $leadId ? Order::where('chat_lead_id', $leadId)->where('status', 'confirmed')->first() : null;
            $order = $existing ?? $this->syncDraft($user, $conversation, $state) ?? new Order(['telegram_user_id' => $user->id]);
            $order->fill(['status' => 'confirmed', 'chat_lead_id' => $leadId, 'chat_conversation_id' => $conversation->id,
                'items' => array_slice((array) ($state['items'] ?? $order->items ?? []), 0, 20), 'quoted' => array_slice((array) ($state['quoted'] ?? []), 0, 20) ?: $order->quoted,
                'summary' => mb_substr((string) ($state['summary'] ?? $order->summary), 0, 800), 'confirmed_at' => $order->confirmed_at ?? now()] + $this->company($user))->save();

            return $order;
        });
        $this->roll($user);

        return $order;
    }

    /** The customer does not want it any more, or starts a new one. */
    public function cancelDraft(TelegramUser $user): ?Order
    {
        $draft = $this->draft($user);
        $draft?->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        return $draft;
    }

    /** Confirmed orders beyond the last KEEP are added to their month and removed. */
    public function roll(TelegramUser $user): void
    {
        Order::where('telegram_user_id', $user->id)->where('status', 'confirmed')->orderByDesc('confirmed_at')->orderByDesc('id')->skip(self::KEEP)->take(500)->get()
            ->each(function (Order $order) use ($user) {
                DB::transaction(function () use ($order, $user) {
                    $month = OrderMonth::firstOrNew(['telegram_user_id' => $user->id,
                        'month' => ($order->confirmed_at ?? $order->created_at)->copy()->setTimezone(SalesAssistantSetting::TZ)->format('Y-m')]); // Tashkent months
                    $items = (array) ($month->items ?? []);
                    foreach ((array) $order->items as $i) {
                        $name = mb_substr(trim((string) ($i['product'] ?? '')), 0, 100);
                        if ($name !== '') {
                            $items[$name] = ($items[$name] ?? 0) + 1;
                        }
                    }
                    arsort($items);
                    $month->fill(['orders' => (int) $month->orders + 1, 'items' => array_slice($items, 0, 50, true),
                        'quoted_sum' => (int) $month->quoted_sum + self::sum((array) $order->quoted)])->save();
                    $order->delete();
                });
            });
        // Cancelled drafts are history only for a while.
        Order::where('telegram_user_id', $user->id)->where('status', 'cancelled')->where('cancelled_at', '<', now()->subDays(90))->delete();
    }

    /**
     * What the AI reads (CONVERSATION CONTEXT): the open draft to continue, the last KEEP orders and the month totals.
     *
     * @return list<string>
     */
    public function forPrompt(TelegramUser $user): array
    {
        $json = fn ($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $lines = [];
        if ($draft = $this->draft($user)) {
            $lines[] = 'Open draft order (not confirmed yet, from '.$draft->updated_at->format('d.m.Y').'): '.$json(['items' => $draft->itemLines(), 'summary' => $draft->summary])
                .' — when this is a new conversation, ask once whether to continue it, change it or start a new one (start_new); a confirmation confirms it.';
        }
        $orders = Order::where('telegram_user_id', $user->id)->where('status', 'confirmed')->orderByDesc('confirmed_at')->limit(self::KEEP)->get();
        if ($orders->isNotEmpty()) {
            $lines[] = 'Last orders: '.$json($orders->map(fn (Order $o) => ['date' => $o->confirmed_at?->format('d.m.Y'), 'items' => $o->itemLines()])->all())
                .' — you may suggest a reorder ("the same as last time?").';
        }
        $months = OrderMonth::where('telegram_user_id', $user->id)->orderByDesc('month')->limit(12)->get();
        if ($months->isNotEmpty()) {
            $lines[] = 'Earlier orders by month: '.$json($months->map(fn (OrderMonth $m) => ['month' => $m->month, 'orders' => $m->orders, 'products' => array_keys(array_slice((array) $m->items, 0, 10, true))])->all());
        }

        return $lines;
    }

    /**
     * Reorders that look due: a product a contact ordered at least 3 times (last orders + month totals) at a steady
     * pace, whose usual interval has passed by REORDER_SLACK. One hint per contact, product and last order.
     *
     * @return list<array{user: TelegramUser, product: string, every: int, since: int}>
     */
    public function dueReorders(): array
    {
        $due = [];
        $userIds = Order::where('status', 'confirmed')->where('confirmed_at', '>=', now()->subYear())->distinct()->pluck('telegram_user_id');
        foreach (TelegramUser::whereIn('id', $userIds)->limit(2000)->get() as $user) {
            $dates = []; // product => list of order dates
            foreach (Order::where('telegram_user_id', $user->id)->where('status', 'confirmed')->get() as $o) {
                foreach ((array) $o->items as $i) {
                    $dates[mb_strtolower(trim((string) ($i['product'] ?? '')))][] = $o->confirmed_at ?? $o->created_at;
                }
            }
            foreach (OrderMonth::where('telegram_user_id', $user->id)->get() as $m) {
                foreach (array_keys((array) $m->items) as $product) {
                    $dates[mb_strtolower(trim((string) $product))][] = Carbon::parse($m->month.'-15');
                }
            }
            foreach ($dates as $product => $list) {
                if ($product === '' || count($list) < 3) {
                    continue;
                }
                usort($list, fn ($a, $b) => $a <=> $b);
                $gaps = [];
                foreach (array_slice($list, 1) as $k => $d) {
                    $gaps[] = $list[$k]->diffInDays($d);
                }
                $every = (int) round(array_sum($gaps) / count($gaps));
                $since = (int) end($list)->diffInDays(now());
                if ($every >= 7 && $since >= $every * self::REORDER_SLACK) {
                    $due[] = ['user' => $user, 'product' => $product, 'every' => $every, 'since' => $since, 'last' => end($list)->toDateString()];
                }
            }
        }

        return $due;
    }

    /** A reorder is "due" when this much more than the usual interval has passed. */
    public const REORDER_SLACK = 1.2;

    /** "1 150 000 sum" → 1150000 (each quoted price; what cannot be read counts 0). */
    public static function sum(array $quoted): int
    {
        return (int) collect($quoted)->sum(function ($q) {
            $digits = preg_replace('/\D/', '', (string) (is_array($q) ? ($q['price'] ?? '') : $q));

            return $digits !== '' && strlen($digits) <= 12 ? (int) $digits : 0;
        });
    }

    /** The contact's company details (the first manager-linked company), copied onto the order. */
    private function company(TelegramUser $user): array
    {
        $link = $user->companyLinks()->where('linked_by', 'manager')->with('client')->oldest('id')->first();
        $c = $link?->client;

        return ['client_id' => $c?->id, 'company' => $c ? array_filter(['name' => $c->name, 'full_name' => $c->full_name, 'inn' => $c->inn, 'address' => $c->address]) : null];
    }
}
