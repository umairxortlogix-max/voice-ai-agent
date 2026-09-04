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
            Log::error('Voice agent error: '.$e->getMessage());

            return response()->json([
                'error' => 'The voice agent hit a snag. Check your API key and try again.',
            ], 500);
        }
    }
}
