<?php

namespace Tests\Feature;

use App\Services\ChatAssistant;
use Database\Seeders\ArticleCategorySeeder;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZzRetrievalDebugTest extends TestCase
{
    use RefreshDatabase;

    public function test_debug(): void
    {
        $this->seed([ArticleCategorySeeder::class, DemoContentSeeder::class]);
        $sw = new \ReflectionMethod(ChatAssistant::class, 'searchWords');
        $ix = new \ReflectionMethod(ChatAssistant::class, 'knowledgeIndex');
        $out = '';
        foreach ([['en', 'Do you sell pizza delivery in Tashkent?'], ['ru', 'доставка в Самарканд и гарантия'], ['uz', 'Yetkazib berish va kafolat'], ['ru', 'маркеровка таваров'], ['uz', 'Асл белги нима? нархи канча?'], ['ru', 'Какая погода в Ташкенте завтра?'], ['en', 'Can I connect the scanner to 1C?']] as [$l, $q]) {
            $words = $sw->invoke(null, $q);
            $data = $ix->invoke(app(ChatAssistant::class), $l);
            $out .= "[$l] $q => ".implode(',', $words)."\n";
            foreach (['entries', 'articles'] as $kind) {
                $items = $data[$kind];
                $n = count($items);
                foreach ($items as $i => $item) {
                    $hits = [];
                    foreach ($words as $w) {
                        $df = count(array_filter($items, fn ($it) => in_array($w, $it['title_words'], true) || in_array($w, $it['words'], true)));
                        $idf = 0.3 + log(($n + 1) / ($df + 1));
                        $s = $idf * ((in_array($w, $item['title_words'], true) ? 3 : 0) + (in_array($w, $item['words'], true) ? 1 : 0));
                        if ($s > 0) { $hits[] = "$w=".round($s, 2); }
                    }
                    if ($hits) { $out .= "   $kind#".($i + 1).': '.implode(' ', $hits)."\n"; }
                }
            }
        }
        file_put_contents(getenv('PROBE_OUT'), $out);
        $this->assertTrue(true);
    }
}
