<?php

namespace Drupal\Tests\ai_provider_openai\Unit;

use Drupal\ai_provider_openai\OpenAiResponsesToolCall;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the Responses API streamed tool-call value object.
 *
 * @group ai_provider_openai
 *
 * @coversDefaultClass \Drupal\ai_provider_openai\OpenAiResponsesToolCall
 */
class OpenAiResponsesToolCallTest extends UnitTestCase {

  /**
   * An id-bearing fragment renders as the start of a new tool call.
   *
   * @covers ::toArray
   */
  public function testStartFragmentRendersIdAndName(): void {
    $fragment = new OpenAiResponsesToolCall('call_123', 'get_weather', '');
    $this->assertSame([
      'id' => 'call_123',
      'function' => [
        'name' => 'get_weather',
        'arguments' => '',
      ],
    ], $fragment->toArray());
  }

  /**
   * An empty-id fragment renders as an arguments-only append.
   *
   * The core StreamedChatMessageIterator appends arguments to the current tool
   * call when the rendered id is empty, so the append fragment must keep an
   * empty id.
   *
   * @covers ::toArray
   */
  public function testAppendFragmentHasEmptyId(): void {
    $fragment = new OpenAiResponsesToolCall('', '', '{"location":');
    $rendered = $fragment->toArray();
    $this->assertSame('', $rendered['id']);
    $this->assertSame('{"location":', $rendered['function']['arguments']);
  }

}
