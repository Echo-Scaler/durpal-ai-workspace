<?php

namespace Drupal\Tests\ai_provider_openai\Unit\Plugin\AiProvider;

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai_provider_openai\Plugin\AiProvider\OpenAiProvider;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OpenAI\Testing\Responses\Fixtures\Responses\CreateResponseFixture;
use PHPUnit\Framework\TestCase;

/**
 * Tests that OpenAiProvider::chat() correctly builds payloads for remote files.
 *
 * Covers the remote file handling added by MR #53, mapped to the Responses
 * API content part shape:
 * @code
 *   foreach ($remote_files as $remote_file_id) {
 *     $content[] = ['type' => 'input_file', 'file_id' => $remote_file_id];
 *   }
 * @endcode
 *
 * @group ai_provider_openai
 * @covers \Drupal\ai_provider_openai\Plugin\AiProvider\OpenAiProvider
 */
class OpenAiProviderChatRemoteFilesTest extends TestCase {

  /**
   * Guzzle history container — each entry holds 'request' and 'response'.
   */
  private array $history = [];

  /**
   * Partial mock of the provider.
   *
   * @var \Drupal\ai_provider_openai\Plugin\AiProvider\OpenAiProvider&\PHPUnit\Framework\MockObject\MockObject
   */
  private OpenAiProvider $provider;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->history = [];

    // Build a real \OpenAI\Client backed by a Guzzle MockHandler so we satisfy
    // the `getClient(): Client` return type while intercepting HTTP traffic.
    $mock = new MockHandler([
      // The provider's chat() makes one POST /v1/responses request.
      new Response(200, ['Content-Type' => 'application/json'], json_encode(CreateResponseFixture::ATTRIBUTES)),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($this->history));
    $guzzle = new GuzzleClient(['handler' => $stack]);
    $client = \OpenAI::factory()->withApiKey('test-key')->withHttpClient($guzzle)->make();

    // Create a partial mock: bypass the plugin constructor, stub the two
    // methods that would need a live container or API key.
    $this->provider = $this->getMockBuilder(OpenAiProvider::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['loadClient', 'moderationEndpoints'])
      ->getMock();

    $this->provider->method('loadClient');
    $this->provider->method('moderationEndpoints');

    $ref = new \ReflectionClass(OpenAiProvider::class);

    $ref->getProperty('client')->setValue($this->provider, $client);
    $ref->getProperty('streamed')->setValue($this->provider, FALSE);
    $ref->getProperty('configuration')->setValue($this->provider, []);
    $ref->getProperty('chatSystemRole')->setValue($this->provider, '');
    $ref->getProperty('moderation')->setValue($this->provider, NULL);
  }

  /**
   * Returns the decoded JSON body of the last captured HTTP request.
   */
  private function lastRequestBody(): array {
    $body = (string) $this->history[0]['request']->getBody();
    return json_decode($body, TRUE);
  }

