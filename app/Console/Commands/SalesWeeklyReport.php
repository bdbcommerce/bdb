<?php

namespace App\Console\Commands;

use App\Models\SalesReportSetting;
use App\Services\SalesReport\SalesReporter;
use Illuminate\Console\Command;

/** Weekly sales report (scheduled Saturday 09:00 Tashkent): Claude analyses the last week of Telegram chats; posted to the invoice source group. */
class SalesWeeklyReport extends Command
{
    protected $signature = 'sales:weekly-report
        {--days=7 : How many days back to analyse}
        {--preview : Print the text Claude would get; nothing is sent to Claude or Telegram}
        {--manual : Started by hand (runs even when the report is switched off)}';

    protected $description = 'Analyse the last week of Telegram sales conversations with Claude and post the report to the Telegram group';

    public function handle(SalesReporter $reporter): int
    {
        $days = max(1, min(31, (int) $this->option('days')));
        $to = now()->startOfMinute(); // weekly runs then line up exactly
        $from = $to->copy()->subDays($days);
        $settings = SalesReportSetting::current();

        if ($this->option('preview')) {
            $data = $reporter->collect($from, $to, $settings->channelList());
            $this->line($data['text']);
            $this->info(count($data['chats']).' conversations, '.mb_strlen($data['text']).' characters.');

            return self::SUCCESS;
        }
        if (! $settings->enabled && ! $this->option('manual')) {
            $this->info('The weekly sales report is switched off (Admin → Telegram → Sales report).');

            return self::SUCCESS;
        }

        $report = $reporter->run($from, $to, $this->option('manual') ? 'manual' : 'schedule');
        if ($report->status === 'failed') {
            $this->error('Sales report failed: '.$report->error);

            return self::FAILURE;
        }
        $this->info("Sales report {$report->status}: {$report->conversations} conversations, ".count((array) $report->message_ids).' messages to '.$report->chat_id.'.'
            .($report->error ? ' '.$report->error : ''));

        return self::SUCCESS;
    }
}
