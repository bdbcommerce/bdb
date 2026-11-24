<?php

namespace App\Http\Controllers;

use App\Models\CatalogSetting;
use App\Services\Catalog\CatalogImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/** POST /api/catalog — push mode: 1C sends the full catalog with the Bearer token (docs/1c-catalog-http-service.md). */
class CatalogPushController extends Controller
{
    /** Wrong tokens per IP before the IP is blocked for 10 minutes. */
    public const MAX_BAD_TOKENS = 10;

    public function __invoke(Request $request, CatalogImporter $importer): JsonResponse
    {
        $key = 'catalog-token|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_BAD_TOKENS)) {
            return response()->json(['error' => 'Too many wrong tokens, try again later.'], 429);
        }

        $settings = CatalogSetting::current();
        if (! $settings->tokenMatches($request->bearerToken())) {
            RateLimiter::hit($key, 600);

            return response()->json(['error' => 'Wrong or missing token.'], 401);
        }
        if ($settings->source !== 'push') {
            return response()->json(['error' => 'Push is switched off: the catalog is loaded from 1C (pull mode).'], 409);
        }
        if ((int) $request->header('Content-Length') > CatalogImporter::MAX_BYTES || strlen($request->getContent()) > CatalogImporter::MAX_BYTES) {
            return response()->json(['error' => 'The body is larger than 20 MB.'], 413);
        }

        $lock = Cache::lock('catalog-import', 180);
        if (! $lock->get()) {
            return response()->json(['error' => 'Another catalog load is running, try again in a minute.'], 409);
        }

        try {
            @set_time_limit(120);
            $data = json_decode($request->getContent(), true);
            ['load' => $load, 'rejected' => $rejected] = is_array($data)
                ? $importer->import($data, 'push', 'push')
                : $importer->failed('push', 'push', 'The body is not JSON ('.json_last_error_msg().').');
        } finally {
            $lock->release();
        }

        return response()->json(array_filter([
            'status' => $load->status,
            'error' => $load->error,
            'received' => $load->received,
            'accepted' => $load->accepted,
            'rejected' => $rejected,
            'hidden' => $load->hidden,
            'clients' => $load->clients,
        ], fn ($v) => $v !== null), $load->status === 'ok' ? 200 : 422);
    }
}
