<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\Industry;
use Illuminate\Database\Seeder;

/**
 * Demo catalog (plus the starter AI knowledge base and articles) so the site doesn't look empty during development.
 * Each part is skipped when its table already has rows. Replace with real data
 * before launch: customer names here are placeholders, not real clients.
 *
 * php artisan db:seed --class=DemoContentSeeder
 */
class DemoContentSeeder extends Seeder
{
    public function run(): void
    {
        $this->brands();
        $this->industriesAndCustomers();
        $this->call([KnowledgeBaseSeeder::class, ArticleSeeder::class]);
    }

    private function brands(): void
    {
        if (Brand::count() > 0) {
            $this->command?->info('Brands already exist, skipping demo brands.');

            return;
        }

        $brands = [
            ['Zebra Technologies', 'https://www.zebra.com', [
                'uz' => 'Yorliq va chek printerlari, mobil terminallar va skanerlar.',
                'ru' => 'Принтеры этикеток и чеков, мобильные терминалы и сканеры.',
                'en' => 'Label and receipt printers, mobile computers and scanners.',
            ]],
            ['Honeywell', 'https://sps.honeywell.com', [
                'uz' => 'Shtrix-kod skanerlari, maʼlumot yigʻish terminallari va printerlar.',
                'ru' => 'Сканеры штрихкодов, терминалы сбора данных и принтеры.',
                'en' => 'Barcode scanners, data collection terminals and printers.',
            ]],
            ['Datalogic', 'https://www.datalogic.com', [
                'uz' => 'Chakana savdo va ombor uchun skanerlar va mobil kompyuterlar.',
                'ru' => 'Сканеры и мобильные компьютеры для розницы и склада.',
                'en' => 'Scanners and mobile computers for retail and warehousing.',
            ]],
            ['TSC Printronix', 'https://www.tscprinters.com', [
                'uz' => 'Sanoat va ish stoli yorliq printerlari.',
                'ru' => 'Промышленные и настольные принтеры этикеток.',
                'en' => 'Industrial and desktop label printers.',
            ]],
            ['Godex', 'https://www.godexintl.com', [
                'uz' => 'Yorliq chop etish uchun qulay narxdagi printerlar.',
                'ru' => 'Доступные принтеры для печати этикеток.',
                'en' => 'Cost-effective label printers.',
            ]],
            ['Urovo', 'https://www.urovo.com', [
                'uz' => 'Android asosidagi maʼlumot yigʻish terminallari.',
                'ru' => 'Терминалы сбора данных на Android.',
                'en' => 'Android data collection terminals.',
            ]],
        ];

        foreach ($brands as $i => [$name, $website, $description]) {
            Brand::create([
                'name' => ['uz' => $name, 'ru' => $name, 'en' => $name],
                'website' => $website,
                'description' => $description,
                'sort_order' => $i,
                'is_active' => true,
            ]);
        }
    }

    private function industriesAndCustomers(): void
    {
        if (Industry::count() > 0) {
            $this->command?->info('Industries already exist, skipping demo industries and customers.');

            return;
        }

        $industries = [
            [
                'icon' => 'shopping-cart',
                'name' => ['uz' => 'Chakana savdo', 'ru' => 'Розничная торговля', 'en' => 'Retail'],
                'description' => [
                    'uz' => 'Kassa skanerlari, narx yorliqlari va inventarizatsiya.',
                    'ru' => 'Кассовые сканеры, ценники и инвентаризация.',
                    'en' => 'Checkout scanners, price labels and stocktaking.',
                ],
                'customers' => ['Demo Supermarket Chain', 'Demo Electronics Store'],
            ],
            [
                'icon' => 'truck',
                'name' => ['uz' => 'Logistika va omborlar', 'ru' => 'Логистика и склады', 'en' => 'Logistics & warehousing'],
                'description' => [
                    'uz' => 'Qabul qilish, joylashtirish va yuklarni joʻnatishni avtomatlashtirish.',
                    'ru' => 'Автоматизация приёмки, размещения и отгрузки грузов.',
                    'en' => 'Automated receiving, put-away and shipping.',
                ],
                'customers' => ['Demo Logistics Hub', 'Demo Distribution Center'],
            ],
            [
                'icon' => 'cube',
                'name' => ['uz' => 'Ishlab chiqarish', 'ru' => 'Производство', 'en' => 'Manufacturing'],
                'description' => [
                    'uz' => 'Mahsulotni markirovka qilish va ishlab chiqarish liniyasida kuzatish.',
                    'ru' => 'Маркировка продукции и прослеживаемость на производственной линии.',
                    'en' => 'Product marking and traceability on the production line.',
                ],
                'customers' => ['Demo Beverage Plant', 'Demo Textile Factory'],
            ],
            [
                'icon' => 'beaker',
                'name' => ['uz' => 'Farmatsevtika', 'ru' => 'Фармацевтика', 'en' => 'Pharmaceuticals'],
                'description' => [
                    'uz' => 'Dori vositalarini Asl Belgi talablari boʻyicha markirovka qilish.',
                    'ru' => 'Маркировка лекарственных средств по требованиям Асл Белги.',
                    'en' => 'Marking medicines to Asl Belgi requirements.',
                ],
                'customers' => ['Demo Pharma Distributor', 'Demo Pharmacy Network'],
            ],
            [
                'icon' => 'heart',
                'name' => ['uz' => 'Sogʻliqni saqlash', 'ru' => 'Здравоохранение', 'en' => 'Healthcare'],
                'description' => [
                    'uz' => 'Bemorlar bilagi, namunalar va tibbiy jihozlarni hisobga olish.',
                    'ru' => 'Браслеты пациентов, учёт образцов и медицинского оборудования.',
                    'en' => 'Patient wristbands, sample and medical equipment tracking.',
                ],
                'customers' => ['Demo City Clinic'],
            ],
            [
                'icon' => 'banknotes',
                'name' => ['uz' => 'Moliya va xizmatlar', 'ru' => 'Финансы и услуги', 'en' => 'Finance & services'],
                'description' => [
                    'uz' => 'Chek printerlari, hujjatlar va aktivlarni hisobga olish.',
                    'ru' => 'Чековые принтеры, учёт документов и активов.',
                    'en' => 'Receipt printers, document and asset tracking.',
                ],
                'customers' => ['Demo Bank'],
            ],
        ];

        foreach ($industries as $i => $data) {
            $industry = Industry::create([
                'name' => $data['name'],
                'description' => $data['description'],
                'icon' => $data['icon'],
                'sort_order' => $i,
                'is_active' => true,
            ]);

            foreach ($data['customers'] as $j => $customer) {
                Customer::create([
                    'name' => $customer,
                    'industry_id' => $industry->id,
                    'sort_order' => $j,
                    'is_active' => true,
                ]);
            }
        }
    }
}
