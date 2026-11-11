<?php

namespace Database\Seeders;

use App\Models\Vacancy;
use Illuminate\Database\Seeder;

/**
 * Two starter vacancies (Delivery driver, Sales manager) in uz/ru/en. Not run by DatabaseSeeder:
 * load them on purpose with `php artisan db:seed --class=VacancySeeder --force`.
 * Safe to re-run: a vacancy whose English title already exists is skipped.
 */
class VacancySeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->vacancies() as $vacancy) {
            if (Vacancy::where('title->en', $vacancy['title']['en'])->exists()) {
                $this->command?->info("Vacancy \"{$vacancy['title']['en']}\" already exists, skipped.");

                continue;
            }

            Vacancy::create($vacancy + ['is_active' => true]);
        }
    }

    private function vacancies(): array
    {
        $tashkent = ['uz' => 'Toshkent', 'ru' => 'Ташкент', 'en' => 'Tashkent'];

        return [
            [
                'title' => [
                    'uz' => 'Sotuv menejeri',
                    'ru' => 'Менеджер по продажам',
                    'en' => 'Sales manager',
                ],
                'department' => 'sales',
                'employment_type' => 'full_time',
                'location' => $tashkent,
                'salary' => '6 000 000 UZS',
                'sort_order' => 10,
                'description' => [
                    'uz' => "Chakana savdo, omborlar va ishlab chiqarish korxonalariga shtrix-kod skanerlari, yorliq printerlari, maʼlumot yigʻish terminallari va RFID yechimlarini sotuvchi jamoamizga sotuv menejeri kerak. Siz mijozlar bilan toʻgʻridan-toʻgʻri ishlaysiz: ehtiyojni aniqlaysiz, mos uskunani tanlaysiz va bitimni yopishgacha olib borasiz.\nIsh jadvali: haftasiga 5 kun, dushanbadan jumagacha.",
                    'ru' => "В команду, которая поставляет сканеры штрихкодов, принтеры этикеток, терминалы сбора данных и RFID-решения для розницы, складов и производств, нужен менеджер по продажам. Вы работаете с клиентами напрямую: выясняете задачу, подбираете оборудование и ведёте сделку до закрытия.\nГрафик: 5 дней в неделю, с понедельника по пятницу.",
                    'en' => "We are looking for a sales manager to join our team, which supplies barcode scanners, label printers, data collection terminals and RFID solutions to retailers, warehouses and manufacturers. You will work with customers directly: understand their needs, choose the right equipment and take the deal through to closing.\nSchedule: 5 days a week, Monday to Friday.",
                ],
                'requirements' => [
                    'uz' => "B2B sotuvda kamida 1 yillik tajriba (texnika yoki uskunalar sotuvi afzal)\nOʻzbek va rus tillarini erkin bilish\nMijozlar bilan muloqot va muzokara olib borish koʻnikmasi\nKompyuter, Excel va CRM tizimlari bilan ishlay olish\nMasʼuliyat, natijaga yoʻnaltirilganlik va oʻrganishga tayyorlik",
                    'ru' => "Опыт B2B-продаж от 1 года (продажи техники или оборудования — преимущество)\nСвободное владение узбекским и русским языками\nНавыки общения с клиентами и ведения переговоров\nУверенный пользователь ПК, Excel и CRM-систем\nОтветственность, ориентация на результат и готовность учиться",
                    'en' => "At least 1 year of B2B sales experience (selling equipment or hardware is a plus)\nFluent Uzbek and Russian\nStrong communication and negotiation skills\nConfident with a computer, Excel and CRM systems\nResponsible, results-driven and willing to learn",
                ],
                'offer' => [
                    'uz' => "Oylik maosh: 6 000 000 soʻm\nHaftasiga 5 kunlik ish, dushanbadan jumagacha\nRasmiy ishga joylashtirish\nMahsulotlar va texnologiyalar boʻyicha oʻqitish\nJahon brendlari bilan ishlash va kasbiy oʻsish imkoniyati",
                    'ru' => "Заработная плата: 6 000 000 сум в месяц\nПятидневная рабочая неделя, с понедельника по пятницу\nОфициальное трудоустройство\nОбучение по продукции и технологиям\nРабота с мировыми брендами и возможность профессионального роста",
                    'en' => "Salary: 6,000,000 UZS per month\n5-day working week, Monday to Friday\nOfficial employment\nTraining on our products and technologies\nWork with leading global brands and opportunities for professional growth",
                ],
            ],
            [
                'title' => [
                    'uz' => 'Haydovchi-ekspeditor',
                    'ru' => 'Водитель-экспедитор',
                    'en' => 'Delivery driver',
                ],
                'department' => 'warehouse',
                'employment_type' => 'full_time',
                'location' => $tashkent,
                'salary' => '4 000 000 UZS',
                'sort_order' => 20,
                'description' => [
                    'uz' => "Mijozlarimizga uskunalar, yorliqlar va sarf materiallarini Toshkent boʻylab yetkazib beruvchi haydovchi kerak. Siz buyurtmalarni ombordan qabul qilasiz, ularni oʻz vaqtida va butunligicha topshirasiz hamda hujjatlarni rasmiylashtirasiz.\nIsh jadvali: haftasiga 5 kun, dushanbadan jumagacha.",
                    'ru' => "Нужен водитель для доставки оборудования, этикеток и расходных материалов нашим клиентам по Ташкенту. Вы получаете заказы на складе, доставляете их вовремя и в сохранности, оформляете сопроводительные документы.\nГрафик: 5 дней в неделю, с понедельника по пятницу.",
                    'en' => "We need a driver to deliver equipment, labels and consumables to our customers across Tashkent. You will collect orders from the warehouse, deliver them on time and intact, and handle the delivery paperwork.\nSchedule: 5 days a week, Monday to Friday.",
                ],
                'requirements' => [
                    'uz' => "B toifali haydovchilik guvohnomasi va kamida 2 yillik haydash tajribasi\nToshkent shahrini yaxshi bilish\nOʻzbek tilini erkin, rus tilini soʻzlashuv darajasida bilish\nAniqlik, vaqtga rioya qilish va masʼuliyat\nYuklarni ehtiyotkorlik bilan tashish va yuklash-tushirishda yordam berishga tayyorlik",
                    'ru' => "Водительское удостоверение категории B и стаж вождения от 2 лет\nХорошее знание Ташкента\nСвободный узбекский, русский на разговорном уровне\nАккуратность, пунктуальность и ответственность\nГотовность бережно перевозить грузы и помогать с погрузкой и разгрузкой",
                    'en' => "Category B driving licence and at least 2 years of driving experience\nGood knowledge of Tashkent\nFluent Uzbek and conversational Russian\nCareful, punctual and responsible\nReady to handle goods with care and help with loading and unloading",
                ],
                'offer' => [
                    'uz' => "Oylik maosh: 4 000 000 soʻm\nHaftasiga 5 kunlik ish, dushanbadan jumagacha\nRasmiy ishga joylashtirish\nBarqaror ish va doʻstona jamoa",
                    'ru' => "Заработная плата: 4 000 000 сум в месяц\nПятидневная рабочая неделя, с понедельника по пятницу\nОфициальное трудоустройство\nСтабильная работа и дружный коллектив",
                    'en' => "Salary: 4,000,000 UZS per month\n5-day working week, Monday to Friday\nOfficial employment\nA stable job and a friendly team",
                ],
            ],
        ];
    }
}
