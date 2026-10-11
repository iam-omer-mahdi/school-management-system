<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /** @var list<string> */
    private const SUPPORTED = ['en', 'ar'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('locale');

        if (! is_string($locale) || ! in_array($locale, self::SUPPORTED, true)) {
            $user = $request->user();
            $userLocale = $user !== null ? ($user->getAttributes()['display_locale'] ?? null) : null;
            $locale = is_string($userLocale) && in_array($userLocale, self::SUPPORTED, true)
                ? $userLocale
                : (string) config('app.locale', 'en');
        }

        app()->setLocale($locale);

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
