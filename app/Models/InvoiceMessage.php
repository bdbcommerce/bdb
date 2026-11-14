<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A photo / file received in the invoice source chat, and what Claude read from it. Admin → Invoice parser → Messages. */
class InvoiceMessage extends Model
{
    /** Failed reads are retried by `invoices:process` up to this many attempts in total. */
    public const MAX_ATTEMPTS = 3;

    public const STATUSES = [
        'pending' => 'Waiting',
        'processing' => 'Reading',
        'sent' => 'Sent',
        'skipped' => 'Not an invoice',
        'failed' => 'Failed',
    ];

    protected $fillable = [
        'chat_id', 'message_id', 'file_id', 'mime', 'sender', 'caption', 'status', 'attempts', 'result',
        'error', 'model', 'input_tokens', 'output_tokens', 'sent_message_id', 'read_at', 'processed_at',
    ];

    protected function casts(): array
    {
        return ['result' => 'array', 'read_at' => 'datetime', 'processed_at' => 'datetime'];
    }

    /** New, stuck, or failed with attempts left — read by the scheduled `invoices:process` (every minute, one at a time). */
    public function scopeDue($query)
    {
        // One grouped condition, so it combines safely with other where()s.
        return $query->where(fn ($outer) => $outer
            ->where('status', 'pending')
            // A read that crashed mid-way (e.g. the PHP worker was killed) is looked at again after 10 minutes;
            // process() marks it failed when no attempts are left.
            ->orWhere(fn ($q) => $q->where('status', 'processing')->where('updated_at', '<', now()->subMinutes(10)))
            ->orWhere(fn ($q) => $q->where('status', 'failed')->where('attempts', '<', self::MAX_ATTEMPTS)->where('updated_at', '<', now()->subMinutes(5))));
    }
}
