<?php

namespace App\Http\Controllers;

use App\Services\OpenAIService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class VoiceAgentController extends Controller
{
    protected string $systemPrompt = <<<'PROMPT'
        You are a helpful, warm, concise voice assistant. Keep replies short and
        natural for speech (1-3 sentences unless the user asks for more detail).
        Avoid lists, markdown, or symbols since your reply will be spoken aloud.
        PROMPT;

    /**
     * Full turn: audio in -> transcript -> LLM reply -> spoken audio out.
     */
    public function converse(Request $request, OpenAIService $ai): JsonResponse
    {
        $request->validate([
            'audio' => ['required', 'file'],
            'history' => ['nullable', 'string'],
        ]);

        try {
            // 1) Speech to text
            $transcript = $ai->transcribe($request->file('audio'));

            if (trim($transcript) === '') {
                return response()->json([
                    'error' => "Couldn't hear anything. Try again a little closer to the mic.",
                ], 422);
            }

            // 2) Build conversation and get a reply from the model
            $history = json_decode($request->input('history', '[]'), true) ?: [];
            $messages = array_merge(
                [['role' => 'system', 'content' => $this->systemPrompt]],
                $history,
                [['role' => 'user', 'content' => $transcript]],
            );

            $reply = $ai->chat($messages);

            // 3) Text to speech
            $audio = $ai->speak($reply);

            return response()->json([
                'transcript' => $transcript,
                'reply' => $reply,
                'audio_base64' => base64_encode($audio),
            ]);
        } catch (Throwable $e) {
            $errorDetails = $e->getMessage();
            if (method_exists($e, 'getResponse') && $e->getResponse() !== null) {
                $response = $e->getResponse();
                $body = $response->getBody();

                if (is_object($body) && method_exists($body, 'getContents')) {
                    $body->seek(0);
                    $rawBody = $body->getContents();
                    $body->seek(0);

                    if (is_string($rawBody) && trim($rawBody) !== '') {
                        $errorDetails .= ' | '.$rawBody;
                    }
                }
            }

            Log::error('Voice agent error: '.$errorDetails, ['exception' => $e]);

            $message = strtolower($e->getMessage());

            if (str_contains($message, 'api key') || str_contains($message, 'unauthorized') || str_contains($message, 'authentication')) {
                return response()->json([
                    'error' => 'Your AI API key is invalid or missing. Please update the provider key and try again.',
                ], 401);
            }

            if (str_contains($message, 'no credits remaining') || str_contains($message, 'quota') || str_contains($message, 'credit')) {
                return response()->json([
                    'error' => 'The AI provider has no remaining credits. Add credits or switch to a different free-tier provider.',
                ], 429);
            }

            if (str_contains($message, '429') || str_contains($message, 'rate limit') || str_contains($message, 'too many requests')) {
                return response()->json([
                    'error' => 'The AI provider is rate-limited right now. Please retry in a moment or switch providers.',
                ], 429);
            }

            if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
                return response()->json([
                    'error' => 'The AI provider timed out. Please try again in a moment.',
                ], 504);
            }

            if (str_contains($message, 'not set') || str_contains($message, 'missing')) {
                return response()->json([
                    'error' => 'A required API key is not configured. Please set the provider key in your environment.',
                ], 500);
            }

            return response()->json([
                'error' => 'The voice agent is temporarily unavailable. Please try again shortly.',
            ], 500);
        }
    }
}
