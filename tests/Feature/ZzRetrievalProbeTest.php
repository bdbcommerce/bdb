<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\KnowledgeEntry;
use App\Services\ChatAssistant;
use Database\Seeders\ArticleCategorySeeder;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Temporary QA probe: prints which entries/articles get picked. Deleted after QA. */
class ZzRetrievalProbeTest extends TestCase
{
    use RefreshDatabase;

    private function k(string $locale, string $q): string
    {
        $m = new \ReflectionMethod(ChatAssistant::class, 'knowledge');

        return $m->invoke(app(ChatAssistant::class), $locale, $q);
    }

    public function test_probe(): void
    {
        $this->seed([ArticleCategorySeeder::class, DemoContentSeeder::class]);
        $entryQ = KnowledgeEntry::all()->mapWithKeys(fn ($e) => [$e->id => $e->getTranslation('question', 'en')]);
        $out = "ENTRIES:\n";
        foreach ($entryQ as $id => $q) {
            $out .= "  #{$id} {$q}\n";
        }
        $out .= "ARTICLES:\n";
        foreach (Article::orderBy('id')->get() as $a) {
            $out .= "  A{$a->id} ".$a->getTranslation('title', 'en')."\n";
        }
        $titleToId = [];
        foreach (Article::all() as $a) {
            foreach (['uz', 'ru', 'en'] as $l) {
                $titleToId[$a->getTranslation('title', $l)] = $a->id;
            }
        }

        $qs = [
            ['uz', 'Uskunani taʼmirlaysizmi?'], ['uz', "Uskunani ta'mirlaysizmi?"], ['uz', 'Uskunani ta’mirlaysizmi?'], ['uz', 'Uskunani ta`mirlaysizmi?'], ['uz', 'Uskunani ta‘mirlaysizmi?'], ['uz', 'Uskunani tamirlaysizmi?'], ['ru', 'Можете привезти сканер в Самарканд?'], ['ru', 'оборудование для здравоохранения'], ['en', 'QR code on receipt'], ['ru', 'Асл Белги'], ['uz', 'Asl Belgi'], ['ru', 'Агрегация коробов и паллет'], ['uz', 'markirovka qilish uchun printer'],
            ['ru', 'Что такое Асл Белги?'],
            ['uz', 'Asl Belgi nima?'],
            ['en', 'What is Asl Belgi?'],
            ['ru', 'Сколько стоит сканер?'],
            ['uz', 'narxi qancha?'],
            ['uz', 'Асл белги нима? нархи канча?'],
            ['ru', 'маркировка лекарств datamatrix'],
            ['ru', 'маркеровка таваров'],
            ['en', 'do you sell ZT411 printer?'],
            ['en', 'Zebra TC21 terminal price'],
            ['ru', 'доставка в Самарканд и гарантия'],
            ['uz', 'Yetkazib berish va kafolat'],
            ['en', 'Can I connect the scanner to 1C?'],
            ['ru', 'ремонт принтера'],
            ['en', 'RFID for warehouse inventory'],
            ['ru', 'онлайн касса и фискальный модуль'],
            ['en', 'electronic invoice rules'],
            ['ru', 'Привет'],
            ['en', '👍👍 ok 😀'],
            ['en', 'Do you sell pizza delivery in Tashkent?'],
            ['ru', 'Какая погода в Ташкенте завтра?'],
            ['en', 'hello, how are you'],
            ['uz', 'Salom, qanday uskunalar bor? Shtrix-kod skaneri kerak'],
            ['en', 'scanner сканер skaner'],
            ['ru', '1D или 2D штрихкод что выбрать'],
            ['en', str_repeat('I need barcode scanners for my warehouse and also want marking. ', 60)],
        ];

        foreach ($qs as [$l, $q]) {
            $t = microtime(true);
            $k = $this->k($l, $q);
            $ms = round((microtime(true) - $t) * 1000, 1);
            preg_match_all('/Q \[#(\d+)\]/', $k, $m);
            $arts = [];
            if (str_contains($k, '## Articles that match')) {
                preg_match_all('/^### (.+)$/m', Str::after($k, '## Articles that match'), $am);
                $arts = array_map(fn ($t) => 'A'.($titleToId[$t] ?? '?'), $am[1]);
            }
            $out .= sprintf("[%s] %s\n   entries: %s | articles: %s | chars %d | %sms\n", $l, mb_strimwidth($q, 0, 70, '…'), implode(',', $m[1]) ?: '-', implode(',', $arts) ?: '-', mb_strlen($k), $ms);
        }
        file_put_contents(getenv('PROBE_OUT') ?: sys_get_temp_dir().'/probe.txt', $out);
        $this->assertTrue(true);
    }
}
