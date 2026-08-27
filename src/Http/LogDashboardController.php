<?php

namespace AlpineDigital\LogDashboard\Http;

use AlpineDigital\LogDashboard\Support\LocalLogReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class LogDashboardController extends Controller
{
    public function __construct(private readonly LocalLogReader $reader) {}

    public function logs(): JsonResponse
    {
        return response()->json($this->reader->listLogFiles());
    }

    public function logContent(Request $request): JsonResponse
    {
        $file = (string) $request->input('file', '');

        if ($file === '') {
            return response()->json(['error' => 'Missing file parameter'], 400);
        }

        $page = max(1, (int) $request->input('page', 1));
        $limit = max(1, min(500, (int) $request->input('limit', 100)));
        $level = strtoupper((string) $request->input('level', 'ALL'));
        $search = trim((string) $request->input('search', ''));

        try {
            return response()->json(
                $this->reader->readLogContent($file, $page, $limit, $level, $search)
            );
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Serve the built SPA: a real static file (JS/CSS/font) when the path maps
     * to one, otherwise the index shell for client-side routes.
     */
    public function serve(string $path = ''): Response
    {
        $dist = realpath($this->distPath());

        if ($dist === false) {
            return response(
                'Log Dashboard UI is not built yet. Run "npm run build:package" in the frontend.',
                500
            );
        }

        if ($path !== '') {
            $full = realpath($dist.'/'.$path);

            if ($full !== false && str_starts_with($full, $dist) && is_file($full)) {
                return $this->fileResponse($full);
            }
        }

        return $this->spaShell($dist);
    }

    /**
     * Return the index shell with the SPA pointed at the configured prefix and
     * handed its embedded-mode runtime config.
     */
    private function spaShell(string $dist): Response
    {
        $index = $dist.'/index.html';

        if (! is_file($index)) {
            return response('Log Dashboard UI is not built yet.', 500);
        }

        $prefix = $this->prefix();
        $html = (string) file_get_contents($index);

        $base = '<base href="/'.e($prefix).'/">';
        $html = preg_replace('/<base href="[^"]*">/', $base, $html, 1, $replaced);
        if (! $replaced) {
            $html = str_replace('<head>', '<head>'.$base, $html);
        }

        $config = json_encode([
            'embedded' => true,
            'apiBase' => '/'.$prefix.'/api',
            'name' => $this->projectName(),
        ]);
        $html = str_replace('</head>', '<script>window.__LOG_DASHBOARD__='.$config.';</script></head>', $html);

        // Never cache the shell: its hashed asset references change on each
        // build, so a cached shell would keep loading stale JS.
        return response($html, 200)
            ->header('Content-Type', 'text/html')
            ->header('Cache-Control', 'no-store, must-revalidate');
    }

    private function fileResponse(string $full): Response
    {
        $mimes = [
            'js' => 'text/javascript',
            'css' => 'text/css',
            'html' => 'text/html',
            'json' => 'application/json',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'map' => 'application/json',
        ];
        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));

        return response((string) file_get_contents($full), 200)
            ->header('Content-Type', $mimes[$ext] ?? 'application/octet-stream');
    }

    /**
     * The Angular application builder emits into a `browser/` subfolder.
     */
    private function distPath(): string
    {
        return __DIR__.'/../../resources/dist/browser';
    }

    private function prefix(): string
    {
        return trim((string) config('log-dashboard.route_prefix', 'log-dashboard'), '/');
    }

    /**
     * Project label for the dashboard: the "name" from the host project's
     * package.json, falling back to config('app.name').
     */
    private function projectName(): string
    {
        $packageJson = base_path('package.json');

        if (is_file($packageJson)) {
            $data = json_decode((string) file_get_contents($packageJson), true);

            if (is_array($data) && ! empty($data['name'])) {
                return (string) $data['name'];
            }
        }

        return (string) config('app.name');
    }
}
