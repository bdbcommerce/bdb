<?php

namespace Database\Seeders;

use App\Models\AiEmployee;
use App\Models\AiShadowDraft;
use App\Models\CatalogClient;
use App\Models\CatalogLoad;
use App\Models\CatalogProduct;
use App\Models\CatalogSetting;
use App\Models\ChatConversation;
use App\Models\ChatLead;
use App\Models\Client;
use App\Models\ClientPerson;
use App\Models\ClientRequest;
use App\Models\ExpertQuestion;
use App\Models\SalesKnowledge;
use App\Models\TelegramUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo data for the AI team pages (contacts, chats, shadow replies, expert questions, portraits, catalog, leads,
 * client requests), so every page can be seen filled in. Local / staging only (DemoContentSeeder); all demo contacts
 * have Telegram IDs 900000001… and "Demo" in their notes. Skipped when they exist.
 */
class DemoAiTeamSeeder extends Seeder
{
    private const ID = 900000000;

    public function run(): void
    {
        if (TelegramUser::where('telegram_id', (string) (self::ID + 1))->exists()) {
            return;
        }
        $malika = AiEmployee::where('name', 'Malika')->first();

        // --- Contacts: clients, a supplier, one not assigned yet, and two employees who answer the AI's questions.
        $u = fn (int $n, array $a) => TelegramUser::create($a + ['telegram_id' => (string) (self::ID + $n), 'locale' => 'ru', 'step' => TelegramUser::STEP_DONE,
            'source' => 'bot', 'notes' => 'Demo contact', 'assigned_at' => now()->subDays(10), 'bot_chat_at' => now()->subDay()]);
        $aziz = $u(1, ['name' => 'Aziz Karimov', 'profile_name' => 'Aziz K.', 'username' => 'aziz_market', 'phone' => '+998901112233', 'phone_verified_at' => now()->subDays(9),
            'company' => 'Korzinka Demo', 'contact_type' => 'client', 'registered_at' => now()->subDays(9), 'locale' => 'uz']);
        $olga = $u(2, ['name' => 'Olga Petrova', 'profile_name' => 'Olga', 'username' => 'olga_pharm', 'phone' => '+998935554411', 'phone_verified_at' => now()->subDays(5),
            'company' => 'Dori-Darmon Demo', 'contact_type' => 'client', 'registered_at' => now()->subDays(5)]);
        $timur = $u(3, ['name' => 'Timur Rakhimov', 'profile_name' => 'Timur', 'username' => 'timur_dealer', 'phone' => '+998977778899', 'phone_verified_at' => now()->subDays(30),
            'company' => 'Scan Service Demo (dealer)', 'contact_type' => 'client', 'registered_at' => now()->subDays(30), 'ai_employee_id' => $malika?->id]);
        $guest = $u(4, ['profile_name' => 'Dilnoza', 'username' => 'dilnoza_tex', 'contact_type' => 'client', 'step' => TelegramUser::STEP_GUEST]);
        $u(5, ['name' => 'Bekzod (supplier)', 'profile_name' => 'Bekzod', 'username' => 'labels_supply', 'company' => 'Label Factory Demo', 'contact_type' => 'supplier']);
        $u(6, ['profile_name' => 'Unknown contact', 'contact_type' => null, 'assigned_at' => null, 'greeted_at' => now()->subHours(3), 'assignment_requested_at' => now()->subHours(3)]);
        $service = $u(7, ['name' => 'Rustam (service)', 'profile_name' => 'Rustam', 'username' => 'rustam_service', 'contact_type' => 'employee', 'is_employee' => true,
            'employee_role' => 'Service', 'answers_questions' => true]);
        $boss = $u(8, ['name' => 'Jasur (CEO)', 'profile_name' => 'Jasur', 'username' => 'jasur_ceo', 'contact_type' => 'employee', 'is_employee' => true,
            'employee_role' => 'CEO', 'answers_questions' => true, 'is_main_admin' => true]);

        // --- Conversations (one per customer), with the assistant's state.
        $chat = fn (TelegramUser $user, array $lines, array $state, array $extra = []) => ChatConversation::create($extra + [
            'uuid' => (string) Str::uuid(), 'channel' => 'telegram', 'external_id' => $user->telegram_id, 'locale' => $user->locale ?? 'ru',
            'messages' => $this->messages($lines), 'message_count' => count($lines), 'sales_state' => $state + ['handled' => count($lines), 'kind' => 'sales'],
        ]);
        $azizChat = $chat($aziz, [
            ['user', 'Assalomu alaykum. Do‘konimiz uchun shtrix-kod skaneri va chek printer kerak, 3 ta kassa.', 50],
            ['assistant', 'Va alaykum assalom, Aziz aka! 3 ta kassa uchun simli 2D skaner va 80 mm chek printer mos keladi. Kassalar qaysi dasturda ishlaydi?', 49],
            ['user', '1C da. Asl Belgi kodlarini ham o‘qishi kerak.', 45],
            ['assistant', 'Unda DataMatrix o‘qiydigan 2D skaner kerak — masalan, Zebra DS2208: 1 dona narxi 1 150 000 so‘m. Chek printer Xprinter XP-Q80: 890 000 so‘m. Taklifni tayyorlaymi?', 44],
            ['user', 'Ha, tayyorlang. Yetkazib berish bormi?', 2],
        ], ['stage' => 'confirm', 'language' => 'uz', 'customer' => ['first_name' => 'Aziz', 'phone' => '+998901112233', 'company' => 'Korzinka Demo'],
            'items' => [['product' => 'Zebra DS2208 2D scanner', 'details' => '3 pcs, reads DataMatrix'], ['product' => 'Xprinter XP-Q80', 'details' => '3 pcs, 80 mm']],
            'summary' => '3 checkouts: 3 × Zebra DS2208 + 3 × XP-Q80, 1C, Asl Belgi; asks about delivery.'], ['awaiting_reply' => true, 'last_message_at' => now()->subMinutes(2)]);
        $olgaChat = $chat($olga, [
            ['user', 'Здравствуйте. Нам для аптеки нужен ТСД для маркировки лекарств, работаем в морозильной камере тоже.', 40],
            ['assistant', 'Здравствуйте, Ольга! Для аптеки с работой при −20 °C нужен ТСД с подогревом экрана. Сколько сотрудников будут работать с ТСД?', 39],
            ['user', 'Двое. А он работает с нашей программой Аптека-Про?', 30],
        ], ['stage' => 'questions', 'language' => 'ru', 'customer' => ['first_name' => 'Olga', 'company' => 'Dori-Darmon Demo'],
            'items' => [['product' => 'TSD for a freezer', 'details' => '2 pcs, pharmacy marking']], 'summary' => 'Pharmacy: 2 freezer TSDs; asks if it works with Apteka-Pro.'],
            ['awaiting_reply' => true, 'last_message_at' => now()->subMinutes(30)]);
        $timurChat = $chat($timur, [
            ['user', 'Привет. Какая дилерская цена на Zebra ZD421 и сколько есть на складе?', 300],
            ['assistant', 'Здравствуйте, Тимур! Zebra ZD421 для дилера — 4 650 000 сум, на складе 12 шт. Нужен ли риббон и этикетки к ним?', 299],
            ['user', 'Возьму 5 штук, риббоны тоже. Оформите.', 290],
            ['assistant', 'Записал: 5 × ZD421 и 5 риббонов 110 мм × 300 м. Передаю менеджеру, он пришлёт счёт.', 289],
        ], ['stage' => 'handed_over', 'language' => 'ru', 'customer' => ['first_name' => 'Timur', 'phone' => '+998977778899', 'company' => 'Scan Service Demo'],
            'items' => [['product' => 'Zebra ZD421', 'details' => '5 pcs, dealer price'], ['product' => 'Ribbon 110 × 300', 'details' => '5 pcs']],
            'summary' => 'Dealer order: 5 × ZD421 + 5 ribbons.', 'handed_over' => 'Dealer order: 5 × ZD421 + 5 ribbons.']);
        $guestChat = $chat($guest, [
            ['user', 'Salom, tekstil uchun yorliq printer bormi?', 20],
        ], ['stage' => 'need', 'language' => 'uz', 'summary' => 'Textile: asks for a label printer.'], ['awaiting_reply' => true, 'last_message_at' => now()->subMinutes(20)]);

        // --- Shadow replies: waiting, edited, sent as is, discarded.
        $draft = fn (ChatConversation $c, string $text, string $status, ?string $final = null) => AiShadowDraft::create([
            'chat_conversation_id' => $c->id, 'ai_employee_id' => $malika?->id, 'prompt_version' => 1, 'draft' => $text, 'final' => $final, 'status' => $status,
            'decided_by' => $status === 'pending' ? null : 'Admin', 'decided_at' => $status === 'pending' ? null : now()->subHour(),
        ]);
        $draft($azizChat, 'Ha, Toshkent bo‘ylab yetkazib berish bepul, 1–2 kun ichida. Taklifni Telegramga PDF qilib yuboraymi?', 'pending');
        $draft($guestChat, 'Salom, Dilnoza! Ha, tekstil uchun termotransfer printerlar bor. Qanday yorliqlar — kiyim yorlig‘i yoki quti uchunmi?', 'pending');
        $draft($timurChat, 'Записал: 5 × ZD421. Передаю менеджеру.', 'edited', 'Записал: 5 × ZD421 и 5 риббонов 110 мм × 300 м. Передаю менеджеру, он пришлёт счёт.');
        $draft($olgaChat, 'Здравствуйте, Ольга! Для аптеки с работой при −20 °C нужен ТСД с подогревом экрана.', 'sent', 'Здравствуйте, Ольга! Для аптеки с работой при −20 °C нужен ТСД с подогревом экрана.');
        $draft($olgaChat, 'Да, конечно работает с любой программой.', 'discarded');

        // --- Client requests, and the expert questions made from them (every status).
        $request = fn (ChatConversation $c, string $q, string $topic, string $status, string $kind = 'product') => ClientRequest::create([
            'chat_conversation_id' => $c->id, 'channel' => 'telegram', 'external_id' => $c->external_id, 'locale' => $c->locale, 'question' => $q,
            'status' => $status, 'topic' => $topic, 'category' => 'product', 'knowledge_kind' => $kind, 'resolved_at' => $status === 'resolved' ? now()->subHour() : null,
        ]);
        $r1 = $request($olgaChat, 'А он работает с нашей программой Аптека-Про?', 'TSD compatibility with Apteka-Pro', 'open', 'software');
        $r2 = $request($azizChat, 'Yetkazib berish bormi?', 'Delivery in Tashkent', 'resolved', 'standard');
        $r3 = $request($timurChat, 'Есть ли гарантия на печатающую головку ZD421?', 'ZD421 print head warranty', 'resolved', 'device');
        $request($guestChat, 'Ipak matoga yorliq chop etsa bo‘ladimi?', 'Printing on silk labels', 'open', 'product');

        $q = fn (array $a) => ExpertQuestion::create($a + ['language' => 'ru', 'history' => [['at' => now()->subHours(2)->toIso8601String(), 'event' => 'asked']]]);
        $q(['chat_conversation_id' => $olgaChat->id, 'customer_id' => $olga->id, 'client_request_id' => $r1->id, 'question' => 'Does the freezer TSD work with the pharmacy program Apteka-Pro?',
            'customer_text' => 'А он работает с нашей программой Аптека-Про?', 'ai_draft' => 'Probably yes, through the standard keyboard-wedge mode (the scan is typed into the field).',
            'topic' => 'TSD compatibility with Apteka-Pro', 'knowledge_kind' => 'software', 'department' => 'Service', 'status' => 'asked', 'expert_id' => $service->id, 'asked_at' => now()->subMinutes(25)]);
        $kn = SalesKnowledge::create(['kind' => 'standard', 'title' => 'Delivery in Tashkent', 'body' => 'Delivery in Tashkent is free, in 1–2 working days. Regions: by courier service, 2–4 days, paid by the customer.', 'is_active' => false]);
        $q(['chat_conversation_id' => $azizChat->id, 'customer_id' => $aziz->id, 'client_request_id' => $r2->id, 'question' => 'Do we deliver in Tashkent, how fast and at what cost?',
            'customer_text' => 'Yetkazib berish bormi?', 'ai_draft' => 'Delivery in Tashkent is free in 1–2 days.', 'topic' => 'Delivery in Tashkent', 'knowledge_kind' => 'standard',
            'department' => 'Sales', 'status' => 'answered', 'expert_id' => $boss->id, 'answered_by' => $boss->id, 'asked_at' => now()->subHours(3), 'answered_at' => now()->subHours(2),
            'answer' => 'Delivery in Tashkent is free, in 1–2 working days. Regions: by courier service, 2–4 days, paid by the customer.', 'verdict' => 'fixed',
            'delivered' => 'shadow', 'sales_knowledge_id' => $kn->id, 'language' => 'uz']);
        $kn2 = SalesKnowledge::create(['kind' => 'device', 'title' => 'ZD421 print head warranty', 'body' => 'Zebra ZD421: 12 months warranty, the print head 6 months or 50 km of printing, whichever comes first.', 'is_active' => true]);
        $q(['chat_conversation_id' => $timurChat->id, 'customer_id' => $timur->id, 'client_request_id' => $r3->id, 'question' => 'What is the warranty on the ZD421 print head?',
            'customer_text' => 'Есть ли гарантия на печатающую головку ZD421?', 'ai_draft' => 'Zebra ZD421: 12 months warranty, the print head 6 months or 50 km of printing, whichever comes first.',
            'topic' => 'ZD421 print head warranty', 'knowledge_kind' => 'device', 'department' => 'Service', 'status' => 'answered', 'expert_id' => $service->id,
            'answered_by' => $service->id, 'asked_at' => now()->subDays(2), 'answered_at' => now()->subDays(2)->addMinutes(12),
            'answer' => 'Zebra ZD421: 12 months warranty, the print head 6 months or 50 km of printing, whichever comes first.', 'verdict' => 'accepted', 'delivered' => 'sent', 'sales_knowledge_id' => $kn2->id]);
        $q(['chat_conversation_id' => $guestChat->id, 'customer_id' => $guest->id, 'question' => 'Can our label printers print on silk (satin) labels for clothing?',
            'customer_text' => 'Ipak matoga yorliq chop etsa bo‘ladimi?', 'topic' => 'Printing on silk labels', 'knowledge_kind' => 'product', 'department' => 'Sales', 'to_admin' => true,
            'status' => 'queued', 'language' => 'uz']);
        $q(['chat_conversation_id' => $timurChat->id, 'customer_id' => $timur->id, 'question' => 'When will the Zebra ZT411 arrive from the supplier?',
            'customer_text' => 'Когда будут ZT411?', 'topic' => 'ZT411 arrival date', 'knowledge_kind' => 'product', 'department' => 'Service', 'status' => 'unknown',
            'expert_id' => $service->id, 'asked_at' => now()->subDays(1), 'answered_at' => now()->subDays(1)->addMinutes(40), 'verdict' => 'unknown', 'delivered' => 'manager']);

        // --- Leads from the assistant.
        ChatLead::create(['channel' => 'telegram', 'name' => 'Timur Rakhimov (Scan Service Demo)', 'phone' => '+998977778899', 'topic' => 'Dealer order: 5 × ZD421 + 5 ribbons', 'locale' => 'ru', 'is_handled' => false]);
        ChatLead::create(['channel' => 'telegram', 'name' => 'Aziz Karimov', 'phone' => '+998901112233', 'topic' => '[Unfinished] 3 checkouts: scanners + receipt printers', 'locale' => 'uz', 'is_handled' => true]);

        // --- Client portraits: two companies, people with different parts and statuses; one moved to another company.
        $korzinka = Client::create(['name' => 'Korzinka Demo', 'inn' => '301234567', 'industry' => 'Retail', 'city' => 'Tashkent', 'size' => '12 stores, 40 checkouts',
            'status' => 'active', 'since' => '2023', 'owner' => 'Sales: Nodira', 'installed' => "40 × Zebra DS2208\n12 × Xprinter XP-Q80\n2 × Zebra ZD421 (warehouse)",
            'consumables' => 'Receipt paper 80 × 80, about 600 rolls a month', 'notes' => 'Demo company. Pays by bank transfer, 50% prepayment.']);
        $pharm = Client::create(['name' => 'Dori-Darmon Demo', 'industry' => 'Pharmacy', 'city' => 'Samarkand', 'size' => '5 pharmacies', 'status' => 'prospect', 'owner' => 'Sales: Nodira',
            'notes' => 'Demo company. Needs Asl Belgi marking for medicines.']);
        ClientPerson::create(['client_id' => $korzinka->id, 'telegram_user_id' => $aziz->id, 'name' => 'Aziz Karimov', 'position' => 'IT manager', 'department' => 'IT', 'part' => 'technical',
            'status' => 'active', 'style' => 'Short messages in Uzbek, likes exact specs and prices.', 'phone' => '+998901112233']);
        ClientPerson::create(['client_id' => $korzinka->id, 'name' => 'Shahlo Yusupova', 'position' => 'CFO', 'department' => 'Finance', 'part' => 'pays', 'status' => 'vacation',
            'style' => 'Formal, Russian. Wants the invoice and the contract in one message.']);
        $old = ClientPerson::create(['client_id' => $korzinka->id, 'name' => 'Sardor Aliev', 'position' => 'Purchasing manager', 'part' => 'decides', 'status' => 'left']);
        ClientPerson::create(['client_id' => $pharm->id, 'telegram_user_id' => $olga->id, 'previous_id' => $old->id, 'name' => 'Olga Petrova', 'position' => 'Head of the pharmacy network',
            'part' => 'decides', 'status' => 'active', 'style' => 'Polite, detailed, asks about compatibility before the price.']);

        // --- Product catalog: a few products per category with three prices, stock and params; dealer / partner phones.
        $p = fn (string $sku, string $brand, string $model, string $category, string $ru, array $prices, string $stock, ?int $qty, array $params, array $uses = [], array $acc = []) => CatalogProduct::create([
            'sku' => $sku, 'brand' => $brand, 'model' => $model, 'category' => $category, 'name' => ['ru' => $ru, 'uz' => $ru, 'en' => $brand.' '.$model],
            'prices' => array_combine(['end_user', 'dealer', 'partner'], $prices), 'stock_status' => $stock, 'stock_qty' => $qty, 'lead_time_days' => $stock === 'to_order' ? 21 : null,
            'warranty_months' => 12, 'params' => $params, 'use_cases' => $uses, 'accessories' => $acc, 'is_active' => true, 'in_feed' => true,
        ]);
        $p('ZD421T', 'Zebra', 'ZD421t', 'label_printer', 'Принтер этикеток Zebra ZD421t', [5200000, 4650000, 4300000], 'in_stock', 12,
            ['print_method' => 'thermal transfer', 'print_width_mm' => 104, 'resolution_dpi' => 203, 'print_speed_mm_s' => 152, 'connectivity' => ['USB', 'Ethernet'], 'class' => 'desktop'], ['retail', 'warehouse', 'textile'], ['RIB110300']);
        $p('ZT411', 'Zebra', 'ZT411', 'label_printer', 'Промышленный принтер Zebra ZT411', [21500000, 19800000, 18900000], 'to_order', null,
            ['print_method' => 'thermal transfer', 'print_width_mm' => 104, 'resolution_dpi' => 300, 'print_speed_mm_s' => 356, 'connectivity' => ['USB', 'Ethernet', 'Bluetooth'], 'class' => 'industrial'], ['production', 'logistics']);
        $p('XPQ80', 'Xprinter', 'XP-Q80', 'receipt_printer', 'Чековый принтер Xprinter XP-Q80', [890000, 790000, 740000], 'in_stock', 54,
            ['paper_width_mm' => 80, 'print_speed_mm_s' => 200, 'connectivity' => ['USB', 'Ethernet'], 'auto_cutter' => true], ['retail', 'horeca'], ['RP8080']);
        $p('DS2208', 'Zebra', 'DS2208', 'scanner', 'Сканер штрих-кода Zebra DS2208 2D', [1150000, 1020000, 960000], 'low', 4,
            ['code_types' => '1D/2D', 'form' => 'handheld', 'wireless' => false, 'connectivity' => ['USB'], 'reads_screens' => true, 'reads_datamatrix' => true], ['retail', 'pharmacy']);
        $p('TC21F', 'Zebra', 'TC21 Freezer', 'tsd', 'ТСД Zebra TC21 (морозильное исполнение)', [9800000, 9100000, 8700000], 'to_order', null,
            ['os' => 'Android 11', 'screen_inch' => 5, 'scanner_type' => '2D', 'ram_gb' => 3, 'storage_gb' => 32, 'battery_mah' => 3300, 'ip_rating' => 'IP67', 'freezer' => true, 'nfc' => true], ['pharmacy', 'warehouse']);
        $p('RIB110300', 'Armor', 'AWR 8', 'ribbon', 'Риббон воск 110 мм × 300 м', [95000, 82000, 76000], 'in_stock', 400,
            ['type' => 'wax', 'width_mm' => 110, 'length_m' => 300, 'core_mm' => 25, 'ink_side' => 'outside']);
        $p('RP8080', 'BDB', 'Thermo 80/80', 'receipt_paper', 'Чековая лента 80 мм × 80 м', [14000, 11500, 10500], 'in_stock', 3000, ['width_mm' => 80, 'length_m' => 80, 'core_mm' => 12]);
        $p('LBL5840', 'BDB', 'Thermo 58×40', 'label', 'Этикетки термо 58 × 40 мм, 1000 шт', [42000, 36000, 33000], 'out', 0,
            ['material' => 'thermal eco', 'width_mm' => 58, 'height_mm' => 40, 'labels_per_roll' => 1000, 'core_mm' => 40, 'winding' => 'outside'], ['retail']);
        CatalogClient::create(['phone' => '+998977778899', 'price_group' => 'dealer', 'company' => 'Scan Service Demo', 'inn' => '305551112']);
        CatalogClient::create(['phone' => '+998935550000', 'price_group' => 'partner', 'company' => 'Partner Demo LLC', 'inn' => '307778889']);
        CatalogLoad::create(['source' => 'push', 'trigger' => 'api', 'status' => 'ok', 'received' => 9, 'accepted' => 8, 'rejected_count' => 1, 'hidden' => 0, 'clients' => 2,
            'rejected' => [['sku' => 'BAD-1', 'error' => 'prices.end_user: must be a positive number']], 'duration_ms' => 184]);
        rescue(fn () => CatalogSetting::current()->forceFill(['last_success_at' => now()->subMinutes(40)])->save(), null, false);
    }

    /** @param list<array{0: string, 1: string, 2: int}> $lines role, text, minutes ago */
    private function messages(array $lines): array
    {
        return array_values(array_map(fn (array $l, int $i) => ['role' => $l[0], 'content' => $l[1], 'at' => now()->subMinutes($l[2])->toIso8601String(), 'message_id' => 100 + $i]
            + ($l[0] === 'assistant' ? ['employee' => 'Malika'] : []), $lines, array_keys($lines)));
    }
}