  /**
   * Re-queues a fresh mock response so individual tests can call chat() once.
   */
  private function queueChatResponse(): void {
    // Re-inject a fresh client with a new response queued for this test.
    $mock = new MockHandler([
      new Response(200, ['Content-Type' => 'application/json'], json_encode(CreateResponseFixture::ATTRIBUTES)),
    ]);
    $this->history = [];
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($this->history));
    $guzzle = new GuzzleClient(['handler' => $stack]);
    $client = \OpenAI::factory()->withApiKey('test-key')->withHttpClient($guzzle)->make();
    (new \ReflectionClass(OpenAiProvider::class))->getProperty('client')->setValue($this->provider, $client);
  }

  /**
   * Tests that a single remote file ID appears in the API request payload.
   */
  public function testSingleRemoteFileIsAddedToPayload(): void {
    $this->queueChatResponse();

    $message = new ChatMessage('user', 'Please summarize the attached document.');
    $message->addRemoteFile('file-abc123');

    $this->provider->chat(new ChatInput([$message]), 'gpt-4o');

    $payload = $this->lastRequestBody();
    $content = $payload['input'][0]['content'];

    $fileEntries = array_values(array_filter($content, fn($c) => ($c['type'] ?? '') === 'input_file' && isset($c['file_id'])));
    $this->assertCount(1, $fileEntries);
    $this->assertEquals('input_file', $fileEntries[0]['type']);
    $this->assertEquals('file-abc123', $fileEntries[0]['file_id']);
  }

  /**
   * Tests that multiple remote file IDs all appear in the API request payload.
   */
  public function testMultipleRemoteFilesAreAllAddedToPayload(): void {
    $this->queueChatResponse();

    $message = new ChatMessage('user', 'Compare these two files.');
    $message->addRemoteFile('file-aaa111');
    $message->addRemoteFile('file-bbb222');

    $this->provider->chat(new ChatInput([$message]), 'gpt-4o');

    $content = $this->lastRequestBody()['input'][0]['content'];
    $fileEntries = array_values(array_filter($content, fn($c) => ($c['type'] ?? '') === 'input_file' && isset($c['file_id'])));

    $this->assertCount(2, $fileEntries);
    $this->assertEquals('file-aaa111', $fileEntries[0]['file_id']);
    $this->assertEquals('file-bbb222', $fileEntries[1]['file_id']);
  }

  /**
   * Tests that the text content is preserved alongside remote files.
   */
  public function testTextIsPreservedAlongsideRemoteFiles(): void {
    $this->queueChatResponse();

    $message = new ChatMessage('user', 'Explain this document.');
    $message->addRemoteFile('file-xyz789');

    $this->provider->chat(new ChatInput([$message]), 'gpt-4o');

    $content = $this->lastRequestBody()['input'][0]['content'];
    $textEntries = array_values(array_filter($content, fn($c) => ($c['type'] ?? '') === 'input_text'));

    $this->assertCount(1, $textEntries);
    $this->assertEquals('Explain this document.', $textEntries[0]['text']);
  }

  /**
   * Tests that a message without remote files does not produce file entries.
   */
  public function testMessageWithoutRemoteFilesHasNoFileEntries(): void {
    $this->queueChatResponse();

    $message = new ChatMessage('user', 'Hello, world!');
    $this->provider->chat(new ChatInput([$message]), 'gpt-4o');

    $content = $this->lastRequestBody()['input'][0]['content'];

    // Content may be a plain string or an array; either way no file entries.
    if (is_array($content)) {
      $fileEntries = array_filter($content, fn($c) => ($c['type'] ?? '') === 'input_file');
      $this->assertCount(0, $fileEntries);
    }
    else {
      $this->assertIsString($content);
    }
  }

  /**
   * Tests that the model ID is correctly forwarded in the API request.
   */
  public function testModelIdIsPassedInPayload(): void {
    $this->queueChatResponse();

    $message = new ChatMessage('user', 'Test.');
    $message->addRemoteFile('file-model-test');

    $this->provider->chat(new ChatInput([$message]), 'gpt-4o-mini');

    $this->assertEquals('gpt-4o-mini', $this->lastRequestBody()['model']);
  }

  /**
   * Tests remote files on the first of multiple messages; second has none.
   */
  public function testRemoteFilesOnFirstMessageOnly(): void {
    $this->queueChatResponse();

    $msg1 = new ChatMessage('user', 'First question about a file.');
    $msg1->addRemoteFile('file-first');

    $msg2 = new ChatMessage('user', 'Second question, no file.');

    $this->provider->chat(new ChatInput([$msg1, $msg2]), 'gpt-4o');

    $messages = $this->lastRequestBody()['input'];
    $this->assertCount(2, $messages);

    // First message must contain a file entry.
    $fileEntries = array_filter($messages[0]['content'], fn($c) => ($c['type'] ?? '') === 'input_file');
    $this->assertCount(1, $fileEntries);

    // Second message must contain no file entries.
    $content1 = $messages[1]['content'];
    if (is_array($content1)) {
      $fileEntries2 = array_filter($content1, fn($c) => ($c['type'] ?? '') === 'input_file');
      $this->assertCount(0, $fileEntries2);
    }
  }

}
