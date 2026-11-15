<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One paid Claude read of an invoice photo and what it cost (US dollars). Kept when the photo is deleted. */
class InvoiceRead extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['invoice_message_id', 'model', 'input_tokens', 'output_tokens', 'cache_write_tokens', 'cache_read_tokens', 'cost_usd'];

    protected function casts(): array
    {
        return ['cost_usd' => 'float'];
    }

    public function invoiceMessage(): BelongsTo
    {
        return $this->belongsTo(InvoiceMessage::class);
    }
}
