<?php

namespace App\Http\Middleware;

use App\Models\PageView;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Counts public page views for Admin → Site analytics, after the response is sent.
 * Skips admins, non-GET requests, errors and browser prefetches. Never breaks the page.
 */
class TrackPageView
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethod('GET') && $response->getStatusCode() === 200 && ! $request->user()?->is_admin
            && ! in_array(strtolower((string) $request->headers->get('Purpose', $request->headers->get('Sec-Purpose'))), ['prefetch', 'preview'], true)) {
            try {
                $view = PageView::fromRequest($request);
                defer(function () use ($view) {
                    try {
                        $view->save();
                    } catch (Throwable) {
                        // Analytics must never affect the visitor (e.g. table not migrated yet).
                    }
                });
            } catch (Throwable) {
            }
        }

        return $response;
    }
}
