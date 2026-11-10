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

/**
 * Security tests for the public Careers CV upload and the admin applications pages.
 * Uses real (non-fake) uploaded files so MIME detection runs on the content, as in production.
 */
class CareersSecurityTest extends TestCase
{
    use RefreshDatabase;

    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $p) {
            @unlink($p);
        }
        parent::tearDown();
    }

    /** A real uploaded file whose MIME type is sniffed from $content (unlike UploadedFile::fake()). */
    private function upload(string $content, string $clientName, ?string $clientMime = 'application/pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'cvsec');
        file_put_contents($path, $content);
        $this->tmp[] = $path;

        return new UploadedFile($path, $clientName, $clientMime, null, true);
    }

    private function form(UploadedFile $cv, array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Aziz Karimov',
            'phone' => '+998 90 123 45 67',
            'email' => 'aziz@example.com',
            'message' => 'Five years in sales',
            'cv' => $cv,
            'consent' => '1',
        ];
    }

    private function apply(array $data)
    {
        return $this->withoutMiddleware(ThrottleRequests::class)->post(route('site.careers.apply', 'en'), $data);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    // ---- Upload type checks -------------------------------------------------------------

    public function test_html_svg_php_and_zip_disguised_as_pdf_are_rejected(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'cvzip');
        $this->tmp[] = $zipPath;
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::OVERWRITE);
        $zip->addFromString('index.html', '<script>alert(1)</script>');
        $zip->close();

        $cases = [
            'html as pdf' => $this->upload('<html><script>alert(1)</script></html>', 'cv.pdf'),
            'svg as pdf' => $this->upload('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'cv.pdf'),
            'php as pdf' => $this->upload('<?php system($_GET["c"]); ?>', 'cv.pdf'),
            'php extension' => $this->upload("%PDF-1.4\n<?php system('id'); ?>", 'shell.php', 'application/pdf'),
            'phtml extension' => $this->upload("%PDF-1.4\n<?php ?>", 'shell.phtml'),
            'empty file' => $this->upload('', 'cv.pdf'),
            'zip as docx' => new UploadedFile($zipPath, 'cv.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true),
        ];

        foreach ($cases as $label => $file) {
            $this->apply($this->form($file))->assertRedirect();
            $this->assertSame(0, JobApplication::count(), $label.' was accepted');
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_pdf_polyglot_is_stored_with_random_pdf_name_and_downloaded_inertly(): void
    {
        $file = $this->upload("%PDF-1.4\n<html><script>alert(document.cookie)</script></html>", '../../../public/evil.php.html.pdf');
        $this->apply($this->form($file))->assertSessionHasNoErrors();

        $app = JobApplication::sole();
        $this->assertMatchesRegularExpression('#^cvs/[A-Za-z0-9]{40}\.pdf$#', $app->cv_path);
        $this->assertSame('evil.php.html.pdf', $app->cv_name);
        Storage::disk('local')->assertExists($app->cv_path);

        $res = $this->actingAs($this->admin())->get(route('admin.job-applications.cv', $app))->assertOk();
        $this->assertStringStartsWith('attachment;', $res->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', $res->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('html', strtolower((string) $res->headers->get('Content-Type')));
    }

    public function test_download_name_cannot_inject_headers_or_paths(): void
    {
        foreach ([
            "evil\r\nSet-Cookie: x=1\r\n.pdf",
            'a";filename=evil.html;x=".pdf',
            '..\\..\\windows\\system32\\cv.pdf',
            "\u{202E}fdp.exe.pdf",
            str_repeat('A', 500).'.pdf',
        ] as $name) {
            $clean = JobApplication::cleanFileName($name, 'pdf');
            $this->assertDoesNotMatchRegularExpression('/[\r\n"\\\\\/;\x{202E}]/u', $clean, $name);
            $this->assertStringEndsWith('.pdf', $clean);
            $this->assertLessThanOrEqual(84, mb_strlen($clean));
        }

        $file = $this->upload("%PDF-1.4\n1 0 obj<<>>endobj", "evil\r\nX-Injected: yes\r\n.pdf");
        $this->apply($this->form($file))->assertSessionHasNoErrors();
        $res = $this->actingAs($this->admin())->get(route('admin.job-applications.cv', JobApplication::sole()))->assertOk();
        $this->assertNull($res->headers->get('X-Injected'));
        $this->assertStringNotContainsString("\n", $res->headers->get('Content-Disposition'));
    }

    /**
     * FINDING (low, defence in depth; expected to fail): the `cvs/` prefix check runs on the raw
     * string, and Flysystem normalises `cvs/../secret.txt` to `secret.txt`, so a tampered cv_path
     * (DB write, future bug) downloads / deletes any file on the private disk.
     */
    public function test_tampered_cv_path_outside_cvs_is_never_downloaded_or_deleted(): void
    {
        Storage::disk('local')->put('secret.txt', 'top secret');
        $app = JobApplication::create([
            'name' => 'X', 'phone' => '+998901234567', 'cv_path' => 'cvs/../secret.txt', 'cv_name' => 'a.pdf', 'cv_size' => 1, 'locale' => 'en',
        ]);
        // str_starts_with('cvs/') passes, but Flysystem's path normaliser refuses the traversal.
        $res = $this->actingAs($this->admin())->get(route('admin.job-applications.cv', $app));
        $this->assertNotSame(200, $res->getStatusCode());
        $this->assertStringNotContainsString('top secret', (string) $res->getContent());

        $app->update(['cv_path' => 'secret.txt']);
        $this->actingAs($this->admin())->get(route('admin.job-applications.cv', $app))->assertNotFound();
        $app->delete();
        Storage::disk('local')->assertExists('secret.txt');
    }

    // ---- Public reachability ------------------------------------------------------------

    public function test_stored_cv_is_not_reachable_through_the_storage_serve_route(): void
    {
        $this->apply($this->form($this->upload("%PDF-1.4\n%secret-cv", 'cv.pdf')))->assertSessionHasNoErrors();
        $path = JobApplication::sole()->cv_path;

        foreach (['/storage/'.$path, '/storage/'.$path.'?signature=abc', '/storage/'.$path.'?expires=9999999999&signature=00'] as $url) {
            $res = $this->get($url);
            $this->assertContains($res->getStatusCode(), [403, 404], $url);
            $this->assertStringNotContainsString('secret-cv', (string) $res->getContent());
        }
        // The signed-upload PUT route of the "serve" disk cannot overwrite a CV either.
        $this->call('PUT', '/storage/'.$path, [], [], [], [], 'pwned');
        $this->assertStringContainsString('secret-cv', Storage::disk('local')->get($path));
    }

    // ---- Access control / mass assignment ----------------------------------------------

    public function test_guests_and_non_admins_cannot_reach_applications_or_vacancies(): void
    {
        $this->apply($this->form($this->upload("%PDF-1.4\n%x", 'cv.pdf')));
        $app = JobApplication::sole();
        $v = Vacancy::create(['title' => ['uz' => 'T', 'en' => 'T'], 'department' => 'sales', 'employment_type' => 'full_time', 'is_active' => true]);
        $user = User::factory()->create(['is_admin' => false]);

        $requests = [
            ['GET', route('admin.job-applications.index')],
            ['GET', route('admin.job-applications.show', $app)],
            ['GET', route('admin.job-applications.cv', $app)],
            ['PUT', route('admin.job-applications.update', $app)],
            ['DELETE', route('admin.job-applications.destroy', $app)],
            ['GET', route('admin.vacancies.index')],
            ['POST', route('admin.vacancies.store')],
            ['PUT', route('admin.vacancies.update', $v)],
            ['DELETE', route('admin.vacancies.destroy', $v)],
        ];
        foreach ([null, $user] as $who) {
            foreach ($requests as [$method, $url]) {
                $who ? $this->actingAs($who) : auth()->logout();
                $res = $this->call($method, $url, ['status' => 'hired']);
                $this->assertContains($res->getStatusCode(), [302, 403], "$method $url");
                $this->assertFalse($res->isOk(), "$method $url");
            }
        }
        $this->assertSame('new', $app->fresh()->status);
        $this->assertNotNull(Vacancy::find($v->id));
    }

    public function test_public_form_cannot_mass_assign_status_notes_or_cv_path(): void
    {
        $this->apply($this->form($this->upload("%PDF-1.4\n%x", 'cv.pdf'), [
            'status' => 'hired', 'notes' => 'injected', 'cv_path' => '../.env', 'cv_name' => 'x.html', 'cv_size' => 1, 'locale' => 'xx',
            'vacancy_title' => 'CEO',
        ]))->assertSessionHasNoErrors();

        $app = JobApplication::sole();
        $this->assertSame('new', $app->status);
        $this->assertNull($app->notes);
        $this->assertStringStartsWith('cvs/', $app->cv_path);
        $this->assertSame('cv.pdf', $app->cv_name);
        $this->assertSame('en', $app->locale);
        $this->assertNull($app->vacancy_title);
    }

    public function test_inactive_vacancy_cannot_be_applied_to(): void
    {
        $v = Vacancy::create(['title' => ['uz' => 'T', 'en' => 'Closed'], 'department' => 'sales', 'employment_type' => 'full_time', 'is_active' => false]);
        $this->apply($this->form($this->upload("%PDF-1.4\n%x", 'cv.pdf'), ['vacancy_id' => $v->id]))->assertSessionHasErrors('vacancy_id');
        $this->apply($this->form($this->upload("%PDF-1.4\n%x", 'cv.pdf'), ['department' => '../../x']))->assertSessionHasErrors('department');
    }

    // ---- XSS -----------------------------------------------------------------------------

    public function test_applicant_text_is_escaped_in_admin_pages_and_telegram(): void
    {
        config(['services.telegram.bot_token' => 'TEST:TOKEN', 'services.telegram.chat_id' => '-100']);
        $payload = '"><img src=x onerror=alert(1)>';
        $this->apply($this->form($this->upload("%PDF-1.4\n%x", '<svg onload=alert(1)>.pdf'), [
            'name' => 'Ali '.$payload.' &amp; <b>',
            'message' => "<script>alert(1)</script>\n".$payload,
            'email' => 'a&b@example.com',
        ]))->assertSessionHasNoErrors();

        $app = JobApplication::sole();
        $admin = $this->admin();
        foreach ([route('admin.job-applications.index'), route('admin.job-applications.show', $app)] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('<img src=x', $html, $url);
            $this->assertStringNotContainsString('<script>alert(1)', $html, $url);
            $this->assertStringNotContainsString('<svg onload', $html, $url);
        }

        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), 'sendMessage')) {
                return false;
            }
            $text = $r['text'];

            return ! str_contains($text, '<img') && ! str_contains($text, '"><') && str_contains($text, '&amp;amp;') && str_contains($text, '&quot;&gt;');
        });
    }

    /** Was a finding: quoted local parts are now rejected (email:strict) and the href is encoded. */
    public function test_quoted_email_can_add_mailto_parameters_in_admin_link(): void
    {
        // email:rfc accepts a quoted local part with ? and = ; the admin mailto: link then carries extra headers.
        $this->apply($this->form($this->upload("%PDF-1.4\n%x", 'cv.pdf'), [
            'email' => '"x?bcc=attacker@evil.test&body=hi"@example.com',
        ]));
        $app = JobApplication::first();
        if ($app === null) {
            $this->assertSame(0, JobApplication::count()); // rejected by email:strict — safe

            return;
        }
        $html = $this->actingAs($this->admin())->get(route('admin.job-applications.show', $app))->getContent();
        $this->assertFalse(str_contains($html, 'mailto:&quot;x?bcc=attacker'), 'mailto link carries an attacker-controlled bcc');
    }

    public function test_vacancy_text_cannot_break_out_of_json_ld_or_html(): void
    {
        $x = '</script><script>alert(1)</script><!--';
        Vacancy::create([
            'title' => ['uz' => $x, 'en' => $x], 'location' => ['en' => $x], 'description' => ['en' => $x],
            'requirements' => ['en' => $x], 'offer' => ['en' => $x],
            'department' => 'sales', 'employment_type' => 'full_time', 'salary' => $x, 'is_active' => true,
        ]);

        $html = $this->get(route('site.careers', 'en'))->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('</script><script>', $html);
        $this->assertSame(0, substr_count($html, '<!--</'));

        $admin = $this->admin();
        $this->assertStringNotContainsString('<script>alert(1)', $this->actingAs($admin)->get(route('admin.vacancies.index'))->getContent());
        $this->assertStringNotContainsString('<script>alert(1)', $this->actingAs($admin)->get(route('admin.vacancies.edit', Vacancy::first()))->getContent());
        $this->assertStringNotContainsString("\n## ", explode('# BDB Auto ID', $this->get('/llms.txt')->getContent())[0]);
    }

    // ---- Size / abuse --------------------------------------------------------------------

    public function test_oversized_cv_is_rejected(): void
    {
        $big = "%PDF-1.4\n".str_repeat('A', 5121 * 1024);
        $this->apply($this->form($this->upload($big, 'cv.pdf')))->assertSessionHasErrors('cv');
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_long_text_fields_are_capped(): void
    {
        $this->apply($this->form($this->upload("%PDF-1.4\n%x", 'cv.pdf'), [
            'name' => str_repeat('a', 121), 'message' => str_repeat('a', 2001), 'phone' => str_repeat('1', 41),
        ]))->assertSessionHasErrors(['name', 'message', 'phone']);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    /**
     * FINDING (expected to fail): the plain `throttle:N,M` middleware keys by domain|IP only, so the
     * careers limiter (5 per 10 min) shares one counter with /chat/message (20 per 1 min). One cheap
     * chat request first creates the counter with a 60 s lifetime; the careers window then resets
     * every minute instead of every 10 minutes.
     */
    public function test_careers_rate_limit_cannot_be_shortened_via_chat_endpoint(): void
    {
        $accepted = 0;
        for ($round = 0; $round < 3; $round++) {
            $this->postJson('/chat/message', []); // 422, but counts on the shared throttle key
            for ($i = 0; $i < 5; $i++) {
                $res = $this->post(route('site.careers.apply', 'en'), ['website' => 'bot']); // honeypot: no file needed
                if ($res->getStatusCode() !== 429) {
                    $accepted++;
                }
            }
            $this->travel(61)->seconds();
        }

        $this->assertLessThanOrEqual(5, $accepted, "careers form accepted {$accepted} submissions in ~3 minutes (limit is 5 per 10 min)");
    }

    /** FINDING (expected to fail). Side effect of the same shared key:a visitor who chatted a little is locked out of the CV form. */
    public function test_chatting_does_not_lock_visitor_out_of_careers_form(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/chat/message', []);
        }
        $this->post(route('site.careers.apply', 'en'), ['website' => 'bot'])->assertStatus(302);
    }
}
