<?php

namespace App\Http\Controllers\PublicForms;

use Illuminate\Http\Response;

/**
 * 9.8c: serves the built standalone lead-form page at /f/{uuid}.
 * The page itself is static; it fetches the form definition from /api/public/forms/{uuid}.
 * No X-Frame-Options header is added on purpose: the share box hands out an iframe snippet.
 */
class LeadFormPageController
{
    public function __invoke(string $uuid): Response
    {
        $manifest = $this->manifest();
        $entry = $manifest['form.html'] ?? null;

        if (! is_array($entry) || empty($entry['file'])) {
            abort(503, 'The public form page has not been built yet. Run "npm run build" in the frontend folder.');
        }

        $head = '';
        foreach (($entry['css'] ?? []) as $css) {
            $head .= '<link rel="stylesheet" href="'.e('/build/'.$css).'" />'."\n";
        }
        foreach (($entry['imports'] ?? []) as $key) {
            $file = $manifest[$key]['file'] ?? null;
            if (is_string($file)) {
                $head .= '<link rel="modulepreload" href="'.e('/build/'.$file).'" />'."\n";
            }
        }

        $template = '<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="robots" content="noindex" />
<title>Contact</title>
%s</head>
<body>
<div id="root"></div>
<script type="module" src="%s"></script>
</body>
</html>
';

        return response(
            sprintf($template, $head, e('/build/'.$entry['file'])),
            200,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-cache',
                'Referrer-Policy' => 'no-referrer',
            ],
        );
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        foreach ([public_path('build/.vite/manifest.json'), public_path('build/manifest.json')] as $path) {
            if (is_file($path)) {
                $data = json_decode((string) file_get_contents($path), true);
                if (is_array($data)) {
                    return $data;
                }
            }
        }

        return [];
    }
}
