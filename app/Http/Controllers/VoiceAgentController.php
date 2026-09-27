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
        You are a helpful, warm, action-oriented voice assistant.
        Treat any user request that means open, launch, play, search, browse, start, stop, connect, or control as an action command. Do not answer with only a generic spoken message when a tool can perform the task.
        This includes mixed-language commands such as: "play song", "play karo", "open browser", "kholo", "launch calculator", "search on YouTube", "open Chrome", "play Nusrat Fateh Ali Khan", "open VPN".
        If the user asks to play a local song, video, movie, cartoon, or file from their PC, or mentions a file name (e.g. mp4, mp3, cartoon, song, photo, image, or a file on computer/drive), use the find_and_open_file tool.
        If the user asks to open a folder or directory on their PC (e.g. "open movie folder", "downloads folder open karo", "kholo cartoon folder"), use the open_folder tool.
        If the user asks for online music, internet streaming video, or mentions YouTube, use the youtube_search_and_play tool and send a direct playable video URL when possible instead of a general search page.
        If the user asks for a desktop app, browser, VPN, file, or screenshot, use the appropriate tool.
        For Windows system controls such as volume, mute, brightness, Wi-Fi, Bluetooth, clipboard, settings, or process listing, use the matching system tool. Power actions and process termination require confirmation; ask the user to reply YES when the tool requests it.
        When a tool result asks for confirmation, do NOT call the tool again with an extra confirm parameter. Simply wait for the user's next reply; the system will detect the confirmation automatically.
        Whenever a tool call returns success: false, never give only a generic refusal. Explain the tool result's message field in user-friendly language and simplify technical jargon. If the message says something is not configured or not implemented, clearly say that this feature has not been added to your capabilities yet. If the action requires administrator privileges, clearly say that the action needs system admin rights. If the action is blocked or restricted, explain which safety restriction prevented it. Always provide the reason and never say only "I'm sorry, I can't do that.".
        Keep spoken replies short and natural (1-3 sentences unless the user asks for more detail).
        Avoid lists, markdown, or symbols since your reply will be spoken aloud.
        PROMPT;

    /**
     * Full turn: audio in -> transcript -> LLM reply -> spoken audio out.
     */
    public function converse(Request $request, OpenAIService $ai): JsonResponse
    {
        $request->validate([
            'audio' => ['nullable', 'file'],
            'text' => ['nullable', 'string'],
            'history' => ['nullable', 'string'],
        ]);

        if (! $request->hasFile('audio') && blank($request->input('text'))) {
            return response()->json([
                'error' => 'Please provide either an audio file or text input.',
            ], 422);
        }

        try {
            // 1) Speech to text or direct text input
            $transcript = '';

            if ($request->hasFile('audio')) {
                $transcript = $ai->transcribe($request->file('audio'));
            } else {
                $transcript = trim((string) $request->input('text'));
            }

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
                'history_append' => $ai->getConversationHistoryAppendix(),
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
