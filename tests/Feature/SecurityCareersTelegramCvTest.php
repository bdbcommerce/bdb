<?php

namespace Tests\Feature;

use App\Models\JobApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Security review of "CVs are sent to the admin Telegram chat" (cv_file_id). */
class SecurityCareersTelegramCvTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '999:SECRETTOKENxyz';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.telegram.bot_token' => self::TOKEN, 'services.telegram.chat_id' => '-100500']);
    }

    private function apply(string $clientName = 'cv.pdf', array $extra = []): JobApplication
    {
        $this->post(route('site.careers.apply', 'en'), $extra + [
            'name' => 'Aziz Karimov',
            'phone' => '+998 90 123 45 67',
            'cv' => UploadedFile::fake()->createWithContent($clientName, "%PDF-1.4\n%test\n"),
            'consent' => '1',
        ])->assertSessionHasNoErrors();

        return JobApplication::latest('id')->first();
    }

    private function fakeOk(): void
    {
        Http::fake([
            'api.telegram.org/bot*/sendDocument' => Http::response(['ok' => true, 'result' => ['message_id' => 7, 'document' => ['file_id' => 'FILEID1']]]),
            'api.telegram.org/bot*/getFile' => Http::response(['ok' => true, 'result' => ['file_path' => 'documents/file_1.pdf']]),
            'api.telegram.org/file/bot*' => Http::response('<html><script>alert(1)</script></html>', 200, ['Content-Type' => 'text/html']),
            '*' => Http::response(['ok' => true, 'result' => []]),
        ]);
    }

    public function test_multipart_and_disposition_filename_cannot_inject_headers(): void
    {
        $this->fakeOk();
        $app = $this->apply("evil\"\r\nX-Injected: 1;\\..\\a.pdf");

        $this->assertStringNotContainsString("\r", $app->cv_name);
        $this->assertStringNotContainsString('"', $app->cv_name);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendDocument')
            && ! str_contains($r->body(), 'X-Injected: 1')
            && ! preg_match('/filename="[^"\r\n]*"[^\r\n]*\r\nX-/', $r->body()));

        $admin = User::factory()->create(['is_admin' => true]);
        $res = $this->actingAs($admin)->get(route('admin.job-applications.cv', $app))->assertOk();
        $this->assertNull($res->headers->get('X-Injected'));
        $this->assertStringStartsWith('attachment', (string) $res->headers->get('Content-Disposition'));
    }

    public function test_caption_escapes_applicant_html(): void
    {
        $this->fakeOk();
        $this->apply('cv.pdf', ['name' => '<a href="tg://x">Bob</a> & <b>', 'email' => 'a@example.com']);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendDocument')
            && ! str_contains($r->body(), '<a href="tg://x">')
            && str_contains($r->body(), 'parse_mode'));
    }

    public function test_download_from_telegram_is_never_rendered_inline(): void
    {
        $this->fakeOk();
        $app = $this->apply();
        $admin = User::factory()->create(['is_admin' => true]);

        $res = $this->actingAs($admin)->get(route('admin.job-applications.cv', $app))->assertOk();
        $this->assertSame('application/octet-stream', $res->headers->get('Content-Type'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $res->headers->get('Content-Security-Policy'));
        $this->assertStringStartsWith('attachment', (string) $res->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString(self::TOKEN, $res->streamedContent());
        foreach ($res->headers->all() as $values) {
            $this->assertStringNotContainsString(self::TOKEN, implode(' ', $values));
        }
    }

    public function test_download_route_refuses_guests_and_non_admins(): void
    {
        $this->fakeOk();
        $app = $this->apply();

        $this->get(route('admin.job-applications.cv', $app))->assertRedirect();
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->get(route('admin.job-applications.cv', $app))->assertRedirect();
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/getFile'));
    }

    public function test_public_form_cannot_set_cv_file_id(): void
    {
        Http::fake(['*' => Http::response(['ok' => false], 500)]);
        $app = $this->apply('cv.pdf', ['cv_file_id' => 'ATTACKER', 'cv_path' => 'cvs/../../.env']);

        $this->assertNull($app->fresh()->cv_file_id);
        $this->assertTrue($app->fresh()->hasSafePath());
    }

    public function test_telegram_failure_on_download_is_404_without_token(): void
    {
        $this->fakeOk();
        $app = $this->apply();
        Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28 for https://api.telegram.org/bot'.self::TOKEN.'/getFile')]);
        Log::spy();

        $admin = User::factory()->create(['is_admin' => true]);
        $res = $this->actingAs($admin)->get(route('admin.job-applications.cv', $app))->assertNotFound();
        $this->assertStringNotContainsString(self::TOKEN, (string) $res->getContent());
        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $ctx = []) => ! str_contains(json_encode($ctx), self::TOKEN));
    }

    public function test_send_document_connection_error_does_not_log_token(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('cURL error 7 for https://api.telegram.org/bot'.self::TOKEN.'/sendDocument')]);
        Log::spy();

        $this->apply();

        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $ctx = []) => ! str_contains(json_encode($ctx), self::TOKEN));
        Log::shouldNotHaveReceived('warning', [\Mockery::any(), \Mockery::on(fn ($ctx) => str_contains(json_encode($ctx), self::TOKEN))]);
    }

    /**
     * FINDING: when sendDocument keeps failing (e.g. the admin chat forbids documents, file rejected)
     * but sendMessage works, every hourly `careers:send-cvs` run re-posts the full caption (personal
     * data) as a new text message, for every pending CV, for up to 365 days.
     */
    public function test_finding_retries_do_not_repost_the_text_notification(): void
    {
        Http::fake([
            'api.telegram.org/bot*/sendDocument' => Http::response(['ok' => false, 'description' => 'Bad Request: not enough rights to send documents'], 400),
            '*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $this->apply();
        $this->artisan('careers:send-cvs');
        $this->artisan('careers:send-cvs');

        $texts = collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/sendMessage'))->count();
        $this->assertSame(1, $texts, "The text notification was posted {$texts} times for one CV.");
    }

    /**
     * FINDING: deleting an application (admin "Application and CV deleted.") or pruning it after
     * 365 days leaves the CV document in the Telegram chat; the message_id is not even stored,
     * so it can never be removed by the app.
     */
    public function test_finding_deleting_an_application_removes_the_telegram_copy(): void
    {
        $this->fakeOk();
        $app = $this->apply();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->delete(route('admin.job-applications.destroy', $app))->assertRedirect();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/deleteMessage'));
    }
}
