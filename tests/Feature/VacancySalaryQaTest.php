<?php

namespace Tests\Feature;

use App\Models\Vacancy;
use App\Services\ChatAssistant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** QA: JobPosting baseSalary JSON-LD in every locale, AI knowledge cache flush and prompt size with many vacancies. */
class VacancySalaryQaTest extends TestCase
{
    use RefreshDatabase;

    private function postings(string $locale): array
    {
        $html = $this->get(route('site.careers', $locale))->assertOk()->getContent();
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        $postings = [];
        foreach ($m[1] as $json) {
            $data = json_decode($json, true);
            $this->assertSame(JSON_ERROR_NONE, json_last_error(), "valid JSON-LD ({$locale})");
            foreach (array_is_list($data) ? $data : [$data] as $item) {
                if (($item['@type'] ?? null) === 'JobPosting') {
                    $postings[$item['title']] = $item;
                }
            }
        }

        return $postings;
    }

    public function test_base_salary_range_and_usd_in_every_locale(): void
    {
        $title = fn ($s) => ['uz' => "Lavozim {$s}", 'ru' => "Должность {$s}", 'en' => "Position {$s}"];
        Vacancy::create(['title' => $title('A'), 'department' => 'sales', 'employment_type' => 'full_time', 'salary' => 'от 5 000 000 до 7 000 000 сум', 'is_active' => true]);
        Vacancy::create(['title' => $title('B'), 'department' => 'software', 'employment_type' => 'contract', 'salary' => '$1,500', 'is_active' => true]);
        Vacancy::create(['title' => $title('C'), 'department' => 'admin', 'employment_type' => 'part_time', 'salary' => null, 'is_active' => true]);

        foreach (['uz' => 'Lavozim', 'ru' => 'Должность', 'en' => 'Position'] as $locale => $word) {
            $p = $this->postings($locale);
            $this->assertCount(3, $p, $locale);

            $this->assertSame(['@type' => 'MonetaryAmount', 'currency' => 'UZS', 'value' => [
                '@type' => 'QuantitativeValue', 'minValue' => 5000000, 'maxValue' => 7000000, 'unitText' => 'MONTH',
            ]], $p["{$word} A"]['baseSalary'], $locale);

            $this->assertSame(['@type' => 'MonetaryAmount', 'currency' => 'USD', 'value' => [
                '@type' => 'QuantitativeValue', 'value' => 1500, 'unitText' => 'MONTH',
            ]], $p["{$word} B"]['baseSalary'], $locale);

            $this->assertArrayNotHasKey('baseSalary', $p["{$word} C"], $locale);
            // Numbers, not strings (Google expects Number for value/minValue/maxValue).
            $this->assertIsInt($p["{$word} A"]['baseSalary']['value']['minValue']);
        }
    }

    public function test_knowledge_cache_is_flushed_when_a_vacancy_is_saved_or_deleted(): void
    {
        $v = Vacancy::create(['title' => ['en' => 'Engineer'], 'department' => 'service', 'employment_type' => 'full_time', 'salary' => '3 000 000 UZS', 'is_active' => true]);
        $ai = app(ChatAssistant::class);
        $this->assertStringContainsString('salary 3 000 000 UZS', $ai->promptPreview('en', 'web'));

        $v->update(['salary' => '9 000 000 UZS', 'requirements' => ['en' => "- Driving licence\n- Russian"]]);
        $prompt = $ai->promptPreview('en', 'web');
        $this->assertStringContainsString('salary 9 000 000 UZS', $prompt);
        $this->assertStringContainsString('Requirements: Driving licence; Russian.', $prompt);
        $this->assertStringNotContainsString('3 000 000', $prompt);

        $v->delete();
        $this->assertStringNotContainsString('Engineer', $ai->promptPreview('en', 'web'));
    }

    public function test_prompt_size_with_fifteen_long_vacancies(): void
    {
        $ai = app(ChatAssistant::class);
        $before = mb_strlen($ai->promptPreview('ru', 'web'));

        // Typical long vacancy: ~1000-char description, 8 requirements and 6 offer lines of ~80 chars.
        $line = fn ($i) => "Пункт {$i}: ".str_repeat('опыт работы с кассовыми аппаратами ', 2);
        for ($i = 1; $i <= 15; $i++) {
            Vacancy::create([
                'title' => ['ru' => "Вакансия {$i}"], 'department' => 'sales', 'employment_type' => 'full_time',
                'location' => ['ru' => 'Ташкент'], 'salary' => 'от 5 000 000 до 7 000 000 сум + KPI',
                'description' => ['ru' => str_repeat('Описание вакансии с подробностями. ', 30)],
                'requirements' => ['ru' => implode("\n", array_map($line, range(1, 8)))],
                'offer' => ['ru' => implode("\n", array_map($line, range(1, 6)))],
                'is_active' => true,
            ]);
        }
        $typical = mb_strlen($ai->promptPreview('ru', 'web'));

        // Admin-allowed maximum: 5000 chars each for requirements and offer, 120 for salary, 200 for location.
        Vacancy::query()->delete();
        for ($i = 1; $i <= 15; $i++) {
            Vacancy::create([
                'title' => ['ru' => str_repeat('В', 200)], 'department' => 'sales', 'employment_type' => 'full_time',
                'location' => ['ru' => str_repeat('Т', 200)], 'salary' => str_repeat('5', 120),
                'description' => ['ru' => str_repeat('о', 5000)],
                'requirements' => ['ru' => implode("\n", array_fill(0, 50, str_repeat('т', 99)))],
                'offer' => ['ru' => implode("\n", array_fill(0, 50, str_repeat('п', 99)))],
                'is_active' => true,
            ]);
        }
        $max = mb_strlen($ai->promptPreview('ru', 'web'));

        fwrite(STDERR, "\n[vacancies prompt] base {$before}, +15 typical ".($typical - $before).', +15 max '.($max - $before)." chars\n");
        $this->assertGreaterThan($before, $typical);
    }
}
