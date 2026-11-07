<?php

namespace App\Http\Controllers;

use App\Models\McpSetting;
use App\Services\Mcp\McpServer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * MCP endpoint (Streamable HTTP transport): POST /mcp with JSON-RPC messages, JSON responses.
 * Off unless enabled in Admin → AI Assistant → MCP server; optional Bearer token.
 */
class McpController extends Controller
{
    public const MAX_BATCH = 10;

    public function __invoke(Request $request): Response
    {
        $settings = McpSetting::current();
        abort_unless($settings->enabled, 404);

        // No server-sent event stream: everything is answered in the POST response.
        if (! $request->isMethod('POST')) {
            return response('', 405, ['Allow' => 'POST']);
        }

        // DNS-rebinding protection: a browser page may only call from this site's own public address.
        $origin = $request->headers->get('Origin');
        $publicHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if ($origin !== null && ! in_array(parse_url($origin, PHP_URL_HOST), array_filter([$publicHost]), true)) {
            return $this->jsonError(403, 'Origin not allowed');
        }

        // Wrong tokens are throttled per IP before any further work.
        $authKey = 'mcp-auth:'.$request->ip();
        if ($settings->require_token && ! $settings->tokenMatches($request->bearerToken())) {
            if (RateLimiter::tooManyAttempts($authKey, 10)) {
                return $this->jsonError(429, 'Too many failed attempts. Try again later.')->header('Retry-After', (string) RateLimiter::availableIn($authKey));
            }
            RateLimiter::hit($authKey, 300);

            return $this->jsonError(401, 'A valid access token is required (Authorization: Bearer …).')
                ->header('WWW-Authenticate', 'Bearer realm="bdb-mcp"');
        }

        $client = $settings->require_token ? 'token' : 'ip:'.$request->ip();

        $body = $request->getContent();
        if (strlen($body) > 100_000) {
            return $this->jsonError(413, 'Request too large');
        }
        $payload = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']], 400);
        }

        $server = new McpServer($settings, $client);
        $isBatch = is_array($payload) && array_is_list($payload) && $payload !== [];
        $messages = $isBatch ? $payload : [$payload];
        if (count($messages) > self::MAX_BATCH) {
            return $this->jsonError(400, 'At most '.self::MAX_BATCH.' messages per request.');
        }

        // Every message counts against the per-minute limit (a batch is not one request).
        $limitKey = 'mcp:'.$client;
        $limit = max(1, (int) $settings->rate_limit);
        if (RateLimiter::attempts($limitKey) + count($messages) > $limit) {
            return $this->jsonError(429, 'Too many requests. Try again in a minute.')->header('Retry-After', (string) max(1, RateLimiter::availableIn($limitKey)));
        }
        RateLimiter::increment($limitKey, 60, count($messages));
        $responses = array_values(array_filter(array_map(fn ($m) => $server->handle($m), $messages)));

        $settings->newQuery()->whereKey($settings->getKey())->update([
            'last_used_at' => now(), 'calls_count' => DB::raw('calls_count + '.count($messages)),
        ]);

        if ($responses === []) {
            return response('', 202); // only notifications
        }

        $response = response()->json($isBatch ? $responses : $responses[0], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $isInitialize = collect($messages)->contains(fn ($m) => is_array($m) && ($m['method'] ?? null) === 'initialize');

        return $isInitialize ? $response->header('Mcp-Session-Id', (string) Str::uuid()) : $response;
    }

    private function jsonError(int $status, string $message)
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32000, 'message' => $message]], $status);
    }
}
