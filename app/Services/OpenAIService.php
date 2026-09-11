<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class OpenAIService
{
    protected Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'timeout' => 30,
            'connect_timeout' => 10,
            'http_errors' => true,
        ]);
    }

    public function shouldRetryWithFallback(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, '429')
            || str_contains($message, '400')
            || str_contains($message, '404')
            || str_contains($message, 'too many requests')
            || str_contains($message, 'rate limit')
            || str_contains($message, 'quota')
            || str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'not found')
            || str_contains($message, 'does not exist')
            || str_contains($message, 'decommissioned')
            || str_contains($message, 'no longer supported')
            || str_contains($message, 'internal server error')
            || str_contains($message, 'server error')
            || str_contains($message, 'service unavailable')
            || str_contains($message, 'bad gateway')
            || str_contains($message, 'gateway timeout')
            || str_contains($message, '5xx');
    }

    protected function formatThrowableForLog(Throwable $e): string
    {
        $details = [$e->getMessage()];

        if (method_exists($e, 'getResponse') && $e->getResponse() !== null) {
            $response = $e->getResponse();
            $body = $response->getBody();

            if (is_object($body) && method_exists($body, 'getContents')) {
                $body->seek(0);
                $raw = $body->getContents();
                $body->seek(0);

                if (is_string($raw) && trim($raw) !== '') {
                    $details[] = $raw;
                }
            }
        }

        return implode(' | ', $details);
    }

    protected function logFallback(string $from, string $to, Throwable $e): void
    {
        Log::warning(sprintf('[FALLBACK_EVENT] Primary (%s) failed: %s. Switching to %s', $from, $this->formatThrowableForLog($e), $to));
    }

    protected function providerLabel(string $provider): string
    {
        return match ($provider) {
            'groq' => 'Groq',
            'gemini' => 'Gemini',
            'openrouter' => 'OpenRouter',
            'local-whisper' => 'Local Whisper',
            'edge-tts' => 'Edge TTS',
            'gtts' => 'gTTS',
            default => ucfirst($provider),
        };
    }

    protected function resolvePythonBinary(): ?string
    {
        foreach (['py', 'python', 'python3'] as $binary) {
            $which = shell_exec(sprintf('where %s 2> NUL', $binary));
            if (is_string($which) && trim($which) !== '') {
                $paths = array_filter(array_map('trim', explode(PHP_EOL, $which)));

                foreach ($paths as $path) {
                    if (str_contains(strtolower($path), 'windowsapps')) {
                        continue;
                    }

                    if ($this->isRealPythonBinary($path)) {
                        return $path;
                    }
                }
            }

            $which = shell_exec(sprintf('which %s 2>/dev/null', $binary));
            if (is_string($which) && trim($which) !== '') {
                $path = trim($which);
                if (str_contains(strtolower($path), 'windowsapps')) {
                    continue;
                }

                if ($this->isRealPythonBinary($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * Windows ka "python.exe" Microsoft Store alias stub ho sakta hy jo
     * `where` ko mil to jata hy lekin asal Python nahi hota — run karne pe
     * sirf "Python was not found..." print karta hy aur exit ho jata hy.
     * Yeh method asal binary check karta hy `--version` chala kar.
     */
    protected function isRealPythonBinary(string $path): bool
    {
        $path = trim($path);
        if ($path === '') {
            return false;
        }

        $output = shell_exec(sprintf('"%s" --version 2>&1', str_replace('"', '', $path)));

        if (!is_string($output)) {
            return false;
        }

        $output = trim($output);

        if (str_contains(strtolower($output), 'python was not found')) {
            return false;
        }

        return (bool) preg_match('/^Python\s+\d+\.\d+/i', $output);
    }

    protected function resolveWhisperBinary(): ?string
    {
        foreach (['whisper', 'whisper.exe'] as $binary) {
            $which = shell_exec(sprintf('where %s 2> NUL', $binary));
            if (is_string($which) && trim($which) !== '') {
                return trim(explode(PHP_EOL, $which)[0]);
            }

            $which = shell_exec(sprintf('which %s 2>/dev/null', $binary));
            if (is_string($which) && trim($which) !== '') {
                return trim($which);
            }
        }

        return null;
    }

    protected function ensureSandboxDirectories(): void
    {
        $workspace = base_path('agent-workspace');
        $screenshots = storage_path('app/screenshots');

        if (!is_dir($workspace)) {
            mkdir($workspace, 0777, true);
        }

        if (!is_dir($screenshots)) {
            mkdir($screenshots, 0777, true);
        }
    }

    protected function resolveSandboxPath(string $filePath): string
    {
        $this->ensureSandboxDirectories();

        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim((string) $filePath));

        if ($normalized === '' || str_contains($normalized, '..')) {
            throw new RuntimeException('Requested file path is outside the sandboxed agent-workspace directory.');
        }

        $base = realpath(base_path('agent-workspace'));
        if ($base === false) {
            throw new RuntimeException('Sandboxed agent-workspace directory does not exist.');
        }

        $candidate = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($normalized, DIRECTORY_SEPARATOR);
        $candidate = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate);

        $realParent = realpath(dirname($candidate));
        if ($realParent === false) {
            $buildPath = dirname($candidate);
            if (!mkdir($buildPath, 0777, true) && !is_dir($buildPath)) {
                throw new RuntimeException('Unable to create sandbox directories for the requested file path.');
            }
            $realParent = realpath($buildPath);
        }

        if ($realParent === false || !str_starts_with($realParent, $base . DIRECTORY_SEPARATOR) && $realParent !== $base) {
            throw new RuntimeException('Requested file path is outside the sandboxed agent-workspace directory.');
        }

        return $candidate;
    }

    protected function normalizeToolArguments(mixed $arguments): array
    {
        if (is_array($arguments)) {
            return $arguments;
        }

        if (is_string($arguments) && trim($arguments) !== '') {
            $decoded = json_decode($arguments, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    public function getAvailableTools(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'open_application',
                    'description' => 'Open a whitelisted desktop application on this machine.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'app_name' => [
                                'type' => 'string',
                                'enum' => ['notepad', 'calculator', 'chrome', 'explorer', 'vscode'],
                                'description' => 'The exact application name to open from the allowed list.',
                            ],
                        ],
                        'required' => ['app_name'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'read_file',
                    'description' => 'Read a file from the sandboxed agent-workspace directory only.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'file_path' => [
                                'type' => 'string',
                                'description' => 'Relative file path inside the agent-workspace directory, for example notes/todo.txt',
                            ],
                        ],
                        'required' => ['file_path'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'write_file',
                    'description' => 'Write content to a file inside the sandboxed agent-workspace directory only.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'file_path' => [
                                'type' => 'string',
                                'description' => 'Relative file path inside the agent-workspace directory.',
                            ],
                            'content' => [
                                'type' => 'string',
                                'description' => 'Text content to write to the file.',
                            ],
                        ],
                        'required' => ['file_path', 'content'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'youtube_search_and_play',
                    'description' => 'Open a YouTube search result page or channel in the default browser.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                                'description' => 'YouTube query, video name, or channel handle to open.',
                            ],
                            'mode' => [
                                'type' => 'string',
                                'enum' => ['search', 'channel'],
                                'description' => 'Use search (default) to open results, or channel to open a direct channel URL.',
                            ],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'open_vpn',
                    'description' => 'Open the configured VPN client application without auto-connecting.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'action' => [
                                'type' => 'string',
                                'enum' => ['open'],
                                'description' => 'Only open is supported in this phase to avoid risky auto-connect behavior.',
                            ],
                        ],
                        'required' => [],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'open_browser',
                    'description' => 'Open a supported browser with an optional profile or direct URL.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'browser' => [
                                'type' => 'string',
                                'enum' => ['chrome', 'edge', 'firefox'],
                                'description' => 'Browser to open.',
                            ],
                            'profiles' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'string',
                                ],
                                'description' => 'Optional profile names to open. If empty, the default profile is used.',
                            ],
                            'url' => [
                                'type' => 'string',
                                'description' => 'Optional direct URL to open in the browser after launch.',
                            ],
                        ],
                        'required' => ['browser'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'take_screenshot',
                    'description' => 'Capture the current desktop and save it to storage/app/screenshots.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'web_search',
                    'description' => 'Placeholder web search tool. Returns a configured error until an external search API is wired up.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                                'description' => 'Search query to execute once the provider is configured.',
                            ],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
        ];
    }

    protected function listChromeProfiles(): array
    {
        $localAppData = getenv('LOCALAPPDATA');
        if (!is_string($localAppData) || trim($localAppData) === '') {
            return [];
        }

        $stateFile = rtrim($localAppData, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'Google'
            . DIRECTORY_SEPARATOR . 'Chrome'
            . DIRECTORY_SEPARATOR . 'User Data'
            . DIRECTORY_SEPARATOR . 'Local State';

        if (!is_file($stateFile)) {
            return [];
        }

        $contents = @file_get_contents($stateFile);
        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded) || !isset($decoded['profile']['info_cache'])) {
            return [];
        }

        $profiles = [];
        foreach ($decoded['profile']['info_cache'] as $directory => $meta) {
            if (!is_array($meta)) {
                continue;
            }

            $displayName = (string) ($meta['name'] ?? $meta['profile_name'] ?? $directory);
            $profiles[(string) $directory] = $displayName;
        }

        return $profiles;
    }

    protected function sanitizeForShell(string $value): string
    {
        $safe = trim((string) $value);
        $safe = str_replace(['"', "'", '&', '|', ';', '`', '<', '>'], '', $safe);

        return $safe;
    }

    protected function resolveBrowserExecutable(string $browser): ?string
    {
        $browser = strtolower(trim($browser));

        $candidates = match ($browser) {
            'chrome' => ['chrome', 'google-chrome', 'chrome.exe'],
            'edge' => ['msedge', 'microsoft-edge', 'msedge.exe'],
            'firefox' => ['firefox', 'firefox.exe'],
            default => [],
        };

        if ($candidates === []) {
            return null;
        }

        foreach ($candidates as $candidate) {
            $which = shell_exec(sprintf('where %s 2> NUL', $candidate));
            if (is_string($which) && trim($which) !== '') {
                return trim(explode(PHP_EOL, (string) $which)[0]);
            }

            $which = shell_exec(sprintf('which %s 2>/dev/null', $candidate));
            if (is_string($which) && trim($which) !== '') {
                return trim($which);
            }
        }

        return $candidates[0] ?? null;
    }

    protected function openBrowserTool(string $browser, array $profiles = [], string $url = ''): array
    {
        $browser = strtolower(trim((string) $browser));
        $allowed = ['chrome', 'edge', 'firefox'];

        if (!in_array($browser, $allowed, true)) {
            return ['success' => false, 'message' => sprintf('Browser "%s" is not supported. Allowed browsers: %s', $browser, implode(', ', $allowed))];
        }

        $resolvedExecutable = $this->resolveBrowserExecutable($browser);
        if ($resolvedExecutable === null) {
            return ['success' => false, 'message' => sprintf('Browser executable for "%s" was not found in PATH.', $browser)];
        }

        // Chrome/Edge ke liye actual available profiles nikalo taake fuzzy-match kar sakein
        $knownProfiles = ($browser === 'chrome') ? $this->listChromeProfiles() : [];

        $safeProfiles = [];
        if (!empty($profiles)) {
            foreach ($profiles as $profile) {
                $clean = $this->sanitizeForShell((string) $profile);
                if ($clean === '') {
                    continue;
                }

                // Agar Chrome ki actual profile list available ho, to match karo
                if ($browser === 'chrome' && $knownProfiles !== []) {
                    $matched = $this->matchProfileName($clean, $knownProfiles);
                    $safeProfiles[] = $matched ?? $clean;
                } else {
                    $safeProfiles[] = $clean;
                }
            }
        }

        if ($browser === 'firefox') {
            $safeProfiles = $safeProfiles !== [] ? [$safeProfiles[0]] : ['Default'];
        } else {
            $safeProfiles = $safeProfiles !== [] ? $safeProfiles : ['Default'];
        }

        $safeUrl = trim((string) $url);
        if ($safeUrl !== '') {
            $safeUrl = 'https://' . preg_replace('/^https?:\/\//i', '', $safeUrl);
        }

        $opened = [];
        $successfulLaunches = 0;

        foreach ($safeProfiles as $profileName) {
            $command = sprintf('cmd /c start "" "%s"', $resolvedExecutable);

            if (in_array($browser, ['chrome', 'edge'], true)) {
                $command .= sprintf(' --profile-directory="%s"', str_replace('"', '', $profileName));
            }

            if ($browser === 'firefox') {
                $command .= sprintf(' -P "%s"', str_replace('"', '', $profileName));
            }

            if ($safeUrl !== '') {
                $command .= sprintf(' "%s"', $safeUrl);
            }

            try {
                exec($command . ' 2>NUL', $output, $exitCode);
                $opened[] = [
                    'browser' => $browser,
                    'profile' => $profileName,
                    'url' => $safeUrl !== '' ? $safeUrl : null,
                    'exitCode' => $exitCode,
                ];

                if ($exitCode === 0) {
                    $successfulLaunches++;
                }
            } catch (Throwable $e) {
                $opened[] = [
                    'browser' => $browser,
                    'profile' => $profileName,
                    'url' => $safeUrl !== '' ? $safeUrl : null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $result = [
            'success' => $successfulLaunches > 0,
            'message' => sprintf('Opened %s browser profile(s).', $browser),
            'opened' => $opened,
        ];

        Log::info('[TOOL_CALL]', ['tool' => 'open_browser', 'args' => ['browser' => $browser, 'profiles' => $profiles, 'url' => $url], 'result' => $result]);

        return $result;
    }
    protected function matchProfileName(string $requested, array $knownProfiles): ?string
    {
        $normalize = fn(string $s) => strtolower(str_replace(' ', '', $s));
        $requestedNormalized = $normalize($requested);

        foreach (array_keys($knownProfiles) as $directory) {
            if ($normalize($directory) === $requestedNormalized) {
                return $directory; // asal directory name return karo, e.g. "Profile 1"
            }
        }

        // Display name se bhi match try karo (e.g. user "Work" bole, meta name "Work" ho)
        foreach ($knownProfiles as $directory => $displayName) {
            if ($normalize($displayName) === $requestedNormalized) {
                return $directory;
            }
        }

        return null; // koi match nahi mila, original value use hoga
    }
    protected function openApplicationTool(string $appName): array
    {
        $allowed = ['notepad', 'calculator', 'chrome', 'explorer', 'vscode'];
        $normalized = strtolower(trim((string) $appName));

        if (!in_array($normalized, $allowed, true)) {
            return [
                'success' => false,
                'message' => sprintf('Application "%s" is not allowed. Allowed apps: %s', $appName, implode(', ', $allowed)),
            ];
        }

        $commands = [
            'notepad' => 'notepad',
            'calculator' => 'calc',
            'chrome' => 'chrome',
            'explorer' => 'explorer',
            'vscode' => 'code',
        ];

        $command = $commands[$normalized];

        try {
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $launch = sprintf('cmd /c start "" "%s"', $command);
            } else {
                $launch = sprintf('nohup %s >/dev/null 2>&1 &', escapeshellarg($command));
            }

            exec($launch . ' 2>NUL', $output, $exitCode);

            if ($exitCode !== 0 && !in_array($normalized, ['explorer', 'notepad'], true)) {
                return [
                    'success' => false,
                    'message' => sprintf('Unable to launch %s from this environment.', $normalized),
                ];
            }

            return [
                'success' => true,
                'message' => sprintf('Opened %s successfully.', $normalized),
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => sprintf('Unable to launch %s: %s', $normalized, $e->getMessage()),
            ];
        }
    }

    protected function readFileTool(string $filePath): array
    {
        try {
            $resolved = $this->resolveSandboxPath($filePath);
            if (!is_file($resolved)) {
                return ['success' => false, 'message' => sprintf('File not found in sandbox: %s', $filePath)];
            }

            $content = file_get_contents($resolved);
            if ($content === false) {
                return ['success' => false, 'message' => sprintf('Unable to read file: %s', $filePath)];
            }

            return [
                'success' => true,
                'path' => $resolved,
                'content' => $content,
            ];
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    protected function writeFileTool(string $filePath, string $content): array
    {
        try {
            $resolved = $this->resolveSandboxPath($filePath);
            $directory = dirname($resolved);
            if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                return ['success' => false, 'message' => sprintf('Unable to create directory for %s', $filePath)];
            }

            $written = file_put_contents($resolved, $content);
            if ($written === false) {
                return ['success' => false, 'message' => sprintf('Unable to write file: %s', $filePath)];
            }

            return [
                'success' => true,
                'path' => $resolved,
                'message' => sprintf('Wrote %s bytes to %s', $written, $filePath),
            ];
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    protected function takeScreenshotTool(): array
    {
        $this->ensureSandboxDirectories();
        $directory = storage_path('app/screenshots');

        try {
            $fileName = 'screenshot-' . date('Ymd-His') . '-' . uniqid() . '.png';
            $fullPath = $directory . DIRECTORY_SEPARATOR . $fileName;
            $script = sprintf(
                '$folder = %s; New-Item -ItemType Directory -Force -Path $folder | Out-Null; $file = Join-Path $folder %s; Add-Type -AssemblyName System.Windows.Forms; Add-Type -AssemblyName System.Drawing; $bounds = [System.Windows.Forms.SystemInformation]::VirtualScreen; $bitmap = New-Object System.Drawing.Bitmap $bounds.Width, $bounds.Height; $graphics = [System.Drawing.Graphics]::FromImage($bitmap); $graphics.CopyFromScreen($bounds.Location, [System.Drawing.Point]::Empty, $bounds.Size); $bitmap.Save($file, [System.Drawing.Imaging.ImageFormat]::Png); $graphics.Dispose(); $bitmap.Dispose(); Write-Output $file;',
                var_export($directory, true),
                var_export($fileName, true)
            );

            $command = sprintf('powershell -NoProfile -ExecutionPolicy Bypass -Command %s', escapeshellarg($script));
            $output = shell_exec($command);
            if (!is_string($output) || trim($output) === '') {
                return ['success' => false, 'message' => 'Screenshot capture failed.'];
            }

            $outputPath = trim($output);
            if (!is_file($outputPath)) {
                return ['success' => false, 'message' => 'Screenshot file was not created.'];
            }

            return ['success' => true, 'path' => $outputPath, 'message' => 'Screenshot captured successfully.'];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Screenshot capture failed: ' . $e->getMessage()];
        }
    }

    protected function webSearchTool(string $query): array
    {
        return [
            'success' => false,
            'message' => 'Web search is not configured yet. Add an API key or provider and enable the search tool.',
        ];
    }

    protected function resolveYouTubeVideoUrl(string $query): ?string
    {
        $normalizedQuery = trim((string) $query);
        if ($normalizedQuery === '') {
            return null;
        }

        $directPattern = '/^(https?:\/\/)?(www\.)?(youtube\.com\/(watch\?v=|shorts\/|live\/)|youtu\.be\/)/i';
        if (preg_match($directPattern, $normalizedQuery) === 1) {
            return $normalizedQuery;
        }

        $searchUrl = 'https://www.youtube.com/results?search_query=' . rawurlencode($normalizedQuery);

        try {
            $response = $this->http->request('GET', $searchUrl, [
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
                    'Accept-Language' => 'en-US,en;q=0.9',
                ],
                'http_errors' => false,
                'timeout' => 15,
            ]);

            $html = (string) $response->getBody();
            if ($html === '') {
                return null;
            }

            $patterns = [
                '/(?:"|\')videoId(?:"|\')\s*:\s*(?:"|\')([A-Za-z0-9_-]{11})(?:"|\')/i',
                '/(?:v=|\/watch\?v=|\/shorts\/|\/live\/)([A-Za-z0-9_-]{11})/i',
                '/(?:data-video-id|video_id)(?:=|\":\s*\")([A-Za-z0-9_-]{11})/i',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $html, $matches) === 1) {
                    $videoId = $matches[1] ?? null;
                    if (is_string($videoId) && preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) === 1) {
                        return 'https://www.youtube.com/watch?v=' . $videoId;
                    }
                }
            }
        } catch (Throwable $e) {
            Log::warning('[YOUTUBE_VIDEO_RESOLVE_FAILED]', ['query' => $normalizedQuery, 'error' => $e->getMessage()]);
        }

        return null;
    }

    protected function toolYoutubeSearchAndPlay(string $query, string $mode = 'search'): array
    {
        $normalizedQuery = trim((string) $query);
        $normalizedMode = strtolower(trim((string) $mode));
        if ($normalizedMode === '') {
            $normalizedMode = 'search';
        }

        if (!in_array($normalizedMode, ['search', 'channel', 'play'], true)) {
            $normalizedMode = 'search';
        }

        if ($normalizedQuery === '') {
            $result = ['success' => false, 'message' => 'YouTube query is required.'];
            Log::info('[TOOL_CALL]', ['tool' => 'youtube_search_and_play', 'args' => ['query' => $query, 'mode' => $mode], 'result' => $result]);

            return $result;
        }

        try {
            $isDirectYouTubeUrl = preg_match('/^(https?:\/\/)?(www\.)?(youtube\.com\/(watch\?v=|shorts\/|live\/)|youtu\.be\/)/i', $normalizedQuery) === 1;

            if ($normalizedMode === 'channel') {
                $channelHandle = ltrim($normalizedQuery, '@');
                $channelHandle = trim($channelHandle);

                if ($channelHandle !== '' && !str_contains($channelHandle, ' ') && !str_contains(strtolower($channelHandle), 'youtube.com')) {
                    $url = 'https://www.youtube.com/@' . $channelHandle;
                } else {
                    $url = $isDirectYouTubeUrl ? $normalizedQuery : 'https://www.youtube.com/results?search_query=' . urlencode($normalizedQuery);
                }
            } else {
                $resolvedVideoUrl = $this->resolveYouTubeVideoUrl($normalizedQuery);
                if ($resolvedVideoUrl !== null && $normalizedMode !== 'search') {
                    $url = $resolvedVideoUrl;
                } elseif ($resolvedVideoUrl !== null) {
                    $url = $resolvedVideoUrl;
                } elseif ($isDirectYouTubeUrl) {
                    $url = $normalizedQuery;
                } else {
                    $url = 'https://www.youtube.com/results?search_query=' . urlencode($normalizedQuery);
                }
            }

            $command = sprintf('start "" "%s"', str_replace('"', '""', $url));
            $handle = @popen($command, 'r');
            if (is_resource($handle)) {
                fclose($handle);
            }

            $result = [
                'success' => true,
                'message' => sprintf('Opened YouTube for: %s', $normalizedQuery),
                'url' => $url,
            ];
        } catch (Throwable $e) {
            $result = ['success' => false, 'message' => 'Unable to open YouTube: ' . $e->getMessage()];
        }

        Log::info('[TOOL_CALL]', ['tool' => 'youtube_search_and_play', 'args' => ['query' => $query, 'mode' => $mode], 'result' => $result]);

        return $result;
    }

    protected function toolOpenVpn(): array
    {
        $vpnPath = trim((string) config('services.vpn.executable_path'));

        if ($vpnPath === '') {
            $result = ['success' => false, 'message' => 'VPN client path not configured. Set VPN_EXECUTABLE_PATH in .env'];
            Log::info('[TOOL_CALL]', ['tool' => 'open_vpn', 'args' => [], 'result' => $result]);

            return $result;
        }

        try {
            $command = sprintf('start "" "%s"', str_replace('"', '""', $vpnPath));
            $handle = @popen($command, 'r');
            if (is_resource($handle)) {
                fclose($handle);
            }

            $result = ['success' => true, 'message' => 'Opened VPN client.'];
        } catch (Throwable $e) {
            $result = ['success' => false, 'message' => 'Unable to open VPN client: ' . $e->getMessage()];
        }

        Log::info('[TOOL_CALL]', ['tool' => 'open_vpn', 'args' => [], 'result' => $result]);

        return $result;
    }

    public function executeTool(string $toolName, array $args = []): string
    {
        $toolName = trim((string) $toolName);
        $arguments = is_array($args) ? $args : [];

        $result = match ($toolName) {
            'open_application' => $this->openApplicationTool((string) ($arguments['app_name'] ?? '')),
            'read_file' => $this->readFileTool((string) ($arguments['file_path'] ?? '')),
            'write_file' => $this->writeFileTool((string) ($arguments['file_path'] ?? ''), (string) ($arguments['content'] ?? '')),
            'youtube_search_and_play' => $this->toolYoutubeSearchAndPlay((string) ($arguments['query'] ?? ''), (string) ($arguments['mode'] ?? 'search')),
            'open_vpn' => $this->toolOpenVpn(),
            'open_browser' => $this->openBrowserTool((string) ($arguments['browser'] ?? ''), (array) ($arguments['profiles'] ?? []), (string) ($arguments['url'] ?? '')),
            'take_screenshot' => $this->takeScreenshotTool(),
            'web_search' => $this->webSearchTool((string) ($arguments['query'] ?? '')),
            default => [
                'success' => false,
                'message' => sprintf('Tool "%s" is not supported.', $toolName),
            ],
        };

        Log::info('[TOOL_CALL]', [
            'tool' => $toolName,
            'args' => $arguments,
            'result' => $result,
        ]);

        return json_encode($result, JSON_THROW_ON_ERROR);
    }

    protected function chatWithProvider(string $provider, array $messages): string
    {
        if ($provider === 'groq') {
            if (blank(config('services.groq.key'))) {
                throw new RuntimeException('GROQ_API_KEY is not set.');
            }

            $model = config('services.groq.chat_model', 'openai/gpt-oss-20b');
            $maxIterations = 5;

            for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
                $response = $this->http->post('https://api.groq.com/openai/v1/chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . config('services.groq.key'),
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'model' => $model,
                        'messages' => $messages,
                        'temperature' => 0.7,
                        'tools' => $this->getAvailableTools(),
                    ],
                ]);

                $data = json_decode((string) $response->getBody(), true);
                $assistantMessage = $data['choices'][0]['message'] ?? [];
                $toolCalls = $assistantMessage['tool_calls'] ?? [];

                if (empty($toolCalls)) {
                    return $assistantMessage['content'] ?? '';
                }

                $messages[] = $assistantMessage;

                foreach ($toolCalls as $toolCall) {
                    $function = $toolCall['function'] ?? [];
                    $toolName = $function['name'] ?? '';
                    $toolArgs = $this->normalizeToolArguments($function['arguments'] ?? '{}');
                    $toolResult = $this->executeTool($toolName, $toolArgs);

                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall['id'] ?? uniqid('toolcall-', true),
                        'name' => $toolName,
                        'content' => $toolResult,
                    ];
                }
            }

            return 'The agent is still working through the requested action. Please try again in a moment.';
        }

        if ($provider === 'gemini') {
            if (blank(config('services.gemini.key'))) {
                throw new RuntimeException('GEMINI_API_KEY is not set.');
            }

            $systemPrompt = '';
            $contents = [];
            foreach ($messages as $message) {
                if (($message['role'] ?? '') === 'system') {
                    $systemPrompt = $message['content'];
                    continue;
                }

                $contents[] = [
                    'role' => (($message['role'] ?? 'user') === 'assistant') ? 'model' : 'user',
                    'parts' => [
                        [
                            'text' => $message['content'],
                        ]
                    ],
                ];
            }

            $payload = ['contents' => $contents];
            if ($systemPrompt !== '') {
                $payload['systemInstruction'] = [
                    'parts' => [
                        [
                            'text' => $systemPrompt,
                        ]
                    ],
                ];
            }

            $model = config('services.gemini.model', 'gemini-2.5-flash');
            $url = sprintf('https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s', $model, config('services.gemini.key'));

            $response = $this->http->post($url, [
                'headers' => ['Content-Type' => 'application/json'],
                'json' => $payload,
            ]);

            $data = json_decode((string) $response->getBody(), true);
            $parts = $data['candidates'][0]['content']['parts'] ?? [];
            $text = '';
            foreach ($parts as $part) {
                $text .= ($part['text'] ?? '');
            }

            return trim($text);
        }

        if ($provider === 'openrouter') {
            if (blank(config('services.openrouter.key'))) {
                throw new RuntimeException('OPENROUTER_API_KEY is not set.');
            }

            $model = config('services.openrouter.chat_model', 'openai/gpt-oss-20b:free');

            $response = $this->http->post('https://openrouter.ai/api/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . config('services.openrouter.key'),
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => config('app.url', 'http://localhost:8000'),
                    'X-Title' => 'Voice AI Agent',
                ],
                'json' => [
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => 0.7,
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);

            return $data['choices'][0]['message']['content'] ?? '';
        }

        throw new RuntimeException(sprintf('Unsupported chat provider: %s', $provider));
    }

    public function chat(array $messages): string
    {
        $providers = ['groq', 'gemini', 'openrouter'];
        $lastError = null;

        foreach ($providers as $index => $provider) {
            try {
                return $this->chatWithProvider($provider, $messages);
            } catch (Throwable $e) {
                $lastError = $e;
                $next = $providers[$index + 1] ?? null;
                if ($next === null || !$this->shouldRetryWithFallback($e)) {
                    throw $e;
                }

                $this->logFallback($this->providerLabel($provider), $this->providerLabel($next), $e);
            }
        }

        throw $lastError instanceof Throwable ? $lastError : new RuntimeException('All chat providers failed.');
    }

    protected function transcribeWithProvider(string $provider, UploadedFile $audio): string
    {
        if ($provider === 'groq') {
            if (blank(config('services.groq.key'))) {
                throw new RuntimeException('GROQ_API_KEY is not set.');
            }

            $response = $this->http->post('https://api.groq.com/openai/v1/audio/transcriptions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . config('services.groq.key'),
                ],
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => fopen($audio->getRealPath(), 'r'),
                        'filename' => $audio->getClientOriginalName() ?: 'audio.webm',
                    ],
                    [
                        'name' => 'model',
                        'contents' => config('services.groq.stt_model', 'whisper-large-v3'),
                    ],
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);

            return $data['text'] ?? '';
        }

        if ($provider === 'local-whisper') {
            $binary = $this->resolveWhisperBinary();
            if ($binary === null) {
                throw new RuntimeException('Local whisper fallback is not installed.');
            }

            $input = $audio->getRealPath();
            $dir = sys_get_temp_dir() . '/voice-ai-' . uniqid();
            mkdir($dir, 0777, true);
            $outputFile = $dir . '/transcript.txt';
            $cmd = sprintf('"%s" "%s" --output_dir "%s" --output_format txt --language en > NUL 2>&1', $binary, $input, $dir);
            exec($cmd, $output, $code);

            if ($code !== 0 || !file_exists($outputFile)) {
                throw new RuntimeException('Local whisper fallback failed.');
            }

            $text = trim((string) file_get_contents($outputFile));
            $text = preg_replace('/\s+/', ' ', $text);

            return trim((string) $text);
        }

        throw new RuntimeException(sprintf('Unsupported STT provider: %s', $provider));
    }

    public function transcribe(UploadedFile $audio): string
    {
        $providers = ['groq', 'local-whisper'];
        $lastError = null;

        foreach ($providers as $index => $provider) {
            try {
                return $this->transcribeWithProvider($provider, $audio);
            } catch (Throwable $e) {
                $lastError = $e;
                $next = $providers[$index + 1] ?? null;
                if ($next === null || !$this->shouldRetryWithFallback($e)) {
                    throw $e;
                }

                $this->logFallback($this->providerLabel($provider), $this->providerLabel($next), $e);
            }
        }

        throw $lastError instanceof Throwable ? $lastError : new RuntimeException('Speech transcription failed.');
    }

    /**
     * Write $text to a temp file and return its path.
     * Using a file instead of embedding text in the shell command
     * avoids all quoting/escaping issues (apostrophes, &, %, |, etc.)
     * on both Windows cmd.exe and Unix shells.
     */
    protected function writeTempTextFile(string $text): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('voice-ai-text-', true) . '.txt';
        file_put_contents($path, $text);

        return $path;
    }

    protected function speakWithProvider(string $provider, string $text): string
    {
        if ($provider === 'edge-tts') {
            $python = $this->resolvePythonBinary();
            if ($python === null) {
                throw new RuntimeException('Python is not available for Edge TTS fallback.');
            }

            $uid = uniqid('voice-ai-tts-', true);
            $textFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $uid . '.txt';
            $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $uid . '.mp3';
            $errFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $uid . '.err.log';

            file_put_contents($textFile, $text);

            $command = sprintf(
                '%s -m edge_tts --file %s --voice en-US-JennyNeural --write-media %s 1> NUL 2> %s',
                escapeshellarg($python),
                escapeshellarg($textFile),
                escapeshellarg($tempFile),
                escapeshellarg($errFile)
            );

            exec($command, $output, $code);

            $stderr = is_file($errFile) ? trim((string) file_get_contents($errFile)) : '';

            @unlink($textFile);
            @unlink($errFile);

            if ($code !== 0 || !file_exists($tempFile)) {
                $detail = $stderr !== '' ? $stderr : 'no stderr captured (exit code ' . $code . ')';
                throw new RuntimeException(sprintf('Edge TTS fallback failed: %s', $detail));
            }

            $audio = (string) file_get_contents($tempFile);
            @unlink($tempFile);

            return $audio;
        }

        if ($provider === 'gtts') {
            $python = $this->resolvePythonBinary();
            if ($python === null) {
                throw new RuntimeException('Python is not available for gTTS fallback.');
            }

            $uid = uniqid('voice-ai-gtts-', true);
            $textFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $uid . '.txt';
            $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $uid . '.mp3';
            $errFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $uid . '.err.log';
            $scriptFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $uid . '.py';

            file_put_contents($textFile, $text);

            // Python script reads text from a file and writes audio to another file.
            // var_export() gives us safe, correctly-quoted Python string literals
            // regardless of what characters are in the file paths.
            $script = sprintf(
                "from gtts import gTTS\n"
                . "with open(%s, 'r', encoding='utf-8') as f:\n"
                . "    text = f.read()\n"
                . "gTTS(text=text, lang='en').save(%s)\n",
                var_export($textFile, true),
                var_export($tempFile, true)
            );
            file_put_contents($scriptFile, $script);

            $command = sprintf(
                '%s %s 1> NUL 2> %s',
                escapeshellarg($python),
                escapeshellarg($scriptFile),
                escapeshellarg($errFile)
            );

            exec($command, $output, $code);

            $stderr = is_file($errFile) ? trim((string) file_get_contents($errFile)) : '';

            @unlink($textFile);
            @unlink($scriptFile);
            @unlink($errFile);

            if ($code !== 0 || !file_exists($tempFile)) {
                $detail = $stderr !== '' ? $stderr : 'no stderr captured (exit code ' . $code . ')';
                throw new RuntimeException(sprintf('gTTS fallback failed: %s', $detail));
            }

            $audio = (string) file_get_contents($tempFile);
            @unlink($tempFile);

            return $audio;
        }

        throw new RuntimeException(sprintf('Unsupported TTS provider: %s', $provider));
    }

    public function speak(string $text): string
    {
        $providers = ['edge-tts', 'gtts'];
        $lastError = null;

        foreach ($providers as $index => $provider) {
            try {
                return $this->speakWithProvider($provider, $text);
            } catch (Throwable $e) {
                $lastError = $e;
                $next = $providers[$index + 1] ?? null;
                if ($next === null || !$this->shouldRetryWithFallback($e)) {
                    throw $e;
                }

                $this->logFallback($this->providerLabel($provider), $this->providerLabel($next), $e);
            }
        }

        throw $lastError instanceof Throwable ? $lastError : new RuntimeException('Speech synthesis failed.');
    }
}