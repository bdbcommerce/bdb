<?php

namespace Tests\Feature;

use App\Models\JobApplication;
use App\Models\Vacancy;
use App\Services\ChatAssistant;
use Database\Seeders\VacancySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class VacancySeederQaTest extends TestCase
{
    use RefreshDatabase;

    private const LOCALES = ['uz', 'ru', 'en'];

    public function test_existing_vacancies_and_applications_are_left_untouched(): void
    {
        $other = Vacancy::create(['title' => ['en' => 'Engineer', 'uz' => 'Muhandis'], 'department' => 'service', 'is_active' => false]);
        // Same English title as a seeded one, edited by staff (other locales, inactive): must not be overwritten or duplicated.
        $edited = Vacancy::create(['title' => ['en' => 'Sales manager', 'ru' => 'Свой заголовок'], 'department' => 'sales', 'salary' => '1', 'is_active' => false]);
        $app = JobApplication::create(['vacancy_id' => $edited->id, 'vacancy_title' => 'Sales manager', 'name' => 'A', 'phone' => '+998901234567', 'cv_path' => 'cvs/x.pdf', 'cv_name' => 'x.pdf']);

        $this->seed(VacancySeeder::class);
        $this->seed(VacancySeeder::class);

        $this->assertSame(3, Vacancy::count());
        $this->assertSame(1, Vacancy::where('title->en', 'Sales manager')->count());
        $edited->refresh();
        $this->assertSame('Свой заголовок', $edited->getTranslation('title', 'ru', false));
        $this->assertSame('1', $edited->salary);
        $this->assertFalse($edited->is_active);
        $this->assertSame($edited->id, $app->fresh()->vacancy_id);
        $this->assertFalse($other->fresh()->is_active);
        $this->assertSame(1, Vacancy::where('title->en', 'Delivery driver')->count());
    }

    public function test_fields_departments_and_lines_are_clean_in_every_locale(): void
    {
        $this->seed(VacancySeeder::class);
        $expected = ['Sales manager' => [5, 5], 'Delivery driver' => [5, 4]];

        foreach (Vacancy::all() as $v) {
            $this->assertContains($v->department, Vacancy::DEPARTMENTS);
            $this->assertArrayHasKey($v->employment_type, Vacancy::SCHEMA_TYPES);
            $this->assertTrue($v->is_active);
            [$req, $off] = $expected[$v->getTranslation('title', 'en')];

            foreach (self::LOCALES as $l) {
                foreach ($v->translatable as $f) {
                    $raw = $v->getTranslation($f, $l, false);
                    $this->assertNotSame('', trim($raw), "$f/$l");
                    $this->assertSame($raw, trim($raw), "$f/$l has outer whitespace");
                    $this->assertStringNotContainsString("\n\n", $raw, "$f/$l has an empty line");
                    $this->assertStringNotContainsString("\r", $raw);
                    $this->assertStringNotContainsString('  ', $raw, "$f/$l double space");
                    if ($l === 'uz') {
                        $this->assertDoesNotMatchRegularExpression('/\p{Cyrillic}/u', $raw, "uz $f has Cyrillic");
                        $this->assertDoesNotMatchRegularExpression("/['`‘’]/u", $raw, "uz $f uses a non-standard apostrophe");
                        // oʻ / gʻ use U+02BB, other apostrophes U+02BC
                        $this->assertDoesNotMatchRegularExpression('/[^oOgG]\x{02BB}/u', $raw, "uz $f: ʻ not after o/g");
                        $this->assertDoesNotMatchRegularExpression('/[oOgG]\x{02BC}/u', $raw, "uz $f: ʼ after o/g");
                    }
                    if ($l === 'ru') {
                        // Latin letters only in the allowed tokens
                        $latin = preg_replace('/\b(B2B|RFID|CRM|Excel|B)\b/u', '', $raw);
                        $this->assertDoesNotMatchRegularExpression('/\p{Latin}/u', $latin, "ru $f has stray Latin");
                    }
                }
                $this->assertCount($req, $v->lines('requirements', $l), "requirements/$l");
                $this->assertCount($off, $v->lines('offer', $l), "offer/$l");
                $this->assertSame(explode("\n", $v->getTranslation('requirements', $l)), $v->lines('requirements', $l));
                $this->assertSame(explode("\n", $v->getTranslation('offer', $l)), $v->lines('offer', $l));
            }
        }
    }

    public function test_careers_page_bullets_and_job_posting_json_ld(): void
    {
        $this->seed(VacancySeeder::class);

        foreach (self::LOCALES as $l) {
            $html = $this->get(route('site.careers', $l))->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression('/site\.careers\.[a-z_.]+/', strip_tags($html));

            foreach (Vacancy::all() as $v) {
                foreach (['requirements', 'offer'] as $f) {
                    foreach ($v->lines($f, $l) as $line) {
                        $this->assertStringContainsString('<span class="min-w-0">'.e($line).'</span>', $html, "$l $f bullet");
                    }
                }
            }
            $this->assertSame(19, substr_count($html, '<li class="flex gap-2.5 break-words">'), "$l bullet count");

            preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $all);
            $posting = null;
            foreach ($all[1] as $json) {
                $data = json_decode($json, true);
                $this->assertNotNull($data, 'invalid JSON-LD: '.json_last_error_msg());
                if (array_is_list($data) && ($data[0]['@type'] ?? null) === 'JobPosting') {
                    $posting = $data;
                }
            }
            $this->assertIsArray($posting, "$l: JobPosting list");
            $this->assertCount(2, $posting);
            foreach ($posting as $p) {
                $this->assertSame('FULL_TIME', $p['employmentType']);
                $this->assertNotEmpty($p['title']);
                $this->assertNotEmpty($p['description']);
                $this->assertNotEmpty($p['datePosted']);
                $this->assertSame('UZ', $p['jobLocation']['address']['addressCountry']);
                $this->assertSame(['uz' => 'Toshkent', 'ru' => 'Ташкент', 'en' => 'Tashkent'][$l], $p['jobLocation']['address']['addressLocality']);
            }
        }
    }

    public function test_llms_sitemap_and_ai_knowledge(): void
    {
        $this->seed(VacancySeeder::class);
        Cache::flush();

        $llms = $this->get('/llms.txt')->assertOk()->getContent();
        $this->assertStringContainsString('- Sales manager — Sales', $llms);
        $this->assertStringContainsString('- Delivery driver — Warehouse & logistics', $llms);

        $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();
        foreach (self::LOCALES as $l) {
            $this->assertStringContainsString('/'.$l.'/careers', $sitemap);
        }

        // "- Title (Department, type, location, salary …): details"
        $titles = ['uz' => ['Sotuv menejeri (Savdo,', 'Haydovchi-ekspeditor (Ombor va logistika,'], 'ru' => ['Менеджер по продажам (Продажи,', 'Водитель-экспедитор (Склад и логистика,'], 'en' => ['Sales manager (Sales,', 'Delivery driver (Warehouse & logistics,']];
        foreach ($titles as $l => $expect) {
            $prompt = app(ChatAssistant::class)->promptPreview($l, 'web');
            foreach ($expect as $t) {
                $this->assertStringContainsString('- '.$t, $prompt, "AI knowledge $l");
            }
        }
    }
}
