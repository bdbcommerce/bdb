<?php

namespace Tests\Feature;

use App\Models\InvoiceMessage;
use App\Models\User;
use App\Services\Telegram\TelegramApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Security review of commit 0b88cc2 ("Never download files, apps or archives from Telegram").
 * Everything is faked: no real Telegram call is made.
 */
class SecurityTelegramDownloadTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456789:AAtestTOKENtestTOKENtestTOKENtest00';

    /** A minimal APK-looking zip (local file header + AndroidManifest.xml entry). */
    private const APK = "PK\x03\x04\x14\x00\x00\x00\x08\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x13\x00\x00\x00AndroidManifest.xml";

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => self::TOKEN]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function fakeFile(string $path, string $body, string $type = 'application/octet-stream'): void
    {
        Http::swap(new Factory);
        Http::fake(fn (Request $r) => str_ends_with($r->url(), '/getFile')
            ? Http::response(['ok' => true, 'result' => ['file_path' => $path, 'file_size' => strlen($body)]])
            : Http::response($body, 200, ['Content-Type' => $type]));
    }

    private function fileRequests(): int
    {
        return Http::recorded(fn ($r) => str_contains($r->url(), '/file/bot'))->count();
    }

    /** Guards that hold: these paths are refused before any byte is fetched. */
    public function test_bypass_paths_are_refused_before_download(): void
    {
        $paths = [
            'documents/x.jpg.apk', 'documents/X.APK', 'DOCUMENTS/x.Apk', 'documents/.apk', 'documents/x.apk.',
            'documents/file_1', 'documents/a.svg', 'documents/a.html', 'documents/a.pdf', 'documents/a.docx',
            'photos/x.apk', 'photos/x', 'photos/../documents/a.jpg', 'music/a.jpg.exe', 'voice/a', 'stickers/a.svg',
            'videos/a.zip', 'other/a.jpg', '/photos/a.jpg', 'documents/x.ＡＰＫ',
        ];
        foreach ($paths as $path) {
            $this->fakeFile($path, self::APK);
            $this->actingAs($this->admin())->get(route('admin.telegram.media', 'BQACAgIAAxkBAAIB_doc'))->assertNotFound();
            $this->assertSame(0, $this->fileRequests(), "$path must not be fetched");
        }

        // Callers that name document extensions still never get apps or archives.
        $this->assertFalse(TelegramApi::mayDownload('documents/cv.apk', ['pdf', 'doc', 'docx', 'rtf', 'odt']));
        $this->assertFalse(TelegramApi::mayDownload('documents/Счет.zip', ['pdf', '']));
        $this->assertFalse(TelegramApi::mayDownload('documents/a.pdf.exe', ['pdf', '']));
    }

    /** A real picture sent as a file and a Telegram photo still work. */
    public function test_real_pictures_are_still_served(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        $this->fakeFile('documents/file_3.png', $png);
        $this->actingAs($this->admin())->get(route('admin.telegram.media', 'BQACAgIAAxkBAAIB_doc'))->assertOk();

        $this->fakeFile('photos/file_1.jpg', 'JPEG');
        $this->actingAs($this->admin())->get(route('admin.telegram.media', 'AgACAgIAAxkBAAIB_photo'))->assertOk();
    }

    /** FINDING: finfo calls SVG "image/svg+xml", so an SVG renamed .jpg passes the "bytes are a picture" check. */
    public function test_svg_disguised_as_a_jpg_document_is_not_served(): void
    {
        $this->fakeFile('documents/file_7.jpg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(document.domain)"/>');

        $this->actingAs($this->admin())->get(route('admin.telegram.media', 'BQACAgIAAxkBAAIB_doc'))->assertNotFound();
    }

    /** FINDING: a JPEG with an APK (zip) appended is both a valid picture and a valid APK; it passes the bytes check. */
    public function test_jpeg_apk_polyglot_document_is_not_served(): void
    {
        $this->fakeFile('documents/file_8.jpg', "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00".str_repeat("\x00", 64).self::APK);

        $this->actingAs($this->admin())->get(route('admin.telegram.media', 'BQACAgIAAxkBAAIB_doc'))->assertNotFound();
    }

    /** FINDING: media folders are trusted by name only; an APK sent as "audio" (music/x.mp3) is downloaded and served. */
    public function test_apk_bytes_in_a_media_folder_are_not_served(): void
    {
        foreach (['music/file_4.mp3', 'videos/file_5.mp4', 'animations/file_6.mp4'] as $path) {
            $this->fakeFile($path, self::APK);
            $this->actingAs($this->admin())->get(route('admin.telegram.media', 'CQACAgIAAxkBAAIB_aud'))->assertNotFound();
        }
    }

    /** FINDING: the invoice image route skips the bytes check (documents = ['pdf','']) and serves what Telegram returns. */
    public function test_invoice_image_route_does_not_serve_an_apk_named_like_an_invoice(): void
    {
        $invoice = InvoiceMessage::create([
            'chat_id' => '-1001', 'message_id' => 10, 'file_id' => 'BQACAgIAAxkBAAIB_inv', 'mime' => 'application/pdf',
            'status' => 'skipped',
        ]);
        $this->fakeFile('documents/file_9.pdf', self::APK);

        $this->actingAs($this->admin())->get(route('admin.invoice-messages.image', $invoice))->assertNotFound();
    }
}
