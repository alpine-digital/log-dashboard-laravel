<?php

namespace AlpineDigital\LogDashboard\Support;

/**
 * Copied from the standalone Log-dashboard backend (App\Services\LogParser).
 * Kept as its own copy so the package is self-contained; if the parser gains
 * features, sync both or extract a shared library.
 */
class LogParser
{
    public static function parseLogs(
        string $filePath,
        int $limit = 100,
        int $page = 1,
        string $level = 'ALL',
        string $search = ''
    ): array {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return self::emptyResult($page, $limit);
        }

        $isLaravel = (bool) preg_match('/^\[\d{4}-\d{2}-\d{2}/', ltrim($content));
        $rawBlocks = self::splitRawLines($content, $isLaravel);

        $search = strtolower(trim($search));
        $levelFilter = strtoupper(trim($level));
        $offset = ($page - 1) * $limit;
        $collected = [];
        $totalAll = 0;
        $totalMatch = 0;
        $counts = ['ERROR' => 0, 'WARNING' => 0, 'INFO' => 0];

        foreach ($rawBlocks as $block) {
            $entry = self::parseSingleEntry($block, $isLaravel);
            if (! $entry) {
                continue;
            }

            $totalAll++;
            $entryLevel = $entry['level'] ?? 'INFO';
            if (isset($counts[$entryLevel])) {
                $counts[$entryLevel]++;
            }

            if ($levelFilter !== 'ALL' && $entryLevel !== $levelFilter) {
                continue;
            }

            $searchText = strtolower(
                $entry['message'].' '.
                ($entry['exception'] ?? '').' '.
                ($entry['stackTrace'] ?? '').' '.
                json_encode($entry['context'] ?? [])
            );

            if ($search !== '' && ! str_contains($searchText, $search)) {
                continue;
            }

            $totalMatch++;
            if ($totalMatch > $offset && count($collected) < $limit) {
                $collected[] = $entry;
            }
        }

