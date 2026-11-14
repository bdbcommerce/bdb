<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InvoiceMessage;
use App\Services\Invoices\ClaudeInvoiceReader;
use App\Services\Invoices\InvoiceParser;
use App\Services\Telegram\TelegramApi;
use Illuminate\Http\Request;

use function Illuminate\Support\defer;

/** Admin → Invoice parser → Messages: every photo from the source chat and what Claude read from it. */
class InvoiceMessageController extends Controller
{
    public function index(Request $request)
    {
        $status = array_key_exists((string) $request->query('status'), InvoiceMessage::STATUSES) ? $request->query('status') : null;

        return view('admin.invoice-parser.index', [
            'messages' => InvoiceMessage::query()->when($status, fn ($q) => $q->where('status', $status))->latest('id')->paginate(30)->withQueryString(),
            'counts' => InvoiceMessage::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'status' => $status,
        ]);
    }

    public function show(InvoiceMessage $invoiceMessage)
    {
        return view('admin.invoice-parser.show', ['invoice' => $invoiceMessage]);
    }

    /** The photo, proxied from Telegram (its file URL contains the bot token). */
    public function image(InvoiceMessage $invoiceMessage, TelegramApi $telegram)
    {
        $file = $telegram->downloadFile($invoiceMessage->file_id);
        abort_unless($file, 404);
        $pdf = $invoiceMessage->mime === 'application/pdf';
        $type = $pdf || in_array($invoiceMessage->mime, ClaudeInvoiceReader::IMAGE_TYPES, true) ? $invoiceMessage->mime : 'image/jpeg';

        return response($file['body'], 200, [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, max-age=3600',
        ] + ($pdf ? [] : ['Content-Disposition' => 'inline']));
    }

    /** Read it again (after a failure, or with a changed prompt/model). Runs after the response. */
    public function retry(InvoiceMessage $invoiceMessage, InvoiceParser $parser)
    {
        abort_if($invoiceMessage->status === 'processing' && $invoiceMessage->updated_at->gt(now()->subMinutes(10)), 409, 'Already being read.');

        // A fresh read (the old result is dropped), even over the daily limit: an admin asked for it.
        $invoiceMessage->update(['status' => 'pending', 'attempts' => 0, 'error' => null, 'result' => null]);
        app()->instance('invoice-parser.skip-limit', $invoiceMessage->id);
        defer(fn () => $parser->process($invoiceMessage));

        return redirect()->route('admin.invoice-messages.show', $invoiceMessage)
            ->with('success', 'Sent to Claude again — refresh in half a minute. A new summary is posted to the destination chat if it is an invoice.');
    }

    public function destroy(InvoiceMessage $invoiceMessage)
    {
        $invoiceMessage->delete();

        return redirect()->route('admin.invoice-messages.index')->with('success', 'Deleted from the list (the Telegram messages stay).');
    }
}
