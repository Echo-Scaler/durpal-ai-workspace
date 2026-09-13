<?php

namespace Drupal\ai_provider_openai;

/**
 * Value object for a streamed Responses API function-call fragment.
 *
 * The core StreamedChatMessageIterator reconstructs streamed tool calls from
 * objects exposing a toArray() method in the OpenAI Chat Completions
 * "tool_calls" delta shape. The Responses API streams function calls as a
 * separate item plus argument deltas, so this object adapts those fragments
 * back into the shape the iterator expects: a fragment with a non-empty id
 * starts a new tool call, a fragment with an empty id appends arguments to the
 * current one.
 */
class OpenAiResponsesToolCall {

  /**
   * Constructs an OpenAiResponsesToolCall.
   *
   * @param string $id
   *   The tool call id, or an empty string for an arguments-only fragment.
   * @param string $name
   *   The function name.
   * @param string $arguments
   *   The (partial) JSON arguments string.
   */
  public function __construct(
    protected string $id,
    protected string $name,
    protected string $arguments,
  ) {
  }

  /**
   * Renders the fragment in the OpenAI tool_calls delta shape.
   *
   * @return array
   *   The rendered array.
   */
  public function toArray(): array {
    return [
      'id' => $this->id,
      'function' => [
        'name' => $this->name,
        'arguments' => $this->arguments,
      ],
    ];
  }

}