        return [
            'entries' => $collected,
            'total' => $totalAll,
            'totalFiltered' => $totalMatch,
            'counts' => $counts,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    private static function parseSingleEntry(string $raw, bool $isLaravel): ?array
    {
        $raw = trim($raw);

        if ($isLaravel) {
            if (
                ! preg_match(
                    '/^\[(?<timestamp>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+\w+\.(?<level>[A-Z]+):\s*(?<body>.*)/s',
                    $raw,
                    $m
                )
            ) {
                return null;
            }
        } else {
            if (
                ! preg_match(
                    '/^(?<timestamp>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+\[(?<level>[^\]]+)\](?:\s+\[(?<component>[^\]]+)\])?\s*(?<body>.*)/s',
                    $raw,
                    $m
                )
            ) {
                return null;
            }
        }

        $timestamp = $m['timestamp'];
        $level = strtoupper(preg_replace('/^.*\./', '', $m['level']));
        $component = ($m['component'] ?? '') ?: null;
        $body = trim($m['body']);

        $memory = null;
        $stackTrace = null;
        $codeLocation = null;
        $requestUrI = null;
        $context = null;
        $exception = null;

        if (preg_match('/"memory":(\d+)/', $body, $mm)) {
            $memory = (int) $mm[1];
            $body = trim(preg_replace('/,?\s*"memory":\d+/', '', $body));
            $body = trim(preg_replace('/\{\s*\}/', '', $body));
        }

        $stackTraceMarker = str_contains($body, '[stacktrace]') ? '[stacktrace]' : 'Stack trace:';

        if (str_contains($body, $stackTraceMarker)) {
            [$before, $after] = explode($stackTraceMarker, $body, 2);
            $body = rtrim($before);
            $stackTrace = trim($after);

            $frames = [];
            preg_match_all('/#\d+\s+(\/\S+\.php[\(\:]\d+[\)\:]?)/', $stackTrace, $frames);

            $skipPatterns = ['vendor/', 'index.php', '{main}', '[internal'];

            foreach ($frames[1] as $frame) {
                $skip = false;
                foreach ($skipPatterns as $pattern) {
                    if (str_contains($frame, $pattern)) {
                        $skip = true;
                        break;
                    }
                }
                if (! $skip) {
                    $codeLocation = $frame;
                    break;
                }
            }

            if (! $codeLocation && ! empty($frames[1])) {
                $codeLocation = $frames[1][0];
            }
        }

        if (preg_match('/^(.*?)\s*(\{.+\})\s*$/s', $body, $jm)) {
            $decoded = json_decode($jm[2], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $beforeJson = trim($jm[1]);
                if ($beforeJson !== '') {
                    $body = $beforeJson;
                } elseif (isset($decoded['message'])) {
                    $body = (string) $decoded['message'];
                    unset($decoded['message']);
                }

                if (isset($decoded['trace'])) {
                    $traceData = $decoded['trace'];
                    if (is_array($traceData)) {
                        $stackTrace = $stackTrace ?? implode("\n", $traceData);

                        foreach ($traceData as $frame) {
                            if (! is_string($frame) || ! str_contains($frame, '.php')) {
                                continue;
                            }
                            if (str_contains($frame, 'vendor/')) {
                                continue;
                            }

                            if (preg_match('/(\/\S+\.php[\(\:]\d+[\)\:]?)/', $frame, $match)) {
                                $codeLocation = $match[1];
                                break;
                            }
                        }

                        if (! $codeLocation) {
                            foreach ($traceData as $frame) {
                                if (! is_string($frame) || ! str_contains($frame, '.php')) {
                                    continue;
                                }

                                if (preg_match('/(\/\S+\.php[\(\:]\d+[\)\:]?)/', $frame, $match)) {
                                    $codeLocation = $match[1];
                                    break;
                                }
                            }
                        }
                    }
                    unset($decoded['trace']);
                }

                if (isset($decoded['exception'])) {
                    $exception = (string) $decoded['exception'];
                    unset($decoded['exception']);
                }

                if (isset($decoded['memory'])) {
                    $memory = $memory ?? (int) $decoded['memory'];
                    unset($decoded['memory']);
                }

                if (isset($decoded['vars']['_SERVER']['REQUEST_URI'])) {
                    $requestUrI = $decoded['vars']['_SERVER']['REQUEST_URI'];
                    $requestUrI = explode('?', $requestUrI)[0];
                }

                if (! empty($decoded)) {
                    $context = $decoded;
                }
            }
        }

        if (! $requestUrI && preg_match('/\b(GET|POST|PUT|DELETE|PATCH)\s+(\/[a-zA-Z0-9_\-\/]+)/', $body, $ru)) {
            $requestUrI = $ru[1].' '.$ru[2];
        }

        if (! $codeLocation && $exception && preg_match('#at\s+(/.+\.php:\d+)#', $exception, $cl)) {
            $codeLocation = $cl[1];
        }

        $message = trim($body) ?: '(no message)';

        return [
            'timestamp' => $timestamp,
            'level' => $level,
            'component' => $component,
            'message' => $message,
            'stackTrace' => $stackTrace,
            'codeLocation' => $codeLocation,
            'requestUrI' => $requestUrI,
            'memory' => $memory,
            'context' => $context,
            'exception' => $exception,
        ];
    }

    private static function splitRawLines(string $content, bool $isLaravel): array
    {
        $lines = explode("\n", $content);
        $lines = array_map(fn ($l) => rtrim($l, "\r"), $lines);

        $rawBlocks = [];
        $current = [];

        foreach ($lines as $line) {
            $isNewEntry = $isLaravel
                ? (bool) preg_match('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/', $line)
                : (bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $line);

            if ($isNewEntry) {
                if (! empty($current)) {
                    $rawBlocks[] = implode("\n", $current);
                }
                $current = [$line];
            } else {
                if (! empty($current)) {
                    $current[] = $line;
                }
            }
        }

        if (! empty($current)) {
            $rawBlocks[] = implode("\n", $current);
        }

        return array_reverse($rawBlocks);
    }

    private static function emptyResult(int $page, int $limit): array
    {
        return [
            'entries' => [],
            'total' => 0,
            'totalFiltered' => 0,
            'counts' => ['ERROR' => 0, 'WARNING' => 0, 'INFO' => 0],
            'page' => $page,
            'limit' => $limit,
        ];
    }
}
