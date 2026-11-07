<?php

namespace Database\Seeders;

use App\Models\Banner;
use Illuminate\Database\Seeder;

/**
 * Seeds the home page hero slides as editable banners: the default hero
 * (copy from lang/{uz,ru,en}/site.php) plus RFID, fiscal receipt and
 * receipt/label printer slides. Each slide is skipped if a banner with the
 * same English title already exists, so the seeder is safe to re-run.
 */
class HeroBannerSeeder extends Seeder
{
    private const LOCALES = ['uz', 'ru', 'en'];

    public function run(): void
    {
        $t = fn (string $key) => collect(self::LOCALES)
            ->mapWithKeys(fn (string $lc) => [$lc => trans($key, [], $lc)])
            ->all();

        $quote = $t('site.cta.contact');

        $banners = [
            [
                'badge' => $t('site.hero.badge'),
                'title' => $t('site.hero.title'),
                'subtitle' => $t('site.hero.lead'),
                'points' => collect(self::LOCALES)
                    ->mapWithKeys(fn (string $lc) => [$lc => implode("\n", (array) trans('site.hero.points', [], $lc))])
                    ->all(),
                'button_text' => $quote,
                'button_url' => '/contact',
                'show_ai_button' => true,
                'illustration' => Banner::DEFAULT_ILLUSTRATION,
                'sort_order' => 0,
            ],
            [
                'badge' => [
                    'uz' => 'RFID · UHF metkalar · RFID oʻquvchilar',
                    'ru' => 'RFID · UHF-метки · Считыватели',
                    'en' => 'RFID · UHF tags · Readers',
                ],
                'title' => [
                    'uz' => 'RFID bilan inventarizatsiya kunlarda emas, daqiqalarda',
                    'ru' => 'RFID: инвентаризация за минуты, а не за дни',
                    'en' => 'RFID stock counts in minutes, not days',
                ],
                'subtitle' => [
                    'uz' => 'Omborlar, chakana savdo va ishlab chiqarish uchun UHF oʻquvchilar, antennalar va metkalar. Yuzlab mahsulotlar bir vaqtda, bevosita koʻrinmasa ham oʻqiladi va qoldiqlar doim aniq boʻladi.',
                    'ru' => 'UHF-считыватели, антенны и метки для складов, розницы и производства. Сотни товаров считываются одновременно и без прямой видимости, а остатки всегда точны.',
                    'en' => 'UHF readers, antennas and tags for warehouses, retail and production. Read hundreds of items at once without line of sight and keep stock levels accurate.',
                ],
                'points' => [
                    'uz' => "RFID oʻquvchilar, antennalar va metkalar\nObyektni oʻrganish va pilot loyiha\nHisob tizimingiz bilan integratsiya",
                    'ru' => "Считыватели, антенны и метки\nОбследование объекта и пилотный проект\nИнтеграция с вашей учётной системой",
                    'en' => "Readers, antennas and tags\nSite survey and pilot project\nIntegration with your accounting system",
                ],
                'button_text' => $quote,
                'button_url' => '/contact',
                'show_ai_button' => true,
                'illustration' => 'rfid',
                'sort_order' => 1,
            ],
            [
                'badge' => [
                    'uz' => 'Onlayn kassa · Fiskal chek · Markirovka',
                    'ru' => 'Онлайн-касса · Фискальный чек · Маркировка',
                    'en' => 'Online cash register · Fiscal receipt · Marking',
                ],
                'title' => [
                    'uz' => 'Har bir savdo uchun fiskal chek — talablarga muvofiq va tez',
                    'ru' => 'Фискальный чек на каждую продажу — быстро и по правилам',
                    'en' => 'A compliant fiscal receipt for every sale',
                ],
                'subtitle' => [
                    'uz' => 'Kassa uskunalari, fiskal modullar va skanerlarni yetkazib beramiz va sozlaymiz: chek fiskal QR-kod bilan chiqadi, markirovka kodi kassada tekshiriladi, maʼlumotlar soliq xizmatiga yuboriladi.',
                    'ru' => 'Поставляем и настраиваем кассовое оборудование, фискальные модули и сканеры: чек печатается с фискальным QR-кодом, код маркировки проверяется на кассе, данные уходят в налоговую службу.',
                    'en' => 'We supply and set up point-of-sale hardware, fiscal modules and scanners: receipts print with a fiscal QR code, marking codes are checked at the till and data goes to the tax service.',
                ],
                'points' => [
                    'uz' => "Onlayn kassa va fiskal modullar\nKassada markirovka kodini tekshirish\nOʻrnatish, sozlash va servis",
                    'ru' => "Онлайн-кассы и фискальные модули\nПроверка кода маркировки на кассе\nУстановка, настройка и сервис",
                    'en' => "Online cash registers and fiscal modules\nMarking code checks at the till\nInstallation, setup and service",
                ],
                'button_text' => $quote,
                'button_url' => '/contact',
                'show_ai_button' => true,
                'illustration' => 'fiscal',
                'sort_order' => 2,
            ],
            [
                'badge' => [
                    'uz' => 'Chek printerlari · Etiketkalar · Shtrix-kod',
                    'ru' => 'Чековые принтеры · Этикетки · Штрихкод',
                    'en' => 'Receipt printers · Labels · Barcodes',
                ],
                'title' => [
                    'uz' => 'Chek va etiketka printerlari uzluksiz ish uchun',
                    'ru' => 'Чековые и этикеточные принтеры для работы без простоев',
                    'en' => 'Receipt and label printers built for nonstop work',
                ],
                'subtitle' => [
                    'uz' => 'Kassalar uchun termal chek printerlari, ombor va ishlab chiqarish uchun etiketka printerlari, shuningdek chek lentasi, etiketkalar va ribbonlar. Shtrix-kod va GS1 kodlari aniq chop etiladi.',
                    'ru' => 'Термопринтеры чеков для касс, принтеры этикеток для склада и производства, а также чековая лента, этикетки и риббоны. Штрихкоды и коды GS1 печатаются чётко и читаются с первого раза.',
                    'en' => 'Thermal receipt printers for checkouts, label printers for warehouses and production, plus receipt rolls, labels and ribbons. Barcodes and GS1 codes print sharp and scan first time.',
                ],
                'points' => [
                    'uz' => "Chek va etiketka printerlari\nChek lentasi, etiketkalar va ribbonlar\nTaʼmirlash va ehtiyot qismlar",
                    'ru' => "Чековые и этикеточные принтеры\nЧековая лента, этикетки и риббоны\nРемонт и запчасти",
                    'en' => "Receipt and label printers\nReceipt rolls, labels and ribbons\nRepair and spare parts",
                ],
                'button_text' => $quote,
                'button_url' => '/contact',
                'show_ai_button' => true,
                'illustration' => 'printer',
                'sort_order' => 3,
            ],
        ];

        foreach ($banners as $banner) {
            if (Banner::where('title->en', $banner['title']['en'])->exists()) {
                $this->command?->info("Hero banner \"{$banner['title']['en']}\" already exists, skipping.");

                continue;
            }

            Banner::create($banner + ['image' => null, 'is_active' => true]);
        }
    }
}
