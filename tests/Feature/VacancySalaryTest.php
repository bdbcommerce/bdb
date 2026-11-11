<?php

namespace Tests\Feature;

use App\Models\Vacancy;
use App\Services\ChatAssistant;
use Database\Seeders\VacancySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Salary in the JobPosting JSON-LD (Google Jobs) and the vacancy details in the AI knowledge. */
class VacancySalaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_salary_range_is_parsed_from_free_text(): void
    {
        $m = fn (?int $min, ?int $max, string $currency = 'UZS', string $unit = 'MONTH') => compact('min', 'max', 'currency', 'unit');
        $cases = [
            '6 000 000 UZS' => $m(6000000, 6000000),
            '4 000 000 soʻm' => $m(4000000, 4000000),
            "5\u{00A0}000\u{00A0}000 – 7 000 000 сум" => $m(5000000, 7000000),
            'от 5 000 000 до 7 000 000 сум' => $m(5000000, 7000000),
            '6 mln' => $m(6000000, 6000000),
            '4,5 млн' => $m(4500000, 4500000),
            '4.5 миллиона' => $m(4500000, 4500000),
            '5–7 млн' => $m(5000000, 7000000),
            'от 5 до 7 млн' => $m(5000000, 7000000),
            '1 500 mln' => $m(1500000000, 1500000000),
            '6,000,000.00 UZS' => $m(6000000, 6000000),
            '6 000 000,00 сум' => $m(6000000, 6000000),
            'до 8 000 000' => $m(null, 8000000),
            '8 mln gacha' => $m(null, 8000000),
            'from 3 000 000' => $m(3000000, null),
            '$500–800' => $m(500, 800, 'USD'),
            '500 у.е.' => $m(500, 500, 'USD'),
            '300 000 per week' => $m(300000, 300000, 'UZS', 'WEEK'),
            '50 000 сум в день' => $m(50000, 50000, 'UZS', 'DAY'),
            '2 shifts, 6,000,000 UZS' => $m(6000000, 6000000),
            'From 2026 salary 6 000 000' => $m(6000000, null),
            '6 000 000, call 90 123 45 67' => null,
            'Tel: +998 90 123 45 67' => null,
            '+998901234567' => null,
            str_repeat('9', 40) => null,
            'By agreement' => null,
            'Kelishilgan holda' => null,
            '' => null,
        ];

        foreach ($cases as $salary => $expected) {
            $this->assertSame($expected, (new Vacancy(['salary' => $salary]))->salaryRange(), "salary: {$salary}");
        }
    }

    public function test_job_posting_has_base_salary(): void
    {
        $this->seed(VacancySeeder::class);

        foreach (['uz', 'ru', 'en'] as $locale) {
            $html = $this->get(route('site.careers', $locale))->assertOk()->getContent();
            preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
            $postings = collect($m[1])->map(fn ($j) => json_decode($j, true))
                ->flatMap(fn ($d) => array_is_list($d ?? []) ? $d : [$d])
                ->where('@type', 'JobPosting')->keyBy(fn ($p) => $p['baseSalary']['value']['value'] ?? null);

            $this->assertSame(['@type' => 'MonetaryAmount', 'currency' => 'UZS',
                'value' => ['@type' => 'QuantitativeValue', 'value' => 6000000, 'unitText' => 'MONTH']], $postings[6000000]['baseSalary'], $locale);
            $this->assertArrayHasKey(4000000, $postings->all(), $locale);
        }
    }

    public function test_job_posting_without_a_parseable_salary_has_no_base_salary(): void
    {
        Vacancy::create(['title' => ['en' => 'Engineer'], 'department' => 'service', 'employment_type' => 'full_time', 'salary' => 'By agreement', 'is_active' => true]);

        $html = $this->get(route('site.careers', 'en'))->assertOk()->getContent();
        $this->assertStringContainsString('JobPosting', $html);
        $this->assertStringNotContainsString('baseSalary', $html);
    }

    public function test_ai_knowledge_has_salary_schedule_and_location(): void
    {
        $this->seed(VacancySeeder::class);

        $prompt = [
            'uz' => app(ChatAssistant::class)->promptPreview('uz', 'web'),
            'ru' => app(ChatAssistant::class)->promptPreview('ru', 'web'),
            'en' => app(ChatAssistant::class)->promptPreview('en', 'web'),
        ];

        $this->assertStringContainsString('salary 4 000 000 UZS', $prompt['en']);
        $this->assertStringContainsString('salary 6 000 000 UZS', $prompt['en']);
        $this->assertStringContainsString('5-day working week', $prompt['en']);
        $this->assertStringContainsString('Tashkent', $prompt['en']);
        $this->assertStringContainsString('Category B driving licence', $prompt['en']);
        $this->assertStringContainsString('Ташкент', $prompt['ru']);
        $this->assertStringContainsString('Toshkent', $prompt['uz']);
    }
}
