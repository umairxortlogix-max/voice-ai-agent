<?php

namespace Tests\Unit;

use App\Services\OpenAIService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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
}
