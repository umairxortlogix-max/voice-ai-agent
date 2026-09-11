<?php

namespace Tests\Unit;

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
        $service = new OpenAIService();

        $this->assertTrue($service->shouldRetryWithFallback(new RuntimeException('429 Too Many Requests')));
        $this->assertTrue($service->shouldRetryWithFallback(new RuntimeException('quota exceeded for this model')));
        $this->assertTrue($service->shouldRetryWithFallback(new RuntimeException('timed out after 5 seconds')));
        $this->assertTrue($service->shouldRetryWithFallback(new RuntimeException('The model openai/gpt-oss-20b has been decommissioned and is no longer supported')));
        $this->assertTrue($service->shouldRetryWithFallback(new RuntimeException('The model openai/gpt-oss-120b does not exist or you do not have access to it')));
        $this->assertFalse($service->shouldRetryWithFallback(new RuntimeException('missing required parameter')));
    }

    public function test_it_exposes_tool_schema_and_blocks_path_traversal(): void
    {
        $service = new OpenAIService();

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

    public function test_it_opens_direct_youtube_video_urls_instead_of_searching_for_them(): void
    {
        $service = new OpenAIService();

        $result = json_decode($service->executeTool('youtube_search_and_play', [
            'query' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'mode' => 'search',
        ]), true);

        $this->assertTrue($result['success']);
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $result['url']);
    }

    public function test_it_tries_to_resolve_a_song_query_to_an_actual_youtube_video(): void
    {
        $service = new OpenAIService();

        $result = json_decode($service->executeTool('youtube_search_and_play', [
            'query' => 'Pardesiya ya',
            'mode' => 'search',
        ]), true);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('/watch?v=', $result['url']);
    }

    public function test_it_instructs_the_voice_agent_to_use_tools_for_actions(): void
    {
        $controller = new \App\Http\Controllers\VoiceAgentController();
        $property = new \ReflectionProperty($controller, 'systemPrompt');
        $property->setAccessible(true);
        $prompt = (string) $property->getValue($controller);

        $this->assertStringContainsString('tool', strtolower($prompt));
        $this->assertStringContainsString('open', strtolower($prompt));
        $this->assertStringContainsString('play', strtolower($prompt));
        $this->assertStringContainsString('kholo', strtolower($prompt));
        $this->assertStringContainsString('play karo', strtolower($prompt));
    }
}
