<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** invoices:process runs every minute in its own process, so waiting on Claude never delays the other scheduled jobs. */
class InvoiceScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_reading_is_scheduled_every_minute_in_the_background_without_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'invoices:process'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->runInBackground, 'runs in the background');
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
    }

    public function test_settings_page_explains_the_scheduler_delay(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get(route('admin.invoice-parser.edit'))->assertOk()
            ->assertSee('read by the scheduler every minute')
            ->assertDontSee('read right after it arrives');
    }
}
