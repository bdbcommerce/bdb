<?php

namespace Database\Seeders;

use App\Models\KnowledgeEntry;
use Illuminate\Database\Seeder;

/**
 * Starter AI knowledge base: general answers about BDB, Asl Belgi, pricing, service and delivery.
 * Not demo data, but not seeded on production automatically either: review the answers
 * (they are what the assistant tells customers), then load them on the server with
 *
 * php artisan db:seed --class=KnowledgeBaseSeeder --force
 *
 * Skipped when the knowledge base already has entries.
 */
class KnowledgeBaseSeeder extends Seeder
{
    public function run(): void
    {
        if (KnowledgeEntry::count() > 0) {
            $this->command?->info('Knowledge entries already exist, skipping the starter knowledge base.');

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
