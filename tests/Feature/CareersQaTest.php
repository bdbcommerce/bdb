<?php

namespace Tests\Feature;

use App\Models\JobApplication;
use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** QA: Careers page edge cases (plurals, preselect, old input, a11y, access, escaping, files). */
class CareersQaTest extends TestCase
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
            'cv' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
            'consent' => '1',
        ];
    }

    public function test_open_count_plurals(): void
    {
        $this->assertSame('Нет открытых вакансий', __('site.careers.open_none', [], 'ru'));
        $this->assertSame('1 открытая вакансия', trans_choice('site.careers.open_count', 1, [], 'ru'));
        $this->assertSame('3 открытые вакансии', trans_choice('site.careers.open_count', 3, [], 'ru'));
        $this->assertSame('5 открытых вакансий', trans_choice('site.careers.open_count', 5, [], 'ru'));
        $this->assertSame('12 открытых вакансий', trans_choice('site.careers.open_count', 12, [], 'ru'));
        $this->assertSame('2 ta ochiq vakansiya', trans_choice('site.careers.open_count', 2, [], 'uz'));
        $this->assertSame('1 open position', trans_choice('site.careers.open_count', 1, [], 'en'));
        $this->assertSame('4 open positions', trans_choice('site.careers.open_count', 4, [], 'en'));

        $this->vacancy();
        $this->vacancy(['department' => 'service']);
        $this->get(route('site.careers', 'ru'))->assertSee('2 открытые вакансии');
        $this->get(route('site.careers', 'uz'))->assertSee('2 ta ochiq vakansiya');
    }

    /** Russian: 21, 31 … take the singular form, 22–24 the paucal form. */
    public function test_open_count_russian_plural_above_twenty(): void
    {
        $this->assertSame('21 открытая вакансия', trans_choice('site.careers.open_count', 21, [], 'ru'));
        $this->assertSame('22 открытые вакансии', trans_choice('site.careers.open_count', 22, [], 'ru'));
    }

    public function test_filter_chips_only_with_several_departments(): void
    {
        $this->vacancy();
        $this->vacancy(['title' => ['uz' => 'Ikkinchi', 'ru' => 'Вторая', 'en' => 'Second sales']]);
        $html = $this->get(route('site.careers', 'en'))->getContent();
        $this->assertStringNotContainsString(__('site.careers.filter_label', [], 'en'), $html);

        $this->vacancy(['department' => 'software', 'title' => ['uz' => 'Dasturchi', 'ru' => 'Программист', 'en' => 'PHP developer']]);
        foreach (['uz', 'ru', 'en'] as $l) {
            $html = $this->get(route('site.careers', $l))->getContent();
            $this->assertStringContainsString(e(__('site.careers.filter_label', [], $l)), $html);
            $this->assertStringContainsString(e(__('site.careers.all', [], $l)), $html);
            $this->assertStringContainsString(e(__('site.careers.departments.software', [], $l)), $html);
            $this->assertDoesNotMatchRegularExpression('/site\.careers\.[a-z_.]+/', $html);
        }
    }

    public function test_apply_link_preselects_vacancy(): void
    {
        $v = $this->vacancy();
        $hidden = $this->vacancy(['is_active' => false]);

        $html = $this->get(route('site.careers', ['en', 'vacancy' => $v->id]))->getContent();
        $this->assertMatchesRegularExpression('/<option value="'.$v->id.'"\s+selected/', $html);
        $this->assertStringContainsString(route('site.careers', ['en', 'vacancy' => $v->id]).'#apply', $html);

        // Unknown / hidden vacancy id: nothing selected, general application.
        foreach ([$hidden->id, 99999, 'abc'] as $id) {
            $html = $this->get(route('site.careers', ['en', 'vacancy' => $id]))->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression('/<option value="\d+"\s+selected/', $html);
        }
    }

    public function test_errors_rerender_with_old_input_and_aria(): void
    {
        $v = $this->vacancy();
        $this->from(route('site.careers', 'ru'))->post(route('site.careers.apply', 'ru'), $this->form([
            'vacancy_id' => $v->id,
            'name' => 'Ш',
            'phone' => 'позвоните',
            'email' => 'not-an-email',
            'message' => 'Опыт — 5 лет, Oʻzbek tili',
            'cv' => null,
        ]))->assertRedirect(route('site.careers', 'ru'));

        $html = $this->get(route('site.careers', 'ru'))->assertOk()->getContent();
        $this->assertStringContainsString(e(__('site.careers.form.errors', [], 'ru')), $html);
        $this->assertStringContainsString('value="Ш"', $html);
        $this->assertStringContainsString('value="позвоните"', $html);
        $this->assertStringContainsString('value="not-an-email"', $html);
        $this->assertStringContainsString(e('Опыт — 5 лет, Oʻzbek tili'), $html);
        $this->assertStringContainsString('checked', $html); // consent kept
        $this->assertMatchesRegularExpression('/<option value="'.$v->id.'"\s+selected/', $html);
        foreach (['cv-name-error', 'cv-phone-error', 'cv-email-error', 'cv-file-error'] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html);
        }
        $this->assertSame(4, substr_count($html, 'aria-invalid="true"'));
        // Field names in messages are the translated labels, not raw attribute names.
        $this->assertStringContainsString(e(__('site.careers.form.phone', [], 'ru')), $html);
        $this->assertStringNotContainsString('validation.', $html);
    }

    public function test_every_form_control_has_a_label(): void
    {
        $this->vacancy();
        $html = $this->get(route('site.careers', 'en'))->getContent();
        foreach (['cv-position', 'cv-department', 'cv-name', 'cv-phone', 'cv-email', 'cv-message', 'cv-file'] as $id) {
            $this->assertStringContainsString('for="'.$id.'"', $html, "label for $id");
        }
        $this->assertStringContainsString('name="consent"', $html);
    }

    public function test_success_message_after_apply(): void
    {
        foreach (['uz', 'ru', 'en'] as $l) {
            $this->withoutMiddleware(ThrottleRequests::class);
            $this->post(route('site.careers.apply', $l), $this->form())->assertSessionHasNoErrors();
            $this->get(route('site.careers', $l))->assertSee(__('site.careers.form.sent_title', [], $l));
        }
        $this->assertSame(3, JobApplication::count());
    }

    public function test_long_and_unicode_input(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->post(route('site.careers.apply', 'en'), $this->form(['name' => str_repeat('a', 121)]))->assertSessionHasErrors('name');
        $this->post(route('site.careers.apply', 'en'), $this->form(['message' => str_repeat('a', 2001)]))->assertSessionHasErrors('message');
        $this->post(route('site.careers.apply', 'en'), $this->form(['phone' => str_repeat('1', 41)]))->assertSessionHasErrors('phone');

        $this->post(route('site.careers.apply', 'uz'), $this->form([
            'name' => 'Oʻgʻiloy Шукурова',
            'message' => str_repeat('я', 2000),
            'cv' => UploadedFile::fake()->create('Резюме Oʻgʻiloy (2026)/../x.docx', 50, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
        ]))->assertSessionHasNoErrors();
        $app = JobApplication::sole();
        $this->assertSame('Oʻgʻiloy Шукурова', $app->name);
        $this->assertStringNotContainsString('/', $app->cv_name);
        $this->assertStringNotContainsString('..', $app->cv_name);
    }

    public function test_access_control(): void
    {
        $this->post(route('site.careers.apply', 'en'), $this->form())->assertSessionHasNoErrors();
        $app = JobApplication::sole();
        $v = $this->vacancy();
        $urls = [
            route('admin.vacancies.index'), route('admin.vacancies.create'), route('admin.vacancies.edit', $v),
            route('admin.job-applications.index'), route('admin.job-applications.show', $app), route('admin.job-applications.cv', $app),
        ];

        foreach ($urls as $url) {
            $this->get($url)->assertRedirect(route('admin.login'));
        }
        $user = User::factory()->create(['is_admin' => false]);
        foreach ($urls as $url) {
            $this->actingAs($user)->get($url)->assertRedirect(route('admin.login'));
        }
        $this->actingAs($user)->delete(route('admin.job-applications.destroy', $app))->assertRedirect(route('admin.login'));
        $this->actingAs($user)->post(route('admin.vacancies.store'), ['title_uz' => 'x', 'department' => 'sales', 'employment_type' => 'full_time']);
        $this->assertSame(1, JobApplication::count());
        $this->assertSame(1, Vacancy::count());
        $this->assertSame('new', $app->fresh()->status);

        $admin = User::factory()->create(['is_admin' => true]);
        foreach ($urls as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }

    public function test_sidebar_badge_counts_new_applications(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->post(route('site.careers.apply', 'en'), $this->form());
        $this->post(route('site.careers.apply', 'en'), $this->form());
        $admin = User::factory()->create(['is_admin' => true]);

        $html = $this->actingAs($admin)->get(route('admin.vacancies.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Applications (CVs)', $html);
        $this->assertMatchesRegularExpression('/2\s*<span class="sr-only">\s*new applications|aria-label="2 new applications"|>\s*2\s*</', $html);
    }

    public function test_html_is_escaped_everywhere(): void
    {
        config(['services.telegram.bot_token' => 'SECRET:TOKEN123', 'services.telegram.chat_id' => '-100']);
        $v = $this->vacancy(['title' => ['uz' => '<script>alert(1)</script>Job', 'en' => '</script><b>x</b>'],
            'salary' => '<img src=x onerror=alert(1)>', 'description' => ['en' => "<i>desc</i>\nline2"]]);

        $html = $this->get(route('site.careers', 'en'))->getContent();
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<i>desc</i>', $html);
        $this->assertStringNotContainsString('</script><b>x</b>', $html); // JSON-LD must not close the script tag

        $this->post(route('site.careers.apply', 'en'), $this->form(['vacancy_id' => $v->id, 'name' => '<b>Bob</b>', 'message' => '<script>x</script>hi']))
            ->assertSessionHasNoErrors();
        // The faked sendDocument fails, so the details go out as a text message.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage') && str_contains($r['text'], 'New CV') && ! str_contains($r['text'], '<b>Bob') && ! str_contains($r['text'], '</script><b>x'));

        $admin = User::factory()->create(['is_admin' => true]);
        $app = JobApplication::sole();
        foreach ([route('admin.job-applications.index'), route('admin.job-applications.show', $app), route('admin.vacancies.index')] as $url) {
            $page = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>alert(1)', $page);
            $this->assertStringNotContainsString('</script><b>x</b>', $page);
            $this->assertStringNotContainsString('SECRET:TOKEN123', $page);
        }
    }

    public function test_download_refuses_paths_outside_cv_dir_and_missing_files(): void
    {
        Storage::disk('local')->put('secret.txt', 'top secret');
        $admin = User::factory()->create(['is_admin' => true]);
        $evil = JobApplication::create(['name' => 'X', 'phone' => '123', 'cv_path' => 'secret.txt', 'cv_name' => 'a.pdf']);
        $traversal = JobApplication::create(['name' => 'X', 'phone' => '123', 'cv_path' => 'cvs/../secret.txt', 'cv_name' => 'a.pdf']);
        $missing = JobApplication::create(['name' => 'X', 'phone' => '123', 'cv_path' => 'cvs/none.pdf', 'cv_name' => 'a.pdf']);

        $this->actingAs($admin)->get(route('admin.job-applications.cv', $evil))->assertNotFound();
        $this->actingAs($admin)->get(route('admin.job-applications.cv', $missing))->assertNotFound();
        $r = $this->actingAs($admin)->get(route('admin.job-applications.cv', $traversal));
        $this->assertNotSame(200, $r->getStatusCode(), 'cvs/../ traversal must not download files outside cvs/');

        // Deleting a record with a path outside cvs/ never removes that file.
        $evil->delete();
        Storage::disk('local')->assertExists('secret.txt');
    }

    public function test_vacancy_admin_form_keeps_old_input_and_rejects_long_values(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->from(route('admin.vacancies.create'))->post(route('admin.vacancies.store'), [
            'title_uz' => '', 'title_ru' => 'Инженер', 'department' => 'service', 'employment_type' => 'full_time',
            'salary' => str_repeat('9', 121),
        ])->assertSessionHasErrors(['title_uz', 'salary']);
        $html = $this->actingAs($admin)->get(route('admin.vacancies.create'))->getContent();
        $this->assertStringContainsString('value="Инженер"', $html);
        $this->assertMatchesRegularExpression('/<option value="service"\s+selected/', $html);
        $this->assertSame(0, Vacancy::count());
    }

    /** Admin form hint: "If empty, the vacancy is not shown on the RU page." */
    public function test_vacancy_without_russian_title_is_hidden_on_russian_page(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->post(route('admin.vacancies.store'), [
            'title_uz' => 'Faqat oʻzbekcha', 'department' => 'sales', 'employment_type' => 'full_time', 'is_active' => '1',
        ])->assertSessionHasNoErrors();
        auth()->logout();

        $this->get(route('site.careers', 'uz'))->assertSee('Faqat oʻzbekcha');
        $this->get(route('site.careers', 'ru'))->assertDontSee('Faqat oʻzbekcha');
    }

    /** ltrim() with "•·" strips single bytes of multibyte characters at the start of a line. */
    public function test_list_lines_keep_multibyte_first_characters(): void
    {
        $v = $this->vacancy(['requirements' => ['ru' => "— Опыт работы\n– Знание 1С\n«Зебра» принтеры\n№ 1 в продажах\n• Узбекский язык\n· Права"]]);
        $lines = $v->lines('requirements', 'ru');
        foreach ($lines as $line) {
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'), 'invalid UTF-8: '.bin2hex($line));
        }
        $this->assertSame(['Опыт работы', 'Знание 1С', '«Зебра» принтеры', '№ 1 в продажах', 'Узбекский язык', 'Права'], $lines);
    }

    /** preg_split('/\R/') without /u treats byte 0x85 (second byte of Cyrillic "х") as a line break. */
    public function test_russian_lines_with_kha_are_not_split(): void
    {
        $v = $this->vacancy(['offer' => ['ru' => "Опыт в торговых сетях\nЗнание Excel"]]);
        $this->assertSame(['Опыт в торговых сетях', 'Знание Excel'], $v->lines('offer', 'ru'));
        $this->get(route('site.careers', 'ru'))->assertSee('Опыт в торговых сетях');
    }

    public function test_mobile_menu_and_footer_link(): void
    {
        foreach (['uz', 'ru', 'en'] as $l) {
            $html = $this->get(route('site.careers', $l))->getContent();
            $this->assertGreaterThanOrEqual(2, substr_count($html, 'href="'.route('site.careers', $l).'"'));
            $this->assertStringContainsString('aria-current="page"', $html);
        }
    }
}
