<?php

namespace Drupal\Tests\ai_provider_openai\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ai_provider_openai\OpenAiResponsesStreamIterator;

/**
 * Tests the Responses API streamed message iterator.
 *
 * Feeds the iterator a fake stream of Responses events and asserts that text,
 * tool calls, the finish reason and token usage are reconstructed correctly.
 *
 * @group ai_provider_openai
 *
 * @coversDefaultClass \Drupal\ai_provider_openai\OpenAiResponsesStreamIterator
 */
class OpenAiResponsesStreamIteratorTest extends KernelTestBase {

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
   * Streamed text deltas accumulate into the reconstructed message.
   *
   * @covers ::doIterate
   */
  public function testStreamsText(): void {
    $iterator = new OpenAiResponsesStreamIterator($this->fakeStream([
      ['event' => 'response.created', 'data' => []],
      ['event' => 'response.output_text.delta', 'data' => ['delta' => 'Hello ']],
      ['event' => 'response.output_text.delta', 'data' => ['delta' => 'world']],
      ['event' => 'response.completed', 'data' => ['response' => ['status' => 'completed']]],
    ]));

    $text = '';
    foreach ($iterator as $message) {
      $text .= $message->getText();
    }

    $this->assertSame('Hello world', trim($text));
    $this->assertSame('completed', $iterator->getFinishReason());
    $this->assertSame('Hello world', trim($iterator->reconstructChatOutput()->getNormalized()->getText()));
  }

  /**
   * A streamed function call is reconstructed from its argument deltas.
   *
   * @covers ::doIterate
   */
  public function testStreamsToolCall(): void {
    $iterator = new OpenAiResponsesStreamIterator($this->fakeStream([
      [
        'event' => 'response.output_item.added',
        'data' => [
          'item' => [
            'type' => 'function_call',
            'call_id' => 'call_1',
            'name' => 'get_weather',
            'arguments' => '',
          ],
        ],
      ],
      ['event' => 'response.function_call_arguments.delta', 'data' => ['delta' => '{"location":']],
      ['event' => 'response.function_call_arguments.delta', 'data' => ['delta' => '"Paris"}']],
      ['event' => 'response.completed', 'data' => ['response' => ['status' => 'completed']]],
    ]));

    // Drive the stream to completion.
    iterator_to_array($iterator);

    $tools = $iterator->getTools();
    $this->assertCount(1, $tools);
    $this->assertSame('get_weather', $tools[0]->getName());
    $this->assertSame('call_1', $tools[0]->getToolId());
    $rendered = $tools[0]->getOutputRenderArray();
    $this->assertSame('{"location":"Paris"}', $rendered['function']['arguments']);
  }

  /**
   * Token usage and finish reason are read from the completion event.
   *
   * @covers ::doIterate
   */
  public function testStreamsTokenUsageAndFinishReason(): void {
    $iterator = new OpenAiResponsesStreamIterator($this->fakeStream([
      ['event' => 'response.output_text.delta', 'data' => ['delta' => 'Hi']],
      [
        'event' => 'response.completed',
        'data' => [
          'response' => [
            'status' => 'completed',
            'usage' => [
              'input_tokens' => 12,
              'input_tokens_details' => ['cached_tokens' => 4],
              'output_tokens' => 8,
              'output_tokens_details' => ['reasoning_tokens' => 5],
              'total_tokens' => 20,
            ],
          ],
        ],
      ],
    ]));

    // Drive the stream to completion.
    iterator_to_array($iterator);

    $this->assertSame('completed', $iterator->getFinishReason());
    $this->assertSame([
      'input' => 12,
      'output' => 8,
      'total' => 20,
      'reasoning' => 5,
      'cached' => 4,
      'cachedWrite' => NULL,
      'toolUse' => NULL,
    ], $iterator->reconstructChatOutput()->getTokenUsage()->toArray());
  }

  /**
   * Builds a fake stream wrapper yielding event objects with toArray().
   *
   * @param array $events
   *   A list of associative arrays, each with "event" and "data" keys.
   *
   * @return \IteratorAggregate
   *   A traversable mimicking the OpenAI SDK StreamResponse.
   */
  protected function fakeStream(array $events): \IteratorAggregate {
    $objects = array_map(fn(array $event): object => new class($event) {
      /**
       * The event payload.
       *
       * @var array
       */
      private array $event;

      public function __construct(array $event) {
        $this->event = $event;
      }

      /**
       * Returns the event payload.
       *
       * @return array
       *   The payload.
       */
      public function toArray(): array {
        return $this->event;
      }

    }, $events);

    return new class($objects) implements \IteratorAggregate {
      /**
       * The event objects.
       *
       * @var array
       */
      private array $objects;

      public function __construct(array $objects) {
        $this->objects = $objects;
      }

      /**
       * {@inheritdoc}
       */
      public function getIterator(): \Iterator {
        return new \ArrayIterator($this->objects);
      }

    };
  }

}
