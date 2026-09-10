<?php

namespace Tests\Feature;

use App\Http\Controllers\VoiceAgentController;
use App\Services\OpenAIService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class VoiceAgentErrorHandlingTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_explains_invalid_api_key_errors(): void
    {
        $service = Mockery::mock(OpenAIService::class);
        $service->shouldReceive('transcribe')->once()->andThrow(new RuntimeException('Incorrect API key provided'));

        $controller = new VoiceAgentController();
        $request = new Request();
        $request->files->add([
            'audio' => UploadedFile::fake()->create('voice.wav', 1, 'audio/wav'),
        ]);

        $response = $controller->converse($request, $service);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('API key', $response->getData()->error);
    }

    public function test_it_explains_credit_exhaustion_errors(): void
    {
        $service = Mockery::mock(OpenAIService::class);
        $service->shouldReceive('transcribe')->once()->andThrow(new RuntimeException('You have no credits remaining. Add credits to continue using the API.'));

        $controller = new VoiceAgentController();
        $request = new Request();
        $request->files->add([
            'audio' => UploadedFile::fake()->create('voice.wav', 1, 'audio/wav'),
        ]);

        $response = $controller->converse($request, $service);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertStringContainsString('credits', strtolower($response->getData()->error));
    }
}
