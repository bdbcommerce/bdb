<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->route('locale', config('app.locale'));

        if (! in_array($locale, ['uz', 'ru', 'en'])) {
            abort(404);
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
