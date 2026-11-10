<?php

namespace App\Http\Middleware;

use App\Models\LocaleFormat;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the response language from the user's saved locale, then Accept-Language, among *active*
 * languages only (planned languages such as Arabic and French fall back to English until activated).
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->negotiate($request);
        app()->setLocale(strtok($locale, '-'));

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }

    private function negotiate(Request $request): string
    {
        $active = LocaleFormat::activeCodes();
        $languages = array_unique(array_map(fn ($code) => strtok($code, '-'), $active));
        $preferred = $request->user('sanctum')?->locale;

        foreach (array_filter([$preferred, ...$request->getLanguages()]) as $candidate) {
            $candidate = str_replace('_', '-', $candidate);
            foreach ($active as $code) {
                if (strcasecmp($code, $candidate) === 0) {
                    return $code;
                }
            }
            $language = strtolower((string) strtok($candidate, '-'));
            if (in_array($language, $languages, true)) {
                return $language;
            }
        }

        return (string) config('app.fallback_locale', 'en');
    }
}
