<?php

namespace Tests\Feature;

use App\Models\JobApplication;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\TelegramNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** QA: CVs sent to Telegram — caption limit, non-ASCII names, delete/prune, views, retry, schedule. */
class CareersTelegramCvQaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.telegram.bot_token' => '123:ABC', 'services.telegram.chat_id' => '-100500']);
    }

    private function fakeOk(string $body = '%PDF-1.4 body'): void
    {
        Http::fake([
            'api.telegram.org/bot*/sendDocument' => Http::response(['ok' => true, 'result' => ['message_id' => 77, 'document' => ['file_id' => 'FID']]]),
            'api.telegram.org/bot*/getFile' => Http::response(['ok' => true, 'result' => ['file_path' => 'documents/file_1.pdf']]),
            'api.telegram.org/file/bot*' => Http::response($body),
            '*' => Http::response(['ok' => true, 'result' => true]),
        ]);
    }

    private function apply(array $extra = [], string $file = 'cv.pdf'): JobApplication
    {
        $this->post(route('site.careers.apply', 'en'), $extra + [
            'name' => 'Aziz Karimov',
            'phone' => '+998 90 123 45 67',
            'cv' => UploadedFile::fake()->createWithContent($file, "%PDF-1.4\n%x\n"),
            'consent' => '1',
        ])->assertSessionHasNoErrors();

        return JobApplication::latest('id')->first();
    }

    /** Telegram counts caption length in UTF-16 code units after HTML entities are parsed. */
    private function visibleLength(string $html): int
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    public function test_worst_case_caption_stays_under_1024_characters(): void
    {
        $this->fakeOk();
        // Max lengths: vacancy title 200 (astral chars count double in UTF-16), name 120, email 190, phone 40.
        $title = str_repeat('𝐀', 200);
        $v = Vacancy::create(['title' => ['uz' => $title, 'en' => $title], 'department' => 'warehouse', 'is_active' => true]);
        $email = str_repeat('a', 64).'@'.str_repeat('b', 61).'.'.str_repeat('c', 60).'.uz';
        $this->assertSame(190, strlen($email));

        $this->apply([
            'vacancy_id' => $v->id,
            'name' => str_repeat('&Ў', 60),
            'phone' => str_repeat('9', 40),
            'email' => $email,
            'message' => str_repeat('x', 2000),
        ]);

        $caption = null;
        Http::assertSent(function ($r) use (&$caption) {
            if (! str_ends_with($r->url(), '/sendDocument')) {
                return false;
            }
            preg_match('/name="caption"\r\nContent-Length: \d+\r\n\r\n(.*?)\r\n--/s', $r->body(), $m)
                || preg_match('/name="caption"\r\n\r\n(.*?)\r\n--/s', $r->body(), $m);
            $caption = $m[1] ?? null;

            return true;
        });
        $this->assertNotNull($caption, 'caption part not found');
        $this->assertStringNotContainsString(str_repeat('x', 50), $caption, 'message must not be in the caption');
        $this->assertLessThanOrEqual(1024, $this->visibleLength($caption), 'visible caption length = '.$this->visibleLength($caption));
    }

    public function test_non_ascii_file_name_in_upload_and_download(): void
    {
        $this->fakeOk();
        $app = $this->apply([], 'Резюме Oʻzbek ҳ.pdf');
        $this->assertSame('Резюме Oʻzbek ҳ.pdf', $app->cv_name);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendDocument')
            && str_contains($r->body(), 'filename="Резюме Oʻzbek ҳ.pdf"'));

        $admin = User::factory()->create(['is_admin' => true]);
        $res = $this->actingAs($admin)->get(route('admin.job-applications.cv', $app))->assertOk();
        $disp = (string) $res->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment;', $disp);
        $this->assertStringContainsString("filename*=utf-8''".rawurlencode('Резюме Oʻzbek ҳ.pdf'), $disp);
        $this->assertTrue(mb_check_encoding($disp, 'ASCII'), 'header must be ASCII: '.$disp);
        $this->assertSame('%PDF-1.4 body', $res->streamedContent());
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
    }

    public function test_download_returns_404_when_telegram_cannot_provide_the_file(): void
    {
        $app = JobApplication::create(['name' => 'A', 'phone' => '1234567', 'cv_file_id' => 'FID', 'cv_name' => 'a.pdf', 'locale' => 'en']);
        Http::fake(['*' => Http::response(['ok' => false], 400)]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get(route('admin.job-applications.cv', $app))->assertNotFound();
    }

    public function test_download_route_is_admin_only(): void
    {
        $app = JobApplication::create(['name' => 'A', 'phone' => '1234567', 'cv_file_id' => 'FID', 'cv_name' => 'a.pdf', 'locale' => 'en']);
        Http::fake();

        $this->get(route('admin.job-applications.cv', $app))->assertRedirect();
        $this->actingAs(User::factory()->create(['is_admin' => false]))->get(route('admin.job-applications.cv', $app))->assertRedirect();
        Http::assertNothingSent();
    }

    public function test_delete_and_prune_with_only_file_id(): void
    {
        $this->fakeOk();
        $app = $this->apply()->fresh();
        $this->assertNull($app->cv_path);
        $this->assertSame('-100500', $app->cv_chat_id);
        $this->assertSame(77, (int) $app->cv_message_id);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->delete(route('admin.job-applications.destroy', $app))->assertRedirect();
        $this->assertModelMissing($app);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/deleteMessage') && $r['message_id'] == 77 && $r['chat_id'] == '-100500');

        // Prune an old one, with Telegram now unconfigured: no error, row gone.
        $old = JobApplication::create(['name' => 'B', 'phone' => '1234567', 'cv_file_id' => 'FID2', 'cv_chat_id' => '-1', 'cv_message_id' => 5, 'cv_name' => 'b.pdf', 'locale' => 'en']);
        $old->forceFill(['created_at' => now()->subDays(400)])->save();
        config(['services.telegram.bot_token' => null]);
        $this->artisan('model:prune', ['--model' => [JobApplication::class]])->assertSuccessful();
        $this->assertModelMissing($old);
    }

    public function test_delete_survives_telegram_error(): void
    {
        $app = JobApplication::create(['name' => 'A', 'phone' => '1234567', 'cv_file_id' => 'FID', 'cv_chat_id' => '-1', 'cv_message_id' => 9, 'cv_name' => 'a.pdf', 'locale' => 'en']);
        Http::fake(['*' => Http::response(['ok' => false, 'description' => 'message can\'t be deleted'], 400)]);

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->delete(route('admin.job-applications.destroy', $app))->assertRedirect();
        $this->assertModelMissing($app);
    }

    public function test_views_when_cv_path_is_null(): void
    {
        Http::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $tg = JobApplication::create(['name' => 'Тест Ўзбек', 'phone' => '1234567', 'cv_file_id' => 'FID', 'cv_name' => 'a.pdf', 'locale' => 'ru']);
        $missing = JobApplication::create(['name' => 'Missing', 'phone' => '1234567', 'cv_name' => 'b.pdf', 'locale' => 'en']);
        $local = JobApplication::create(['name' => 'Local', 'phone' => '1234567', 'cv_path' => 'cvs/'.Str::random(40).'.pdf', 'cv_name' => 'c.pdf', 'locale' => 'en']);

        $this->actingAs($admin)->get(route('admin.job-applications.index'))->assertOk()->assertSee('Тест Ўзбек')->assertSee('Missing');
        $this->actingAs($admin)->get(route('admin.job-applications.show', $tg))->assertOk()->assertSee('stored in the Telegram admin chat')->assertDontSee('123:ABC');
        $this->actingAs($admin)->get(route('admin.job-applications.show', $missing))->assertOk()->assertSee('file missing');
        $this->actingAs($admin)->get(route('admin.job-applications.show', $local))->assertOk()->assertSee('not yet sent to Telegram');
        $this->actingAs($admin)->get(route('admin.job-applications.cv', $missing))->assertNotFound();
    }

    public function test_retry_does_not_repost_text_and_skips_fresh_rows(): void
    {
        Http::fake([
            'api.telegram.org/bot*/sendDocument' => Http::response(['ok' => false], 500),
            '*' => Http::response(['ok' => true, 'result' => []]),
        ]);
        $app = $this->apply();
        $this->assertNotNull($app->fresh()->cv_path);

        // Fresh row (< 10 min) is skipped.
        $this->artisan('careers:send-cvs')->expectsOutputToContain('Sent: 0. Failed (kept on the server): 0')->assertSuccessful();

        $this->travel(11)->minutes();
        Http::fake([
            'api.telegram.org/bot*/sendDocument' => Http::response(['ok' => false], 500),
            '*' => Http::response(['ok' => true, 'result' => []]),
        ]);
        $this->artisan('careers:send-cvs')->expectsOutputToContain('Failed (kept on the server): 1')->assertFailed();
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMessage'));
        Storage::disk('local')->assertExists($app->fresh()->cv_path);
    }

    public function test_retry_handles_more_than_one_chunk(): void
    {
        $this->fakeOk();
        for ($i = 0; $i < 3; $i++) {
            $path = 'cvs/'.Str::random(40).'.pdf';
            Storage::disk('local')->put($path, '%PDF');
            JobApplication::create(['name' => "N$i", 'phone' => '1234567', 'cv_path' => $path, 'cv_name' => "$i.pdf", 'locale' => 'en'])
                ->forceFill(['created_at' => now()->subHour()])->save();
        }

        $this->artisan('careers:send-cvs')->expectsOutputToContain('Sent: 3')->assertSuccessful();
        $this->assertSame(0, JobApplication::whereNull('cv_file_id')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_second_send_is_a_no_op(): void
    {
        $this->fakeOk();
        $app = $this->apply()->fresh();
        $this->assertFalse($app->sendCvToTelegram(app(TelegramNotifier::class)));
        Http::assertSentCount(1);
    }
}
