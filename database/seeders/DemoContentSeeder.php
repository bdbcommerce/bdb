<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\Industry;
use App\Models\KnowledgeEntry;
use Illuminate\Database\Seeder;

/**
 * Demo catalog and AI knowledge so the site doesn't look empty during development.
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
        $this->knowledge();
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

    private function knowledge(): void
    {
        if (KnowledgeEntry::count() > 0) {
            $this->command?->info('Knowledge entries already exist, skipping demo knowledge.');

            return;
        }

        $entries = [
            ['company', 'company, about, BDB, kompaniya, компания',
                [
                    'uz' => 'BDB nima bilan shugʻullanadi?',
                    'ru' => 'Чем занимается BDB?',
                    'en' => 'What does BDB do?',
                ],
                [
                    'uz' => 'BDB Oʻzbekistondagi avtoidentifikatsiya kompaniyasi. Biz shtrix-kod, RFID va maʼlumot yigʻish uskunalarini yetkazib beramiz, integratsiya qilamiz va ularga xizmat koʻrsatamiz, shuningdek Asl Belgi markirovkasini joriy etishda yordam beramiz.',
                    'ru' => 'BDB — компания по автоидентификации в Узбекистане. Мы поставляем, интегрируем и обслуживаем оборудование для штрихкодирования, RFID и сбора данных, а также помогаем внедрить маркировку Асл Белги.',
                    'en' => 'BDB is an auto identification company in Uzbekistan. We supply, integrate and service barcode, RFID and data collection equipment, and help companies implement Asl Belgi product marking.',
                ]],
            ['asl_belgi', 'asl belgi, marking, markirovka, маркировка, datamatrix',
                [
                    'uz' => 'Asl Belgi nima?',
                    'ru' => 'Что такое Асл Белги?',
                    'en' => 'What is Asl Belgi?',
                ],
                [
                    'uz' => 'Asl Belgi — Oʻzbekistonning mahsulotlarni raqamli markirovka qilish va kuzatish milliy tizimi. Muayyan tovar guruhlari noyob DataMatrix kodi bilan markirovka qilinishi kerak. BDB uskunalarni tanlash, kodlarni chop etish va qoʻllash hamda tizimga ulanishda yordam beradi.',
                    'ru' => 'Асл Белги — национальная система цифровой маркировки и прослеживаемости товаров в Узбекистане. Определённые товарные группы должны маркироваться уникальным кодом DataMatrix. BDB помогает подобрать оборудование, наладить печать и нанесение кодов и подключиться к системе.',
                    'en' => 'Asl Belgi is Uzbekistan\'s national digital product marking and traceability system. Certain product groups must carry a unique DataMatrix code. BDB helps choose the equipment, set up code printing and application, and connect to the system.',
                ]],
            ['product', 'equipment, products, scanner, printer, terminal, RFID, uskunalar, оборудование',
                [
                    'uz' => 'Qanday uskunalarni taklif qilasiz?',
                    'ru' => 'Какое оборудование вы предлагаете?',
                    'en' => 'What equipment do you offer?',
                ],
                [
                    'uz' => 'Shtrix-kod skanerlari, yorliq va chek printerlari, maʼlumot yigʻish terminallari, RFID uskunalari va markirovka uchun yechimlar. Biz bilan ishlaydigan brendlar roʻyxati “Brendlar” sahifasida.',
                    'ru' => 'Сканеры штрихкодов, принтеры этикеток и чеков, терминалы сбора данных, RFID-оборудование и решения для маркировки. Бренды, с которыми мы работаем, перечислены на странице «Бренды».',
                    'en' => 'Barcode scanners, label and receipt printers, data collection terminals, RFID equipment and marking solutions. The brands we work with are listed on the Brands page.',
                ]],
            ['pricing', 'price, cost, quote, narx, цена, стоимость, КП',
                [
                    'uz' => 'Narxlar qancha?',
                    'ru' => 'Сколько стоит оборудование?',
                    'en' => 'How much does it cost?',
                ],
                [
                    'uz' => 'Narx uskuna modeli, miqdori va integratsiya hajmiga bogʻliq, shuning uchun biz individual tijorat taklifi tayyorlaymiz. Telefon raqamingizni qoldiring yoki “Kontaktlar” sahifasi orqali soʻrov yuboring.',
                    'ru' => 'Цена зависит от модели, количества и объёма интеграции, поэтому мы готовим индивидуальное коммерческое предложение. Оставьте номер телефона или отправьте запрос через страницу «Контакты».',
                    'en' => 'The price depends on the model, quantity and integration scope, so we prepare an individual quote. Leave your phone number or send a request through the Contact page.',
                ]],
            ['support', 'service, repair, support, servis, taʼmirlash, ремонт, сервис',
                [
                    'uz' => 'Uskunalarni taʼmirlaysizmi?',
                    'ru' => 'Вы ремонтируете оборудование?',
                    'en' => 'Do you repair equipment?',
                ],
                [
                    'uz' => 'Ha, BDB biz yetkazib beradigan brendlar uskunalariga xizmat koʻrsatadi va taʼmirlaydi. Muddat va shartlar model va nosozlikka bogʻliq — mutaxassislarimiz ularni aniqlashtirib beradi.',
                    'ru' => 'Да, BDB обслуживает и ремонтирует оборудование брендов, которые мы поставляем. Сроки и условия зависят от модели и неисправности — наши специалисты их уточнят.',
                    'en' => 'Yes, BDB services and repairs equipment from the brands we supply. Timing and terms depend on the model and the fault; our specialists will confirm them.',
                ]],
            ['support', 'integration, 1C, ERP, WMS, software, integratsiya, интеграция',
                [
                    'uz' => 'Uskunalarni bizning dasturimizga ulay olasizmi?',
                    'ru' => 'Можете ли вы подключить оборудование к нашей учётной системе?',
                    'en' => 'Can you connect the equipment to our software?',
                ],
                [
                    'uz' => 'Ha, biz uskunalarni oʻrnatish va integratsiya qilish xizmatini koʻrsatamiz. Qaysi tizimdan foydalanishingizni ayting, mutaxassislarimiz yechim taklif qiladi.',
                    'ru' => 'Да, мы занимаемся установкой и интеграцией оборудования. Расскажите, какой системой вы пользуетесь, и наши специалисты предложат решение.',
                    'en' => 'Yes, we install and integrate equipment. Tell us which system you use and our specialists will propose a solution.',
                ]],
            ['faq', 'choose, select, which scanner, qaysi, какой выбрать',
                [
                    'uz' => 'Qaysi uskunani tanlashni bilmayman. Yordam bera olasizmi?',
                    'ru' => 'Не знаю, какое оборудование выбрать. Поможете?',
                    'en' => 'I don\'t know which equipment to choose. Can you help?',
                ],
                [
                    'uz' => 'Albatta. Sohangiz, kuniga qancha mahsulot yoki hujjat bilan ishlashingiz va uskunadan qayerda foydalanishingizni (kassa, ombor, ishlab chiqarish) ayting — biz mos variantni tavsiya qilamiz.',
                    'ru' => 'Конечно. Расскажите о вашей отрасли, объёме товаров или документов в день и где будет использоваться оборудование (касса, склад, производство) — мы подберём подходящий вариант.',
                    'en' => 'Of course. Tell us your industry, how many items or documents you handle per day, and where the equipment will be used (checkout, warehouse, production), and we\'ll suggest a suitable option.',
                ]],
            ['delivery', 'delivery, warranty, yetkazib berish, kafolat, доставка, гарантия',
                [
                    'uz' => 'Yetkazib berish va kafolat qanday?',
                    'ru' => 'Как с доставкой и гарантией?',
                    'en' => 'What about delivery and warranty?',
                ],
                [
                    'uz' => 'Yetkazib berish muddati va kafolat shartlari tanlangan brend va modelga bogʻliq. Menejerlarimiz ularni tijorat taklifida koʻrsatib beradi.',
                    'ru' => 'Сроки доставки и условия гарантии зависят от выбранного бренда и модели. Наши менеджеры укажут их в коммерческом предложении.',
                    'en' => 'Delivery times and warranty terms depend on the chosen brand and model. Our managers will state them in your quote.',
                ]],
        ];

        foreach ($entries as $i => [$type, $keywords, $question, $answer]) {
            KnowledgeEntry::create([
                'type' => $type,
                'question' => $question,
                'answer' => $answer,
                'keywords' => $keywords,
                'is_active' => true,
                'sort_order' => $i,
            ]);
        }
    }
}
