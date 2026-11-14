<?php

namespace Database\Seeders;

use App\Models\Brand;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * The brands BDB supplies, with logo (database/seeders/data/brand-logos, transparent PNG),
 * official website (when known) and a short description in uz/ru/en. Not run by DatabaseSeeder:
 * load on purpose with `php artisan db:seed --class=BrandSeeder --force`.
 *
 * Safe to re-run. A brand is matched by name (any language, case-insensitive):
 * - new brands are created, active, at the end of the list;
 * - existing brands get the website below, the logo when they have none (or its file is missing),
 *   and a description only in languages where it is empty — admin edits are kept.
 */
class BrandSeeder extends Seeder
{
    public function run(): void
    {
        $brands = Brand::all();
        $disk = Storage::disk('public');

        foreach ($this->brands() as $slug => $data) {
            $brand = $brands->first(fn (Brand $b) => collect($b->getTranslations('name'))
                ->contains(fn ($n) => mb_strtolower(trim((string) $n)) === mb_strtolower($data['name'])));

            // Keep a logo the admin uploaded; (re)place a missing one or one this seeder put there before.
            $own = $brand?->logo && $brand->logo !== "brands/{$slug}.png" && $disk->exists($brand->logo);
            $logo = $own ? $brand->logo : ($this->storeLogo($slug) ?? ($brand?->logo && $disk->exists($brand->logo) ? $brand->logo : null));

            if (! $brand) {
                $brands->push(Brand::create([
                    'name' => ['uz' => $data['name'], 'ru' => $data['name'], 'en' => $data['name']],
                    'description' => $data['description'],
                    'website' => $data['website'],
                    'logo' => $logo,
                    'sort_order' => (int) Brand::max('sort_order') + 1,
                    'is_active' => true,
                ]));
                $this->command?->info("Brand \"{$data['name']}\" created.");

                continue;
            }

            foreach ($data['description'] as $locale => $text) {
                if (trim((string) $brand->getTranslation('description', $locale, false)) === '') {
                    $brand->setTranslation('description', $locale, $text);
                }
            }
            $brand->website = $data['website'] ?? $brand->website; // no known site: keep the admin's
            $brand->logo = $logo;
            $brand->save();
            $this->command?->info("Brand \"{$data['name']}\" updated.");
        }
    }

    /** Copies the bundled logo to the public disk (brands/<slug>.png) and returns its path. */
    private function storeLogo(string $slug): ?string
    {
        $source = database_path("seeders/data/brand-logos/{$slug}.png");
        if (! is_file($source)) {
            return null;
        }
        $path = "brands/{$slug}.png";
        Storage::disk('public')->put($path, file_get_contents($source));

        return $path;
    }

