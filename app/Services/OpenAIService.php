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

    protected function chatWithProvider(string $provider, array $messages): string
    {
        if ($provider === 'groq') {
            if (blank(config('services.groq.key'))) {
                throw new RuntimeException('GROQ_API_KEY is not set.');
            }

            $model = config('services.groq.chat_model', 'openai/gpt-oss-20b');

            $response = $this->http->post('https://api.groq.com/openai/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . config('services.groq.key'),
                    'Content-Type' => 'application/json',
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