<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class OpenAIService
{
    protected Client $http;

    public function __construct()
    {
        if (blank(config('services.openai.key'))) {
            throw new RuntimeException('OPENAI_API_KEY is not set. Add it to your .env file.');
        }

        $this->http = new Client([
            'base_uri' => 'https://api.openai.com/v1/',
            'headers' => [
                'Authorization' => 'Bearer '.config('services.openai.key'),
            ],
            'timeout' => 60,
        ]);
    }

    /**
     * Transcribe an audio file to text using Whisper.
     */
    public function transcribe(UploadedFile $audio): string
    {
        $response = $this->http->post('audio/transcriptions', [
            'multipart' => [
                [
                    'name' => 'file',
                    'contents' => fopen($audio->getRealPath(), 'r'),
                    'filename' => $audio->getClientOriginalName() ?: 'audio.webm',
                ],
                [
                    'name' => 'model',
                    'contents' => config('services.openai.stt_model'),
                ],
            ],
        ]);

        $data = json_decode((string) $response->getBody(), true);

        return $data['text'] ?? '';
    }

    /**
     * Send the conversation to the chat model and get a reply.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function chat(array $messages): string
    {
        $response = $this->http->post('chat/completions', [
            'json' => [
                'model' => config('services.openai.chat_model'),
                'messages' => $messages,
                'temperature' => 0.7,
            ],
        ]);

        $data = json_decode((string) $response->getBody(), true);

        return $data['choices'][0]['message']['content'] ?? '';
    }

    /**
     * Convert text to spoken audio (mp3 bytes) using TTS.
     */
    public function speak(string $text): string
    {
        $response = $this->http->post('audio/speech', [
            'json' => [
                'model' => config('services.openai.tts_model'),
                'voice' => config('services.openai.tts_voice'),
                'input' => $text,
                'format' => 'mp3',
            ],
        ]);

        return (string) $response->getBody();
    }
}