    /** Keyed by logo file name (database/seeders/data/brand-logos/<key>.png). */
    private function brands(): array
    {
        return [
            'zebra' => [
                'name' => 'Zebra Technologies',
                'website' => 'https://www.zebra.com',
                'description' => [
                    'uz' => 'Yorliq va chek printerlari, mobil terminallar va skanerlar.',
                    'ru' => 'Принтеры этикеток и чеков, мобильные терминалы и сканеры.',
                    'en' => 'Label and receipt printers, mobile computers and scanners.',
                ],
            ],
            'honeywell' => [
                'name' => 'Honeywell',
                'website' => 'https://automation.honeywell.com',
                'description' => [
                    'uz' => 'Shtrix-kod skanerlari, maʼlumot yigʻish terminallari va printerlar.',
                    'ru' => 'Сканеры штрихкодов, терминалы сбора данных и принтеры.',
                    'en' => 'Barcode scanners, data collection terminals and printers.',
                ],
            ],
            'datalogic' => [
                'name' => 'Datalogic',
                'website' => 'https://www.datalogic.com',
                'description' => [
                    'uz' => 'Chakana savdo va ombor uchun skanerlar va mobil kompyuterlar.',
                    'ru' => 'Сканеры и мобильные компьютеры для розницы и склада.',
                    'en' => 'Scanners and mobile computers for retail and warehousing.',
                ],
            ],
            'tsc-printronix' => [
                'name' => 'TSC Printronix',
                'website' => 'https://www.tscprinters.com',
                'description' => [
                    'uz' => 'Sanoat va ish stoli yorliq printerlari.',
                    'ru' => 'Промышленные и настольные принтеры этикеток.',
                    'en' => 'Industrial and desktop label printers.',
                ],
            ],
            'godex' => [
                'name' => 'Godex',
                'website' => 'https://www.godexintl.com',
                'description' => [
                    'uz' => 'Yorliq chop etish uchun qulay narxdagi printerlar.',
                    'ru' => 'Доступные принтеры для печати этикеток.',
                    'en' => 'Cost-effective label printers.',
                ],
            ],
            'urovo' => [
                'name' => 'Urovo',
                'website' => 'https://www.urovo.com',
                'description' => [
                    'uz' => 'Android asosidagi maʼlumot yigʻish terminallari.',
                    'ru' => 'Терминалы сбора данных на Android.',
                    'en' => 'Android data collection terminals.',
                ],
            ],
            'argox' => [
                'name' => 'Argox',
                'website' => 'https://www.argox.com',
                'description' => [
                    'uz' => 'Ish stoli va sanoat shtrix-kod yorliq printerlari hamda skanerlar.',
                    'ru' => 'Настольные и промышленные принтеры этикеток и сканеры штрихкодов.',
                    'en' => 'Desktop and industrial barcode label printers and scanners.',
                ],
            ],
            'sprt' => [
                'name' => 'SPRT',
                'website' => 'https://www.sprt-printer.com',
                'description' => [
                    'uz' => 'Chek va yorliq termoprinterlari, koʻchma va kiosk printerlari.',
                    'ru' => 'Термопринтеры чеков и этикеток, мобильные и киосковые принтеры.',
                    'en' => 'Thermal receipt and label printers, portable and kiosk printers.',
                ],
            ],
            'henex' => [
                'name' => 'HENEX',
                'website' => 'https://www.henex.cn',
                'description' => [
                    'uz' => 'Chakana savdo va logistika uchun simli, simsiz va Bluetooth shtrix-kod skanerlari.',
                    'ru' => 'Проводные, беспроводные и Bluetooth-сканеры штрихкодов для розницы и логистики.',
                    'en' => 'Wired, wireless and Bluetooth barcode scanners for retail and logistics.',
                ],
            ],
            'xprinter' => [
                'name' => 'XPRINTER',
                'website' => 'https://www.xprintertech.com',
                'description' => [
                    'uz' => 'Doʻkonlar, kafelar va omborlar uchun chek va yorliq termoprinterlari.',
                    'ru' => 'Термопринтеры чеков и этикеток для магазинов, кафе и складов.',
                    'en' => 'Thermal receipt and label printers for shops, cafés and warehouses.',
                ],
            ],
            'gainscha' => [
                'name' => 'GAINSCHA',
                'website' => 'https://www.gainscha.com.tw',
                'description' => [
                    'uz' => 'Shtrix-kod yorliq printerlari — ish stolidan sanoat modellarigacha.',
                    'ru' => 'Принтеры этикеток со штрихкодом — от настольных до промышленных моделей.',
                    'en' => 'Barcode label printers, from desktop to industrial models.',
                ],
            ],
            'hprt' => [ // no logo bundled yet: the name is shown until one is uploaded in Admin → Brands
                'name' => 'HPRT',
                'website' => 'https://www.hprt.com',
                'description' => [
                    'uz' => 'Chek va yorliq printerlari, koʻchma printerlar va shtrix-kod skanerlari.',
                    'ru' => 'Принтеры чеков и этикеток, мобильные принтеры и сканеры штрихкодов.',
                    'en' => 'Receipt, label and mobile printers and barcode scanners.',
                ],
            ],
            'jassway' => [
                'name' => 'JASSWAY',
                'website' => 'https://www.jassway.com',
                'description' => [
                    'uz' => 'Chakana savdo va restoranlar uchun POS-terminallar va sensorli kassa monobloklari.',
                    'ru' => 'POS-терминалы и сенсорные кассовые моноблоки для розницы и ресторанов.',
                    'en' => 'POS terminals and touch-screen cash registers for retail and restaurants.',
                ],
            ],
            'rongta' => [
                'name' => 'Rongta',
                'website' => 'https://www.rongtatech.com',
                'description' => [
                    'uz' => 'Chakana savdo va biznes uchun chek va yorliq termoprinterlari.',
                    'ru' => 'Термопринтеры чеков и этикеток для розницы и бизнеса.',
                    'en' => 'Thermal receipt and label printers for retail and business.',
                ],
            ],
            'bixolon' => [
                'name' => 'Bixolon',
                'website' => 'https://www.bixolon.com',
                'description' => [
                    'uz' => 'POS, koʻchma va yorliq printerlari.',
                    'ru' => 'POS-принтеры, мобильные принтеры и принтеры этикеток.',
                    'en' => 'POS, mobile and label printers.',
                ],
            ],
            'newland' => [
                'name' => 'Newland',
                'website' => 'https://www.newland-id.com',
                'description' => [
                    'uz' => 'Qoʻl va sanoat shtrix-kod skanerlari, skanerlash modullari va mobil kompyuterlar.',
                    'ru' => 'Ручные и промышленные сканеры штрихкодов, сканирующие модули и мобильные компьютеры.',
                    'en' => 'Handheld and industrial barcode scanners, scan engines and mobile computers.',
                ],
            ],
            'chainway' => [
                'name' => 'Chainway',
                'website' => 'https://www.chainway.net',
                'description' => [
                    'uz' => 'RFID oʻquvchilar, qoʻl terminallari va mustahkam planshetlar.',
                    'ru' => 'RFID-считыватели, мобильные терминалы и защищённые планшеты.',
                    'en' => 'RFID readers, handheld computers and rugged tablets.',
                ],
            ],
            'asianwell' => [ // no own website or logo found (only an Alibaba store)
                'name' => 'Asianwell',
                'website' => null,
                'description' => [
                    'uz' => 'Shtrix-kod skanerlari, maʼlumot yigʻish terminallari, yorliq va chek printerlari.',
                    'ru' => 'Сканеры штрихкодов, терминалы сбора данных, принтеры этикеток и чеков.',
                    'en' => 'Barcode scanners, data collectors, label and receipt printers.',
                ],
            ],
            'lenovo' => [
                'name' => 'Lenovo',
                'website' => 'https://www.lenovo.com',
                'description' => [
                    'uz' => 'Biznes uchun noutbuklar, kompyuterlar, planshetlar va monitorlar.',
                    'ru' => 'Ноутбуки, настольные компьютеры, планшеты и мониторы для бизнеса.',
                    'en' => 'Laptops, desktops, tablets and monitors for business.',
                ],
            ],
        ];
    }
}
