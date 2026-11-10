<?php

namespace Tests\Feature;

use App\Models\JobApplication;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\ChatAssistant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Careers page: vacancies, CV form, admin vacancies + applications. */
class CareersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::fake();
    }

    private function vacancy(array $attrs = []): Vacancy
    {
        return Vacancy::create($attrs + [
            'title' => ['uz' => 'Sotuv menejeri', 'ru' => 'Менеджер по продажам', 'en' => 'Sales manager'],
            'department' => 'sales',
            'employment_type' => 'full_time',
            'location' => ['uz' => 'Toshkent', 'ru' => 'Ташкент', 'en' => 'Tashkent'],
            'requirements' => ['uz' => "- Tajriba\n- Rus tili", 'ru' => "- Опыт\n- Узбекский язык", 'en' => "- Experience\n\n• B2B sales"],
            'is_active' => true,
        ]);
    }

    private function form(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Aziz Karimov',
            'phone' => '+998 90 123 45 67',
            'email' => 'aziz@example.com',
            'message' => 'Five years in sales',
            'cv' => UploadedFile::fake()->create('My CV <b>.pdf', 200, 'application/pdf'),
            'consent' => '1',
        ];
    }

    public function test_page_lists_active_vacancies_in_every_locale(): void
    {
        $v = $this->vacancy();
        $this->vacancy(['title' => ['uz' => 'Yashirin', 'ru' => 'Скрытая', 'en' => 'Hidden job'], 'is_active' => false]);

        foreach (['uz', 'ru', 'en'] as $locale) {
            $html = $this->get(route('site.careers', $locale))->assertOk()->getContent();
            $this->assertStringContainsString(e($v->titleIn($locale)), $html);
            $this->assertStringNotContainsString('Hidden job', $html);
            $this->assertStringNotContainsString('Скрытая', $html);
            $this->assertDoesNotMatchRegularExpression('/site\.careers\.[a-z_.]+/', $html, "raw key in $locale");
            $this->assertStringContainsString('"@type":"JobPosting"', $html);
            $this->assertStringContainsString('enctype="multipart/form-data"', $html);
            $this->assertStringContainsString(e(__('site.careers.departments.sales', [], $locale)), $html);
            $this->assertStringContainsString(route('site.careers', $locale), $this->get(route('site.home', $locale))->getContent()); // footer link
        }
        $this->get(route('site.careers', 'en'))->assertSee('B2B sales')->assertSee('Experience');
        $this->assertStringContainsString('/en/careers', $this->get('/sitemap.xml')->getContent());
        $this->assertStringContainsString('Sales manager', $this->get('/llms.txt')->getContent());
    }

    public function test_empty_state_still_offers_the_form(): void
    {
        $this->get(route('site.careers', 'ru'))->assertOk()
            ->assertSee(__('site.careers.none_title', [], 'ru'))
            ->assertSee(__('site.careers.form.submit', [], 'ru'));
    }

    public function test_cv_is_stored_privately_and_admin_can_download(): void
    {
        config(['services.telegram.bot_token' => 'TEST:TOKEN', 'services.telegram.chat_id' => '-100']);
        $v = $this->vacancy();

        $this->post(route('site.careers.apply', 'en'), $this->form(['vacancy_id' => $v->id]))
            ->assertRedirect(route('site.careers', 'en').'#apply')->assertSessionHas('careers_sent');

        $app = JobApplication::sole();
        $this->assertSame($v->id, $app->vacancy_id);
        $this->assertSame('Sales manager', $app->vacancy_title);
        $this->assertSame('sales', $app->department);
        $this->assertSame('new', $app->status);
        $this->assertStringStartsWith('cvs/', $app->cv_path);
        $this->assertStringNotContainsString('My CV', $app->cv_path);
        $this->assertSame('My CV b.pdf', $app->cv_name);
        Storage::disk('local')->assertExists($app->cv_path);
        Storage::disk('public')->assertMissing($app->cv_path);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage') && str_contains($r['text'], 'New CV') && str_contains($r['text'], 'Aziz Karimov'));

        // Guests cannot see or download.
        $this->get(route('admin.job-applications.cv', $app))->assertRedirect(route('admin.login'));
        $this->get(route('admin.job-applications.show', $app))->assertRedirect(route('admin.login'));

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.job-applications.index'))->assertOk()->assertSee('Aziz Karimov');
        $this->actingAs($admin)->get(route('admin.job-applications.show', $app))->assertOk()->assertSee('Five years in sales')->assertSee('Download CV');
        $this->assertSame('reviewed', $app->fresh()->status);

        $response = $this->actingAs($admin)->get(route('admin.job-applications.cv', $app))->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
        $this->assertSame('nosniff', $response->headers->get('x-content-type-options'));

        $this->actingAs($admin)->put(route('admin.job-applications.update', $app), ['status' => 'interview', 'notes' => 'Call Monday'])->assertSessionHasNoErrors();
        $this->assertSame('interview', $app->fresh()->status);
        $this->actingAs($admin)->put(route('admin.job-applications.update', $app), ['status' => 'bogus'])->assertSessionHasErrors('status');

        $path = $app->cv_path;
        $this->actingAs($admin)->delete(route('admin.job-applications.destroy', $app))->assertRedirect();
        Storage::disk('local')->assertMissing($path);
        $this->assertSame(0, JobApplication::count());
    }

    public function test_general_application_with_department(): void
    {
        $this->post(route('site.careers.apply', 'uz'), $this->form(['department' => 'service']))->assertSessionHasNoErrors();
        $app = JobApplication::sole();
        $this->assertNull($app->vacancy_id);
        $this->assertSame('service', $app->department);
        $this->assertSame('uz', $app->locale);
    }

    public function test_validation(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class); // many posts in one test
        $hidden = $this->vacancy(['is_active' => false]);

        $this->post(route('site.careers.apply', 'en'), $this->form(['vacancy_id' => $hidden->id]))->assertSessionHasErrors('vacancy_id');
        $this->post(route('site.careers.apply', 'en'), $this->form(['cv' => UploadedFile::fake()->create('evil.php', 10, 'application/x-php')]))->assertSessionHasErrors('cv');
        $this->post(route('site.careers.apply', 'en'), $this->form(['cv' => UploadedFile::fake()->create('page.html', 10, 'text/html')]))->assertSessionHasErrors('cv');
        $this->post(route('site.careers.apply', 'en'), $this->form(['cv' => UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')]))->assertSessionHasErrors('cv');
        $this->post(route('site.careers.apply', 'en'), array_diff_key($this->form(), ['cv' => 1]))->assertSessionHasErrors('cv');
        $this->post(route('site.careers.apply', 'en'), array_diff_key($this->form(), ['consent' => 1]))->assertSessionHasErrors('consent');
        $this->post(route('site.careers.apply', 'en'), $this->form(['phone' => 'call me']))->assertSessionHasErrors('phone');
        $this->post(route('site.careers.apply', 'en'), $this->form(['department' => 'ceo']))->assertSessionHasErrors('department');
        $this->assertSame(0, JobApplication::count());
        $this->assertSame([], Storage::disk('local')->allFiles());

        // Errors are shown translated on the page.
        $this->from(route('site.careers', 'ru'))->post(route('site.careers.apply', 'ru'), $this->form(['name' => '']))
            ->assertRedirect(route('site.careers', 'ru'));
        $this->get(route('site.careers', 'ru'))->assertSee(__('site.careers.form.errors', [], 'ru'));
    }

    public function test_docx_detected_as_zip_is_accepted_only_with_a_word_document_inside(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $make = function (array $entries): string {
            $path = tempnam(sys_get_temp_dir(), 'docx');
            $zip = new \ZipArchive;
            $zip->open($path, \ZipArchive::OVERWRITE);
            foreach ($entries as $name => $body) {
                $zip->addFromString($name, $body);
            }
            $zip->close();

            return $path;
        };

        $word = $make(['[Content_Types].xml' => '<Types/>', 'word/document.xml' => '<w:document/>']);
        $this->post(route('site.careers.apply', 'en'), $this->form(['cv' => new UploadedFile($word, 'Resume.docx', null, null, true)]))->assertSessionHasNoErrors();
        $this->assertStringEndsWith('.docx', JobApplication::sole()->cv_path);
        $this->assertSame('Resume.docx', JobApplication::sole()->cv_name);

        $other = $make(['index.html' => '<script>alert(1)</script>']);
        $this->post(route('site.careers.apply', 'en'), $this->form(['cv' => new UploadedFile($other, 'Resume.docx', null, null, true)]))->assertSessionHasErrors('cv');
        $this->assertSame(1, JobApplication::count());
        @unlink($word);
        @unlink($other);
    }

    public function test_honeypot_saves_nothing(): void
    {
        $this->post(route('site.careers.apply', 'en'), $this->form(['website' => 'http://spam']))->assertSessionHas('careers_sent');
        $this->assertSame(0, JobApplication::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_apply_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('site.careers.apply', 'en'), $this->form())->assertRedirect();
        }
        $this->post(route('site.careers.apply', 'en'), $this->form())->assertStatus(429);
    }

    public function test_admin_vacancy_crud(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.vacancies.create'))->assertOk()->assertSee('Department');

        $this->actingAs($admin)->post(route('admin.vacancies.store'), [
            'title_uz' => 'Muhandis', 'title_ru' => 'Инженер', 'title_en' => 'Service engineer',
            'department' => 'service', 'employment_type' => 'full_time', 'salary' => 'from 8 000 000 UZS',
            'requirements_en' => "Zebra printers\nDriving licence", 'is_active' => '1',
        ])->assertRedirect(route('admin.vacancies.index'));
        $v = Vacancy::sole();
        $this->assertSame(['Zebra printers', 'Driving licence'], $v->lines('requirements', 'en'));

        $this->actingAs($admin)->get(route('admin.vacancies.index'))->assertOk()->assertSee('Service engineer');
        $this->actingAs($admin)->post(route('admin.vacancies.store'), ['title_uz' => '', 'department' => 'x', 'employment_type' => 'full_time'])
            ->assertSessionHasErrors(['title_uz', 'department']);

        $this->actingAs($admin)->put(route('admin.vacancies.update', $v), [
            'title_uz' => 'Muhandis', 'title_en' => 'Service engineer', 'department' => 'service', 'employment_type' => 'internship',
        ])->assertSessionHasNoErrors();
        $this->assertFalse($v->fresh()->is_active);
        auth()->logout();
        $this->get(route('site.careers', 'en'))->assertDontSee('Service engineer');

        // Deleting a vacancy keeps its applications.
        $v->refresh()->update(['is_active' => true]);
        $this->post(route('site.careers.apply', 'en'), $this->form(['vacancy_id' => $v->id]))->assertSessionHasNoErrors();
        $this->actingAs($admin)->delete(route('admin.vacancies.destroy', $v))->assertRedirect();
        $app = JobApplication::sole();
        $this->assertNull($app->vacancy_id);
        $this->assertSame('Service engineer', $app->vacancy_title);
        $this->actingAs($admin)->get(route('admin.job-applications.show', $app))->assertOk()->assertSee('Service engineer');
    }

    public function test_old_applications_are_pruned_with_files(): void
    {
        $this->post(route('site.careers.apply', 'en'), $this->form())->assertSessionHasNoErrors();
        $app = JobApplication::sole();
        $app->forceFill(['created_at' => now()->subDays(400)])->save();

        $this->artisan('model:prune', ['--model' => [JobApplication::class]])->assertSuccessful();
        $this->assertSame(0, JobApplication::count());
        Storage::disk('local')->assertMissing($app->cv_path);
    }

    public function test_ai_knowledge_mentions_careers(): void
    {
        $this->vacancy();
        $preview = app(ChatAssistant::class)->promptPreview('en', 'web');
        $this->assertStringContainsString('/en/careers', $preview);
        $this->assertStringContainsString('Sales manager', $preview);
    }
}
