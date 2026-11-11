<?php

namespace Tests\Feature;

use App\Models\JobApplication;
use App\Models\User;
use App\Services\TelegramNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** CVs are sent to the admin Telegram chat and deleted from the server; only the file_id is kept. */
class CareersTelegramCvTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.telegram.bot_token' => '123:ABC', 'services.telegram.chat_id' => '-100500']);
    }

    private function apply(): JobApplication
    {
        $this->post(route('site.careers.apply', 'en'), [
            'name' => 'Aziz Karimov',
            'phone' => '+998 90 123 45 67',
            'cv' => UploadedFile::fake()->create('Aziz CV.pdf', 200, 'application/pdf'),
            'consent' => '1',
        ])->assertSessionHasNoErrors();

        return JobApplication::sole();
    }

    private function fakeTelegramOk(): void
    {
        Http::fake([
            'api.telegram.org/bot*/sendDocument' => Http::response(['ok' => true, 'result' => ['message_id' => 7, 'document' => ['file_id' => 'BQACAgIAAxkBAAIC']]]),
            'api.telegram.org/bot*/getFile' => Http::response(['ok' => true, 'result' => ['file_path' => 'documents/file_1.pdf']]),
            'api.telegram.org/file/bot*' => Http::response('%PDF-1.4 cv body'),
            '*' => Http::response(['ok' => true, 'result' => []]),
        ]);
    }

    public function test_cv_is_sent_to_telegram_and_removed_from_the_server(): void
    {
        $this->fakeTelegramOk();

        $app = $this->apply()->fresh();

        $this->assertSame('BQACAgIAAxkBAAIC', $app->cv_file_id);
        $this->assertNull($app->cv_path);
        $this->assertSame([], Storage::disk('local')->allFiles());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendDocument')
            && str_contains($r->body(), 'name="document"; filename="Aziz CV.pdf"')
            && str_contains($r->body(), 'New CV')
            && str_contains($r->body(), '-100500'));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMessage'));
    }

    public function test_admin_downloads_the_cv_from_telegram(): void
    {
        $this->fakeTelegramOk();
        $app = $this->apply();
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->get(route('admin.job-applications.cv', $app))->assertOk();
        $this->assertSame('%PDF-1.4 cv body', $response->streamedContent());
        $this->assertStringContainsString('Aziz CV.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString('123:ABC', $response->streamedContent());

        $this->actingAs($admin)->get(route('admin.job-applications.show', $app))->assertOk()->assertSee('stored in the Telegram admin chat');
    }

    public function test_failed_upload_keeps_the_cv_on_the_server_and_sends_the_text(): void
    {
        Http::fake([
            // First upload fails, the retry works.
            'api.telegram.org/bot*/sendDocument' => Http::sequence()
                ->push(['ok' => false, 'description' => 'Bad Request'], 400)
                ->push(['ok' => true, 'result' => ['message_id' => 8, 'document' => ['file_id' => 'BQACAgIAAxkBAAIC']]]),
            '*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $app = $this->apply()->fresh();

        $this->assertNull($app->cv_file_id);
        Storage::disk('local')->assertExists($app->cv_path);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && str_contains($r['text'], 'New CV'));

        // The hourly retry leaves brand-new CVs alone, then sends it once Telegram works again.
        $this->artisan('careers:send-cvs')->expectsOutputToContain('Sent: 0')->assertSuccessful();
        $this->travel(11)->minutes();
        $this->artisan('careers:send-cvs')->expectsOutputToContain('Sent: 1')->assertSuccessful();
        $this->assertSame('BQACAgIAAxkBAAIC', $app->fresh()->cv_file_id);
        $this->assertSame([], Storage::disk('local')->allFiles());
        // The details went out once (with the first failure), not again with the retry.
        Http::assertSentCount(3); // sendDocument (fails), sendMessage, sendDocument (retry, ok)
        $this->assertCount(1, Http::recorded(fn ($r) => str_ends_with($r->url(), '/sendMessage')));
    }

    public function test_deleting_an_application_deletes_the_telegram_message(): void
    {
        $this->fakeTelegramOk();
        $app = $this->apply()->fresh();
        $this->assertSame('-100500', $app->cv_chat_id);
        $this->assertSame(7, (int) $app->cv_message_id);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->delete(route('admin.job-applications.destroy', $app))->assertRedirect();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/deleteMessage')
            && $r['chat_id'] === '-100500' && $r['message_id'] === 7);
    }

    public function test_upload_is_not_repeated_once_sent(): void
    {
        $this->fakeTelegramOk();
        $app = $this->apply();

        $this->assertFalse($app->fresh()->sendCvToTelegram(app(TelegramNotifier::class)));
        Http::assertSentCount(1); // only the first sendDocument
    }

    public function test_without_telegram_the_cv_stays_on_the_server(): void
    {
        config(['services.telegram.bot_token' => null]);
        Http::fake();

        $app = $this->apply();

        Storage::disk('local')->assertExists($app->cv_path);
        $this->assertNull($app->cv_file_id);
        Http::assertNothingSent();
        $this->artisan('careers:send-cvs')->assertFailed();
    }
}
