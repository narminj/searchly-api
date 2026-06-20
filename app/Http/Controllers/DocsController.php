<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the technical documentation from storage/app/docs (a NON-public path)
 * so it is reachable only through these authenticated, role-gated routes —
 * never from the public web root.
 *
 * Language is chosen with ?lang=az (Azerbaijani) or en/default (English).
 * Missing translations fall back to English.
 */
class DocsController extends Controller
{
    public function index(Request $request): BinaryFileResponse
    {
        return $this->serve('html', $request);        // graphical Mermaid edition
    }

    public function offline(Request $request): BinaryFileResponse
    {
        return $this->serve('offline.html', $request); // no-CDN edition
    }

    public function pdf(Request $request): BinaryFileResponse
    {
        return $this->serve('pdf', $request);
    }

    private function serve(string $kind, Request $request): BinaryFileResponse
    {
        $dir    = config('docs.path', storage_path('app/docs'));
        $suffix = $request->query('lang') === 'az' ? '.az' : '';

        $path = "{$dir}/TECHNICAL_DOCUMENTATION{$suffix}.{$kind}";

        // Fall back to English when the requested translation isn't present
        if ($suffix !== '' && ! is_file($path)) {
            $path = "{$dir}/TECHNICAL_DOCUMENTATION.{$kind}";
        }

        abort_unless(is_file($path), 404);

        // response()->file() infers the content type and serves inline
        return response()->file($path);
    }
}
