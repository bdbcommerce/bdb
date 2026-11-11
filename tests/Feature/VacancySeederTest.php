<?php

namespace Tests\Feature;

use App\Models\Vacancy;
use Database\Seeders\VacancySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VacancySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_two_vacancies_once_and_shows_them_in_every_locale(): void
    {
        $this->seed(VacancySeeder::class);
        $this->seed(VacancySeeder::class); // re-run adds nothing

        $this->assertSame(2, Vacancy::count());
        $this->assertSame(['4 000 000 UZS', '6 000 000 UZS'], Vacancy::orderBy('salary')->pluck('salary')->all());

        foreach (Vacancy::all() as $vacancy) {
            foreach (['uz', 'ru', 'en'] as $locale) {
                foreach (['title', 'location', 'description', 'requirements', 'offer'] as $field) {
                    $this->assertNotEmpty($vacancy->getTranslation($field, $locale, false), "{$vacancy->titleIn('en')}: {$field} {$locale}");
                }
                $this->assertGreaterThanOrEqual(4, count($vacancy->lines('requirements', $locale)));
            }
        }

        foreach (['uz' => ['Sotuv menejeri', 'Haydovchi-ekspeditor'], 'ru' => ['Менеджер по продажам', 'Водитель-экспедитор'], 'en' => ['Sales manager', 'Delivery driver']] as $locale => $titles) {
            $this->get(route('site.careers', $locale))->assertOk()
                ->assertSee($titles)
                ->assertSee('6 000 000 UZS')
                ->assertSee('JobPosting', false);
        }
    }
}
