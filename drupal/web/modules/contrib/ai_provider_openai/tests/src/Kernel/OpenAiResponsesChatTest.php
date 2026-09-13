<?php

namespace Drupal\Tests\ai_provider_openai\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionInput;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutput;
use Drupal\ai\OperationType\Chat\Tools\ToolsInput;
use Drupal\ai\OperationType\Chat\Tools\ToolsPropertyInput;
use Drupal\ai\OperationType\GenericType\ImageFile;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Log\AbstractLogger;

/**
 * Tests that the chat operation talks to the OpenAI Responses endpoint.
 *
 * The OpenAI client is built over a mock HTTP transport so the outgoing request
 * payload and the response parsing can be asserted without network access.
 *
 * @group ai_provider_openai
 *
 * @coversDefaultClass \Drupal\ai_provider_openai\Plugin\AiProvider\OpenAiProvider
 */
class OpenAiResponsesChatTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'key',
    'file',
    'system',
    'user',
    'ai',
    'ai_provider_openai',
  ];

  /**
   * The captured request/response history of the mock transport.
   *
   * @var array
   */
  protected array $history = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['ai_provider_openai']);
    // Disable moderation so the chat call does not require a moderation
    // round-trip through the mock transport.
    $this->config('ai_provider_openai.settings')->set('moderation', FALSE)->save();
  }

  /**
   * Creates an OpenAI provider backed by a mock transport.
   *
   * @param \GuzzleHttp\Psr7\Response $response
   *   The canned HTTP response the Responses endpoint should return.
   *
   * @return \Drupal\ai_provider_openai\Plugin\AiProvider\OpenAiProvider
   *   The provider instance.
   */
  protected function providerWithResponse(Response $response) {
    // The provider manager returns a ProviderProxy; unwrap it to reach the
    // real plugin whose HTTP client we replace.
    $provider = $this->container->get('ai.provider')->createInstance('openai')->getPlugin();

    $this->history = [];
    $stack = HandlerStack::create(new MockHandler([$response]));
    $stack->push(Middleware::history($this->history));
    $guzzle = new GuzzleClient(['handler' => $stack]);

    // Inject the mock HTTP client and a dummy key so a real OpenAI\Client is
    // built over the mock transport (no network, no real credentials).
    $this->setProtected($provider, 'httpClient', $guzzle);
    $this->setProtected($provider, 'apiKey', 'test-key');
    return $provider;
  }

  /**
   * Sets a protected property on an object.
   */
  protected function setProtected(object $object, string $property, mixed $value): void {
    $reflection = new \ReflectionProperty($object, $property);
    $reflection->setAccessible(TRUE);
    $reflection->setValue($object, $value);
  }

  /**
   * Returns the decoded payload of the request sent to the responses endpoint.
   *
   * @return array
   *   The decoded JSON request body.
   */
  protected function sentPayload(): array {
    $this->assertNotEmpty($this->history, 'A request was sent to OpenAI.');
    $request = $this->history[0]['request'];
    $this->assertSame('POST', $request->getMethod());
    $this->assertStringEndsWith('/responses', $request->getUri()->getPath());
    return json_decode((string) $request->getBody(), TRUE);
  }

  /**
   * Builds a canned non-streamed Responses HTTP response.
   *
   * @param string $text
   *   The assistant message text.
   * @param array $extra_output
   *   Additional output items (e.g. function calls) to append.
   * @param array $usage
   *   The usage payload.
   *
   * @return \GuzzleHttp\Psr7\Response
   *   The canned response.
   */
  protected function cannedResponse(string $text, array $extra_output = [], array $usage = []): Response {
    $output = [];
    if ($text !== '') {
      $output[] = [
        'type' => 'message',
        'id' => 'msg_1',
        'status' => 'completed',
        'role' => 'assistant',
        'content' => [
          ['type' => 'output_text', 'text' => $text, 'annotations' => []],
        ],
      ];
    }
    $output = array_merge($output, $extra_output);
    $body = [
      'id' => 'resp_1',
      'object' => 'response',
      'created_at' => 1700000000,
      'status' => 'completed',
      'model' => 'gpt-4o-mini',
      'output' => $output,
      'parallel_tool_calls' => FALSE,
      'tool_choice' => 'auto',
      'tools' => [],
      'text' => ['format' => ['type' => 'text']],
      'reasoning' => ['effort' => NULL, 'generate_summary' => NULL],
      'metadata' => [],
      'usage' => $usage ?: [
        'input_tokens' => 10,
        'input_tokens_details' => ['cached_tokens' => 0],
        'output_tokens' => 5,
        'output_tokens_details' => ['reasoning_tokens' => 0],
        'total_tokens' => 15,
      ],
    ];
    return new Response(200, ['Content-Type' => 'application/json'], json_encode($body));
  }

  /**
   * The request targets the responses endpoint with mapped input and config.
   *
   * @covers ::chat
   * @covers ::buildResponsesInput
   * @covers ::buildResponsesMessageItem
   * @covers ::prepareResponsesConfiguration
   */
  public function testChatRequestMapsInputAndConfiguration(): void {
    $provider = $this->providerWithResponse($this->cannedResponse('ok'));
    $provider->setConfiguration([
      'max_tokens' => 1000,
      'temperature' => 0.5,
      'frequency_penalty' => 0.2,
      'presence_penalty' => 0.1,
      'top_p' => 0.9,
    ]);
    // Collect log records so the deprecated-key warnings can be asserted.
    $logger = new class() extends AbstractLogger {

      /**
       * The collected log records as [level, message, context] tuples.
       *
       * @var array
       */
      public array $records = [];

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->records[] = [$level, (string) $message, $context];
      }

    };
    $this->setProtected($provider, 'logger', $logger);
    $input = new ChatInput([new ChatMessage('user', 'Hi there')]);
    // In production the ProviderProxy bridges the provider's system role into
    // the input; set it directly to exercise the provider's mapping.
    $input->setSystemPrompt('You are helpful.');
    $provider->chat($input, 'gpt-4o-mini');

    $payload = $this->sentPayload();
    $this->assertSame('gpt-4o-mini', $payload['model']);
    // Messages mapped to the "input" array, not "messages".
    $this->assertArrayNotHasKey('messages', $payload);
    $this->assertSame('system', $payload['input'][0]['role']);
    $this->assertSame('You are helpful.', $payload['input'][0]['content']);
    $this->assertSame('user', $payload['input'][1]['role']);
    $this->assertSame('Hi there', $payload['input'][1]['content']);
    // Configuration translated to Responses keys.
    $this->assertArrayNotHasKey('max_tokens', $payload);
    $this->assertSame(1000, $payload['max_output_tokens']);
    $this->assertArrayNotHasKey('frequency_penalty', $payload);
    $this->assertArrayNotHasKey('presence_penalty', $payload);
    $this->assertSame(0.5, $payload['temperature']);
    $this->assertSame(0.9, $payload['top_p']);
    // Each translated or dropped legacy key is reported as a warning.
    $warned_keys = array_map(
      fn(array $record) => $record[2]['@key'] ?? NULL,
      array_filter($logger->records, fn(array $record) => $record[0] === 'warning'),
    );
    $this->assertContains('max_tokens', $warned_keys);
    $this->assertContains('frequency_penalty', $warned_keys);
    $this->assertContains('presence_penalty', $warned_keys);
  }

  /**
   * Image input is mapped to typed input_text and input_image content parts.
   *
   * @covers ::buildResponsesMessageItem
   */
  public function testChatRequestMapsMultimodalInput(): void {
    $provider = $this->providerWithResponse($this->cannedResponse('ok'));
    $message = new ChatMessage('user', 'What is this?');
    $message->setImage(new ImageFile('binary', 'image/png', 'pic.png'));
    $provider->chat(new ChatInput([$message]), 'gpt-4o-mini');

    $content = $this->sentPayload()['input'][0]['content'];
    $this->assertSame('input_text', $content[0]['type']);
    $this->assertSame('What is this?', $content[0]['text']);
    $this->assertSame('input_image', $content[1]['type']);
    $this->assertStringStartsWith('data:image/png;base64,', $content[1]['image_url']);
  }

  /**
   * Tools are flattened and the leaked schema keys are sanitized.
   *
   * @covers ::renderResponsesTools
   * @covers ::sanitizeResponsesToolSchema
   */
  public function testChatRequestFlattensAndSanitizesTools(): void {
    $provider = $this->providerWithResponse($this->cannedResponse('ok'));
    $input = new ChatInput([new ChatMessage('user', 'Weather in Paris?')]);
    $input->setChatTools($this->buildWeatherTool());
    $provider->chat($input, 'gpt-4o-mini');

    $tool = $this->sentPayload()['tools'][0];
    // Flattened: no nested "function" key, fields on the tool itself.
    $this->assertSame('function', $tool['type']);
    $this->assertSame('get_weather', $tool['name']);
    $this->assertArrayNotHasKey('function', $tool);
    $this->assertFalse($tool['strict']);
    // The object-level required array is preserved.
    $this->assertSame(['location'], $tool['parameters']['required']);
    // The non-standard property-level keys are stripped.
    $property = $tool['parameters']['properties']['location'];
    $this->assertArrayNotHasKey('required', $property);
    $this->assertArrayNotHasKey('name', $property);
    $this->assertSame('string', $property['type']);
  }

  /**
   * Structured output is sent under text.format, not response_format.
   *
   * @covers ::chat
   */
  public function testChatRequestMapsStructuredOutput(): void {
    $provider = $this->providerWithResponse($this->cannedResponse('{}'));
    $input = new ChatInput([new ChatMessage('user', 'Give JSON')]);
    $input->setChatStructuredJsonSchema([
      'name' => 'city',
      'schema' => ['type' => 'object', 'properties' => ['c' => ['type' => 'string']]],
      'strict' => TRUE,
    ]);
    $provider->chat($input, 'gpt-4o-mini');

    $payload = $this->sentPayload();
    $this->assertArrayNotHasKey('response_format', $payload);
    $this->assertSame('json_schema', $payload['text']['format']['type']);
    $this->assertSame('city', $payload['text']['format']['name']);
    $this->assertTrue($payload['text']['format']['strict']);
  }

  /**
   * Reasoning models drop sampling params and move the reasoning effort.
   *
   * @covers ::prepareResponsesConfiguration
   */
  public function testChatRequestReasoningModelConfiguration(): void {
    $provider = $this->providerWithResponse($this->cannedResponse('ok'));
    $provider->setConfiguration([
      'max_tokens' => 500,
      'temperature' => 0.5,
      'top_p' => 0.9,
      'reasoning_effort' => 'low',
    ]);
    $provider->chat(new ChatInput([new ChatMessage('user', 'Think')]), 'gpt-5');

    $payload = $this->sentPayload();
    $this->assertArrayNotHasKey('temperature', $payload);
    $this->assertArrayNotHasKey('top_p', $payload);
    $this->assertArrayNotHasKey('reasoning_effort', $payload);
    $this->assertSame('low', $payload['reasoning']['effort']);
    $this->assertSame(500, $payload['max_output_tokens']);
  }

  /**
   * The stateful context setting is sent as a boolean "store" parameter.
   *
   * @covers ::prepareResponsesConfiguration
   */
  public function testChatRequestMapsStatefulContext(): void {
    // Checkbox values arrive as integers and must be cast, as the Responses
    // endpoint strictly validates "store" as a boolean.
    $provider = $this->providerWithResponse($this->cannedResponse('ok'));
    $provider->setConfiguration(['store' => 1]);
    $provider->chat(new ChatInput([new ChatMessage('user', 'Hi')]), 'gpt-4o-mini');
    $this->assertTrue($this->sentPayload()['store']);

    $provider = $this->providerWithResponse($this->cannedResponse('ok'));
    $provider->setConfiguration(['store' => 0]);
    $provider->chat(new ChatInput([new ChatMessage('user', 'Hi')]), 'gpt-4o-mini');
    $this->assertFalse($this->sentPayload()['store']);

    // Without the setting the parameter is omitted and OpenAI's own default
    // applies.
    $provider = $this->providerWithResponse($this->cannedResponse('ok'));
    $provider->chat(new ChatInput([new ChatMessage('user', 'Hi')]), 'gpt-4o-mini');
    $this->assertArrayNotHasKey('store', $this->sentPayload());
  }

  /**
   * Tool history is mapped to function_call and function_call_output items.
   *
   * @covers ::buildResponsesInput
   */
  public function testChatRequestMapsToolHistory(): void {
    $provider = $this->providerWithResponse($this->cannedResponse('done'));
    $tools = $this->buildWeatherTool();

    // A prior assistant turn that issued a tool call.
    $assistant = new ChatMessage('assistant', '');
    $call = new ToolsFunctionOutput(
      $tools->getFunctionByName('get_weather'),
      'call_xyz',
      ['location' => 'Paris'],
    );
    $assistant->setTools([$call]);
    // The tool result.
    $result = new ChatMessage('tool', 'Sunny, 21C');
    $result->setToolsId('call_xyz');

    $input = new ChatInput([
      new ChatMessage('user', 'Weather in Paris?'),
      $assistant,
      $result,
    ]);
    $input->setChatTools($tools);
    $provider->chat($input, 'gpt-4o-mini');

    $items = $this->sentPayload()['input'];
    // A function_call item carries the call id, name and arguments.
    $call = $this->findItemByType($items, 'function_call');
    $this->assertSame('call_xyz', $call['call_id']);
    $this->assertSame('get_weather', $call['name']);
    $this->assertSame('{"location":"Paris"}', $call['arguments']);
    // A function_call_output item carries the tool result.
    $output = $this->findItemByType($items, 'function_call_output');
    $this->assertSame('call_xyz', $output['call_id']);
    $this->assertSame('Sunny, 21C', $output['output']);
  }

  /**
   * A non-streamed response is parsed into text, tools and token usage.
   *
   * @covers ::extractResponsesChatMessage
   * @covers ::setResponsesTokenUsage
   */
  public function testChatParsesNonStreamedResponse(): void {
    $function_call = [
      'type' => 'function_call',
      'id' => 'fc_1',
      'call_id' => 'call_abc',
      'name' => 'get_weather',
      'arguments' => '{"location":"Paris"}',
      'status' => 'completed',
    ];
    $usage = [
      'input_tokens' => 11,
      'input_tokens_details' => ['cached_tokens' => 2],
      'output_tokens' => 7,
      'output_tokens_details' => ['reasoning_tokens' => 3],
      'total_tokens' => 18,
    ];
    $provider = $this->providerWithResponse($this->cannedResponse('Hello there', [$function_call], $usage));
    $input = new ChatInput([new ChatMessage('user', 'Weather?')]);
    $input->setChatTools($this->buildWeatherTool());
    $output = $provider->chat($input, 'gpt-4o-mini');

    $message = $output->getNormalized();
    $this->assertSame('assistant', $message->getRole());
    $this->assertSame('Hello there', $message->getText());

    $tools = $message->getTools();
    $this->assertCount(1, $tools);
    $this->assertSame('get_weather', $tools[0]->getName());
    $this->assertSame('call_abc', $tools[0]->getToolId());
    $rendered = $tools[0]->getOutputRenderArray();
    $this->assertSame('{"location":"Paris"}', $rendered['function']['arguments']);

    $this->assertSame([
      'input' => 11,
      'output' => 7,
      'total' => 18,
      'reasoning' => 3,
      'cached' => 2,
      'cachedWrite' => NULL,
      'toolUse' => NULL,
    ], $output->getTokenUsage()->toArray());
  }

  /**
   * Builds a get_weather tool with a required string property.
   *
   * @return \Drupal\ai\OperationType\Chat\Tools\ToolsInput
   *   The tools input.
   */
  protected function buildWeatherTool(): ToolsInput {
    $property = new ToolsPropertyInput('location', [
      'description' => 'The city name',
      'type' => 'string',
      'required' => TRUE,
    ]);
    $function = new ToolsFunctionInput('get_weather', [
      'description' => 'Get the current weather for a city',
    ]);
    $function->setProperty($property);
    return new ToolsInput([$function]);
  }

  /**
   * Finds the first input item of the given type.
   *
   * @param array $items
   *   The input items.
   * @param string $type
   *   The item type.
   *
   * @return array
   *   The matching item.
   */
  protected function findItemByType(array $items, string $type): array {
    foreach ($items as $item) {
      if (($item['type'] ?? '') === $type) {
        return $item;
      }
    }
    $this->fail("No input item of type '$type' was sent.");
  }

}
