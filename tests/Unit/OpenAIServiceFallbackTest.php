<?php

namespace Tests\Unit;

use App\Http\Controllers\VoiceAgentController;
use App\Services\OpenAIService;
use RuntimeException;
use Tests\TestCase;

class OpenAIServiceFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['GROQ_API_KEY'] = 'test-groq-key';
        $_ENV['GEMINI_API_KEY'] = 'test-gemini-key';
        $_ENV['OPENROUTER_API_KEY'] = 'test-openrouter-key';
        putenv('GROQ_API_KEY=test-groq-key');
        putenv('GEMINI_API_KEY=test-gemini-key');
        putenv('OPENROUTER_API_KEY=test-openrouter-key');
    }

    public function test_it_identifies_retryable_provider_failures(): void
    {
        $service = new OpenAIService;

        $this->assertTrue($service->shouldRetryWithFallback(new RuntimeException('429 Too Many Requests')));
        $this->assertTrue($service->shouldRetryWithFallback(new RuntimeException('quota exceeded for this model')));
        $this->assertTrue($service->shouldRetryWithFallback(new RuntimeException('timed out after 5 seconds')));
        $this->assertTrue($service->shouldRetryWithFallback(new RuntimeException('The model openai/gpt-oss-20b has been decommissioned and is no longer supported')));
        $this->assertTrue($service->shouldRetryWithFallback(new RuntimeException('The model openai/gpt-oss-120b does not exist or you do not have access to it')));
        $this->assertFalse($service->shouldRetryWithFallback(new RuntimeException('missing required parameter')));
    }

    public function test_it_exposes_tool_schema_and_blocks_path_traversal(): void
    {
        $service = new OpenAIService;

        $tools = $service->getAvailableTools();

        $this->assertIsArray($tools);
        $this->assertNotEmpty($tools);
        $this->assertSame('open_application', $tools[0]['function']['name']);

        $screenshotTool = collect($tools)->firstWhere('function.name', 'take_screenshot');
        $this->assertNotNull($screenshotTool);
        $this->assertEquals('{}', json_encode($screenshotTool['function']['parameters']['properties']));

        $blocked = json_decode($service->executeTool('read_file', ['file_path' => '../outside.txt']), true);
        $this->assertFalse($blocked['success']);
        $this->assertStringContainsString('sandbox', strtolower((string) $blocked['message']));

        $webSearch = json_decode($service->executeTool('web_search', ['query' => 'laravel news']), true);
        $this->assertFalse($webSearch['success']);
        $this->assertStringContainsString('not configured', strtolower((string) $webSearch['message']));
    }

    public function test_it_registers_system_tools_with_required_parameter_arrays(): void
    {
        $service = new OpenAIService;
        $tools = collect($service->getAvailableTools())->keyBy('function.name');

        foreach ([
            'set_volume', 'toggle_mute', 'set_brightness', 'toggle_wifi', 'toggle_bluetooth',
            'system_power_action', 'open_settings_page', 'get_clipboard', 'set_clipboard',
            'list_running_processes', 'kill_process', 'find_and_open_file', 'open_folder',
        ] as $toolName) {
            $this->assertArrayHasKey($toolName, $tools->all());
            $this->assertArrayHasKey('required', $tools[$toolName]['function']['parameters']);
        }
    }

    public function test_risky_tools_require_confirmation_and_protected_processes_are_blocked(): void
    {
        $service = new OpenAIService;

        $pending = json_decode($service->executeTool('system_power_action', ['action' => 'shutdown']), true);
        $this->assertFalse($pending['success']);
        $this->assertTrue($pending['requires_confirmation']);
        $this->assertSame('shutdown', $pending['pending_action']);
        $this->assertSame(['action' => 'shutdown'], $pending['pending_args']);

        $blocked = json_decode($service->executeTool('kill_process', ['process_name' => 'explorer.exe']), true);
        $this->assertFalse($blocked['success']);
        $this->assertStringContainsString('protected', strtolower((string) $blocked['message']));
    }

    public function test_it_recovers_a_pending_sleep_action_from_conversation_history(): void
    {
        $service = new OpenAIService;
        $pending = json_decode($service->executeTool('system_power_action', ['action' => 'sleep']), true);

        $history = [
            [
                'role' => 'tool',
                'name' => 'system_power_action',
                'content' => json_encode($pending, JSON_THROW_ON_ERROR),
            ],
            ['role' => 'user', 'content' => 'yes'],
        ];

        $method = new \ReflectionMethod($service, 'findPendingConfirmationInHistory');
        $method->setAccessible(true);
        $found = $method->invoke($service, $history);

        $this->assertSame('system_power_action', $found['tool']);
        $this->assertSame(['action' => 'sleep'], $found['args']);
    }

    public function test_it_accepts_flexible_affirmative_confirmation_replies(): void
    {
        $service = new OpenAIService;
        $method = new \ReflectionMethod($service, 'isAffirmativeReply');
        $method->setAccessible(true);

        foreach (['yes', 'Yes.', 'YES', 'yes please', 'haan'] as $reply) {
            $this->assertTrue($method->invoke($service, $reply), $reply.' should be affirmative');
        }
    }

    public function test_it_opens_direct_youtube_video_urls_instead_of_searching_for_them(): void
    {
        $service = new OpenAIService;

        $result = json_decode($service->executeTool('youtube_search_and_play', [
            'query' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'mode' => 'search',
        ]), true);

        $this->assertTrue($result['success']);
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $result['url']);
    }

    public function test_it_tries_to_resolve_a_song_query_to_an_actual_youtube_video(): void
    {
        $service = new OpenAIService;

        $result = json_decode($service->executeTool('youtube_search_and_play', [
            'query' => 'Pardesiya ya',
            'mode' => 'search',
        ]), true);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('/watch?v=', $result['url']);
    }

    public function test_it_instructs_the_voice_agent_to_use_tools_for_actions(): void
    {
        $controller = new VoiceAgentController;
        $property = new \ReflectionProperty($controller, 'systemPrompt');
        $property->setAccessible(true);
        $prompt = (string) $property->getValue($controller);

        $this->assertStringContainsString('tool', strtolower($prompt));
        $this->assertStringContainsString('open', strtolower($prompt));
        $this->assertStringContainsString('play', strtolower($prompt));
        $this->assertStringContainsString('kholo', strtolower($prompt));
        $this->assertStringContainsString('play karo', strtolower($prompt));
        $this->assertStringContainsString('success: false', strtolower($prompt));
        $this->assertStringContainsString('administrator privileges', strtolower($prompt));
        $this->assertStringContainsString('safety restriction', strtolower($prompt));
        $this->assertStringContainsString("i'm sorry, i can't do that", strtolower($prompt));
    }

    public function test_it_handles_find_and_open_file_tool(): void
    {
        $service = new OpenAIService;

        $empty = json_decode($service->executeTool('find_and_open_file', ['file_name' => '']), true);
        $this->assertFalse($empty['success']);

        $notFound = json_decode($service->executeTool('find_and_open_file', ['file_name' => 'non_existent_random_file_xyz_123.fake']), true);
        $this->assertFalse($notFound['success']);
        $this->assertStringContainsString('could not find', strtolower((string) $notFound['message']));
    }

    public function test_it_handles_open_folder_tool(): void
    {
        $service = new OpenAIService;

        $empty = json_decode($service->executeTool('open_folder', ['folder_name' => '']), true);
        $this->assertFalse($empty['success']);

        $notFound = json_decode($service->executeTool('open_folder', ['folder_name' => 'non_existent_folder_xyz_999']), true);
        $this->assertFalse($notFound['success']);
        $this->assertStringContainsString('could not find', strtolower((string) $notFound['message']));
    }
}
