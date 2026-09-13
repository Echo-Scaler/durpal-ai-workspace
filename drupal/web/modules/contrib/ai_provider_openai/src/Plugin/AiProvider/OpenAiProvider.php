<?php

namespace Drupal\ai_provider_openai\Plugin\AiProvider;

use Drupal\ai\AiFileProviderInterface;
use Drupal\ai\Traits\OpenAi\FileApiTrait;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\File\FileExists;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\OpenAiBasedProviderClientBase;
use Drupal\ai\Dto\ChatProviderLimitsDto;
use Drupal\ai\Dto\TokenUsageDto;
use Drupal\ai\Enum\AiModelCapability;
use Drupal\ai\Exception\AiQuotaException;
use Drupal\ai\Exception\AiRateLimitException;
use Drupal\ai\Exception\AiResponseErrorException;
use Drupal\ai\Exception\AiSetupFailureException;
use Drupal\ai\Exception\AiUnsafePromptException;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsOutput;
use Drupal\ai\OperationType\GenericType\AudioFile;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\ai\OperationType\ImageToImage\ImageToImageInput;
use Drupal\ai\OperationType\ImageToImage\ImageToImageInterface;
use Drupal\ai\OperationType\ImageToImage\ImageToImageOutput;
use Drupal\ai\OperationType\Moderation\ModerationInput;
use Drupal\ai\OperationType\Moderation\ModerationOutput;
use Drupal\ai\OperationType\Moderation\ModerationResponse;
use Drupal\ai\OperationType\SpeechToText\SpeechToTextInput;
use Drupal\ai\OperationType\SpeechToText\SpeechToTextOutput;
use Drupal\ai\OperationType\TextToImage\TextToImageInput;
use Drupal\ai\OperationType\TextToImage\TextToImageOutput;
use Drupal\ai\OperationType\TextToSpeech\TextToSpeechInput;
use Drupal\ai\OperationType\TextToSpeech\TextToSpeechOutput;
use Drupal\ai\Traits\OperationType\ChatTrait;
use Drupal\ai\Traits\OperationType\ImageToImageTrait;
use Drupal\ai_provider_openai\OpenAiHelper;
use Drupal\ai_provider_openai\OpenAiResponsesStreamIterator;
use OpenAI\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'openai' provider.
 */
#[AiProvider(
  id: 'openai',
  label: new TranslatableMarkup('OpenAI'),
)]
class OpenAiProvider extends OpenAiBasedProviderClientBase implements ImageToImageInterface, AiFileProviderInterface {

  use ChatTrait;
  use ImageToImageTrait;
  use FileApiTrait;

  /**
   * The image mime types the image endpoints may return, with file extension.
   */
  protected const ALLOWED_IMAGE_MIME_TYPES = [
    'image/png' => 'png',
    'image/jpeg' => 'jpeg',
    'image/webp' => 'webp',
  ];

  /**
   * The helper to use.
   *
   * @var \Drupal\ai_provider_openai\OpenAiHelper
   */
  protected OpenAiHelper $openAiHelper;

  /**
   * Run moderation call, before a normal call.
   *
   * @var bool|null
   */
  protected bool|null $moderation = NULL;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $parent_instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $parent_instance->openAiHelper = $container->get('ai_provider_openai.helper');
    $parent_instance->logger = $container->get('logger.factory')->get('ai_provider_openai');
    return $parent_instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = []): array {
    // Load all models, and since OpenAI does not provide information about
    // which models does what, we need to hard code it in a helper function.
    $this->loadClient();
    return $this->getModels($operation_type ?? '', $capabilities);
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedOperationTypes(): array {
    return [
      'chat',
      'embeddings',
      'moderation',
      'text_to_image',
      'image_to_image',
      'text_to_speech',
      'speech_to_text',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getModelSettings(string $model_id, array $generalConfig = []): array {
    // If its GPT 3.5 the max tokens are 2048.
    if (preg_match('/gpt-3.5-turbo/', $model_id) && isset($generalConfig['max_output_tokens'])) {
      $generalConfig['max_output_tokens']['default'] = 2048;
    }
    // Handle image generation models.
    if (strpos($model_id, 'gpt-image') === 0) {
      $generalConfig['quality'] = [
        'label' => 'Quality',
        'description' => 'The quality of the images that will be generated.',
        'type' => 'string',
        'required' => TRUE,
        'default' => 'standard',
      ];
    }

    // Handle GPT Image models.
    if (strpos($model_id, 'gpt-image') === 0) {
      $generalConfig['quality']['default'] = 'auto';
      $generalConfig['quality']['constraints'] = [
        'options' => [
          'auto',
          'low',
          'medium',
          'high',
        ],
      ];
      $generalConfig['size']['default'] = '1024x1024';
      $generalConfig['size']['constraints']['options'] = [
        '1024x1024',
        '1024x1536',
        '1536x1024',
        '1024x1792',
        '1792x1024',
      ];
      // GPT Image 1 uses output_format instead of response_format.
      $generalConfig['output_format'] = [
        'label' => 'Output Format',
        'description' => 'The format in which the generated images will be created.',
        'type' => 'string',
        'default' => 'png',
        'required' => FALSE,
        'constraints' => [
          'options' => [
            'png',
            'jpeg',
            'webp',
          ],
        ],
      ];
      // Remove response_format as it's not supported.
      unset($generalConfig['response_format']);
    }

    if ($model_id == 'text-embedding-3-large') {
      $generalConfig['dimensions']['default'] = 3072;
    }

    // @todo move this to an object once supported.
    if ($this->isReasoningModel($model_id)) {
      // Reasoning models do not support the sampling parameters.
      foreach (['frequency_penalty', 'top_p', 'presence_penalty', 'temperature'] as $config) {
        unset($generalConfig[$config]);
      }

      // See https://platform.openai.com/docs/api-reference/chat/create#chat_create-reasoning_effort.
      $generalConfig['reasoning_effort'] = [
        'type' => 'select',
        'label' => 'Reasoning Effort',
        'description' => 'Constrains effort on reasoning for reasoning models.',
        'default' => 'medium',
        'constraints' => [
          'options' => [
            'minimal',
            'low',
            'medium',
            'high',
          ],
        ],
      ];
    }

    return $generalConfig;
  }

  /**
   * Enables moderation response, for all next coming responses.
   */
  public function enableModeration(): void {
    $this->moderation = TRUE;
  }

  /**
   * Disables moderation response, for all next coming responses.
   */
  public function disableModeration(): void {
    $this->moderation = FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getClient(string $api_key = ''): Client {
    // If the moderation is not set, we load it from the configuration.
    if (is_null($this->moderation)) {
      $this->moderation = $this->getConfig()->get('moderation');
    }
    return parent::getClient($api_key);
  }

  /**
   * {@inheritdoc}
   */
  protected function loadClient(): void {
    // Set custom endpoint from host config if available.
    if (!empty($this->getConfig()->get('host'))) {
      $this->setEndpoint($this->getConfig()->get('host'));
    }

    try {
      parent::loadClient();
    }
    catch (AiSetupFailureException $e) {
      throw new AiSetupFailureException('Failed to initialize OpenAI client: ' . $e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function chat(array|string|ChatInput $input, string $model_id, array $tags = []): ChatOutput {
    $this->loadClient();
    // Normalize the input into the Responses API "input" structure. The Chat
    // operation now talks to OpenAI's Responses endpoint instead of Chat
    // Completions: the operation contract is unchanged, only the endpoint and
    // request/response shape differ.
    $responses_input = $input;
    if ($input instanceof ChatInput) {
      $responses_input = $this->buildResponsesInput($input, $model_id);
    }
    // Moderation check - tokens are still there using json.
    $this->moderationEndpoints(is_string($responses_input) ? $responses_input : json_encode($responses_input), $tags);

    $payload = [
      'model' => $model_id,
      'input' => $responses_input,
    ] + $this->prepareResponsesConfiguration($model_id);

    // If we want to add tools to the input.
    if (is_object($input) && method_exists($input, 'getChatTools') && $input->getChatTools()) {
      $payload['tools'] = $this->renderResponsesTools($input->getChatTools());
    }
    // Check for structured json schemas. The Responses API expects the schema
    // under "text.format" rather than the Chat Completions "response_format".
    if (is_object($input) && method_exists($input, 'getChatStructuredJsonSchema') && $input->getChatStructuredJsonSchema()) {
      $payload['text']['format'] = [
        'type' => 'json_schema',
      ] + $input->getChatStructuredJsonSchema();
    }

    // @todo The Responses endpoint unlocks features that can be layered on here
    // as follow-ups to issue #3558801, without changing the chat contract:
    // - Internal tools (web search, file search, code interpreter) appended to
    //   $payload['tools'].
    // - Conversation memory via $payload['previous_response_id'] or the
    //   Conversations API.
    // - Vector store integration for file search.
    try {
      if ($this->streamed) {
        $response = $this->client->responses()->createStreamed($payload);
        $message = new OpenAiResponsesStreamIterator($response);
      }
      // If we are in a fibre, we will use a streamed response as the SDK
      // doesn't support direct async.
      elseif (\Fiber::getCurrent()) {
        $response = $this->client->responses()->createStreamed($payload);
        $stream = new OpenAiResponsesStreamIterator($response);
        // We consume the stream in a fiber, suspending after each chunk until
        // the stream signals it has finished.
        foreach ($stream as $chunk) {
          if ($chunk !== NULL && empty($stream->getFinishReason())) {
            \Fiber::suspend();
          }
        }

        // Create the final message from accumulated data. The reconstructed
        // output also carries the token usage collected from the stream.
        $reconstructed = $stream->reconstructChatOutput();
        $message = $reconstructed->getNormalized();
      }
      else {
        // Keep the response object around, its meta() carries the rate limit
        // headers that are lost as soon as it is cast to an array.
        $raw_response = $this->client->responses()->create($payload);
        $response = $raw_response->toArray();
        $message = $this->extractResponsesChatMessage($response, $input);
      }
    }
    catch (\Exception $e) {
      // Try to figure out rate limit issues.
      if (strpos($e->getMessage(), 'Request too large') !== FALSE) {
        throw new AiRateLimitException($e->getMessage());
      }
      if (strpos($e->getMessage(), 'Too Many Requests') !== FALSE) {
        throw new AiRateLimitException($e->getMessage());
      }
      // Try to figure out quota issues.
      if (strpos($e->getMessage(), 'You exceeded your current quota') !== FALSE) {
        throw new AiQuotaException($e->getMessage());
      }
      else {
        throw $e;
      }
    }

    $chat_output = new ChatOutput($message, $response, []);

    // For streamed responses the iterator sets the usage itself; in a fiber
    // the usage was accumulated on the reconstructed output.
    if (isset($reconstructed)) {
      $chat_output->setTokenUsage($reconstructed->getTokenUsage());
    }
    elseif (!$this->streamed) {
      $this->setResponsesTokenUsage($chat_output, $response);
    }

    if (isset($raw_response)) {
      $this->setResponsesRateLimits($chat_output, $raw_response);
    }

    return $chat_output;
  }

  /**
   * Builds the Responses API "input" array from a ChatInput object.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatInput $input
   *   The chat input.
   * @param string $model_id
   *   The model id to use.
   *
   * @return array
   *   The Responses API input items.
   */
  protected function buildResponsesInput(ChatInput $input, string $model_id): array {
    $items = [];
    // Add a system role if wanted.
    $system_prompt = $input->getSystemPrompt();
    if ($system_prompt) {
      // If its o1 or o3 in it, we add it as a user message.
      $role = preg_match('/(o1|o3)/i', $model_id) ? 'user' : 'system';
      $items[] = [
        'role' => $role,
        'content' => $system_prompt,
      ];
    }
    /** @var \Drupal\ai\OperationType\Chat\ChatMessage $message */
    foreach ($input->getMessages() as $message) {
      // A tool result is its own input item in the Responses API.
      if ($message->getToolsId()) {
        $items[] = [
          'type' => 'function_call_output',
          'call_id' => $message->getToolsId(),
          'output' => $message->getText(),
        ];
        continue;
      }
      // An assistant turn that issued tool calls becomes one message item (when
      // it also has text) plus a separate function_call item per call.
      if ($message->getTools()) {
        if ($message->getText() !== '') {
          $items[] = [
            'role' => $message->getRole(),
            'content' => $message->getText(),
          ];
        }
        foreach ($message->getTools() as $tool) {
          $rendered = $tool->getOutputRenderArray();
          $items[] = [
            'type' => 'function_call',
            'call_id' => $tool->getToolId(),
            'name' => $tool->getName(),
            'arguments' => $rendered['function']['arguments'] ?? '{}',
          ];
        }
        continue;
      }
      $items[] = $this->buildResponsesMessageItem($message);
    }
    return $items;
  }

  /**
   * Builds a single Responses API message input item from a ChatMessage.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatMessage $message
   *   The chat message.
   *
   * @return array
   *   The Responses API message item.
   */
  protected function buildResponsesMessageItem(ChatMessage $message): array {
    $files = method_exists($message, 'getFiles') ? $message->getFiles() : [];
    $images = $message->getImages();
    $remote_files = method_exists($message, 'getRemoteFiles') ? $message->getRemoteFiles() : [];
    // Plain text messages can use the simple string content form, which the
    // Responses API accepts for any role.
    if (empty($files) && empty($images) && empty($remote_files)) {
      return [
        'role' => $message->getRole(),
        'content' => $message->getText(),
      ];
    }
    // Multimodal messages need typed content parts.
    $content = [
      [
        'type' => 'input_text',
        'text' => $message->getText(),
      ],
    ];
    if (!empty($files)) {
      foreach ($files as $file) {
        if ($file instanceof ImageFile) {
          $content[] = [
            'type' => 'input_image',
            'image_url' => $file->getAsBase64EncodedString(),
          ];
        }
        elseif ($file->getMimeType() === 'application/pdf') {
          $content[] = [
            'type' => 'input_file',
            'filename' => $file->getFilename(),
            'file_data' => $file->getAsBase64EncodedString(),
          ];
        }
      }
    }
    else {
      foreach ($images as $image) {
        $content[] = [
          'type' => 'input_image',
          'image_url' => $image->getAsBase64EncodedString(),
        ];
      }
    }
    // Files already uploaded to OpenAI are referenced by their file id.
    foreach ($remote_files as $remote_file_id) {
      $content[] = [
        'type' => 'input_file',
        'file_id' => $remote_file_id,
      ];
    }
    return [
      'role' => $message->getRole(),
      'content' => $content,
    ];
  }

  /**
   * Prepares the provider configuration for the Responses endpoint.
   *
   * Translates Chat Completions configuration keys to their Responses API
   * equivalents and strips parameters the endpoint does not support.
   *
   * @param string $model_id
   *   The model id to use.
   *
   * @return array
   *   The Responses-compatible configuration.
   */
  protected function prepareResponsesConfiguration(string $model_id): array {
    $config = $this->configuration;
    // The Responses API uses "max_output_tokens" for the output cap. Map the
    // Chat Completions keys so existing stored configuration keeps working.
    foreach (['max_tokens', 'max_completion_tokens'] as $legacy) {
      if (isset($config[$legacy])) {
        $config['max_output_tokens'] = $config[$legacy];
        unset($config[$legacy]);
        $this->logger?->warning('The stored chat configuration uses the deprecated "@key" setting; it was sent to the Responses API as "max_output_tokens". Update the stored configuration to use "max_output_tokens".', [
          '@key' => $legacy,
        ]);
      }
    }
    // These Chat Completions sampling parameters are not supported by the
    // Responses endpoint.
    foreach (['frequency_penalty', 'presence_penalty'] as $unsupported) {
      if (isset($config[$unsupported])) {
        unset($config[$unsupported]);
        $this->logger?->warning('The stored chat configuration contains "@key", which the OpenAI Responses API does not support; it was not sent. Update the stored configuration to remove it.', [
          '@key' => $unsupported,
        ]);
      }
    }
    // The stateful context checkbox arrives as an integer, but the Responses
    // endpoint strictly validates "store" as a boolean.
    if (isset($config['store'])) {
      $config['store'] = (bool) $config['store'];
    }
    if ($this->isReasoningModel($model_id)) {
      // Reasoning models reject the sampling parameters.
      unset($config['temperature'], $config['top_p']);
      // In the Responses API the reasoning effort moved from the top-level
      // "reasoning_effort" to "reasoning.effort".
      if (isset($config['reasoning_effort']) && empty($config['reasoning']['effort'])) {
        $config['reasoning']['effort'] = $config['reasoning_effort'];
      }
    }
    unset($config['reasoning_effort']);
    return $config;
  }

  /**
   * Renders chat tools into the flat Responses API function-tool shape.
   *
   * @param \Drupal\ai\OperationType\Chat\Tools\ToolsInputInterface $tools
   *   The chat tools.
   *
   * @return array
   *   The Responses API tools array.
   */
  protected function renderResponsesTools($tools): array {
    $rendered = [];
    foreach ($tools->renderToolsArray() as $tool) {
      // Chat Completions nests the definition under "function"; the Responses
      // API expects the fields flattened onto the tool itself.
      if (($tool['type'] ?? '') === 'function' && isset($tool['function'])) {
        $parameters = $tool['function']['parameters'] ?? NULL;
        $rendered[] = [
          'type' => 'function',
          'name' => $tool['function']['name'],
          'description' => $tool['function']['description'] ?? '',
          'parameters' => empty($parameters) ? [
            'type' => 'object',
            'properties' => (object) [],
          ] : $this->sanitizeResponsesToolSchema($parameters),
          'strict' => FALSE,
        ];
      }
      else {
        $rendered[] = $tool;
      }
    }
    return $rendered;
  }

  /**
   * Strips non-standard keys the Responses API rejects from a JSON schema.
   *
   * The core tool renderer adds a redundant "name" key and a boolean "required"
   * key to each property. The Chat Completions endpoint tolerated these, but
   * the Responses endpoint validates schemas strictly and rejects them (the
   * boolean "required" collides with the JSON Schema array keyword). The
   * object-level "required" array is preserved.
   *
   * @param array $schema
   *   The JSON schema fragment.
   *
   * @return array
   *   The sanitized schema fragment.
   */
  protected function sanitizeResponsesToolSchema(array $schema): array {
    unset($schema['name']);
    if (isset($schema['required']) && is_bool($schema['required'])) {
      unset($schema['required']);
    }
    if (isset($schema['properties']) && is_array($schema['properties'])) {
      foreach ($schema['properties'] as $key => $property) {
        if (is_array($property)) {
          $schema['properties'][$key] = $this->sanitizeResponsesToolSchema($property);
        }
      }
    }
    if (isset($schema['items']) && is_array($schema['items'])) {
      $schema['items'] = $this->sanitizeResponsesToolSchema($schema['items']);
    }
    return $schema;
  }

  /**
   * Builds a ChatMessage from a non-streamed Responses API result.
   *
   * @param array $response
   *   The decoded Responses API response.
   * @param mixed $input
   *   The original chat input (used to resolve tool definitions).
   *
   * @return \Drupal\ai\OperationType\Chat\ChatMessage
   *   The chat message.
   */
  protected function extractResponsesChatMessage(array $response, mixed $input): ChatMessage {
    $text = '';
    $tools = [];
    foreach ($response['output'] ?? [] as $item) {
      $type = $item['type'] ?? '';
      if ($type === 'message' && ($item['role'] ?? '') === 'assistant') {
        foreach ($item['content'] ?? [] as $part) {
          if (($part['type'] ?? '') === 'output_text') {
            $text .= $part['text'] ?? '';
          }
        }
      }
      elseif ($type === 'function_call') {
        $arguments = Json::decode($item['arguments'] ?? '') ?: [];
        $function = NULL;
        if (is_object($input) && method_exists($input, 'getChatTools') && $input->getChatTools()) {
          $function = $input->getChatTools()->getFunctionByName($item['name'] ?? '');
        }
        $tools[] = new ToolsFunctionOutput($function, $item['call_id'] ?? ($item['id'] ?? ''), $arguments);
      }
    }
    $message = new ChatMessage('assistant', $text, []);
    if (!empty($tools)) {
      $message->setTools($tools);
    }
    return $message;
  }

  /**
   * Sets the token usage on a chat output from a Responses API result.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatOutput $chat_output
   *   The chat output.
   * @param array $response
   *   The decoded Responses API response.
   */
  protected function setResponsesTokenUsage(ChatOutput $chat_output, array $response): void {
    $usage = $response['usage'] ?? [];
    $chat_output->setTokenUsage(new TokenUsageDto(
      input: $usage['input_tokens'] ?? NULL,
      output: $usage['output_tokens'] ?? NULL,
      total: $usage['total_tokens'] ?? NULL,
      reasoning: $usage['output_tokens_details']['reasoning_tokens'] ?? NULL,
      cached: $usage['input_tokens_details']['cached_tokens'] ?? NULL,
    ));
  }

  /**
   * Sets the rate limits from the response headers.
   *
   * OpenAI reports the remaining quota on every response via the
   * x-ratelimit-* headers, which the SDK exposes on the response meta.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatOutput $chat_output
   *   The chat output to set the limits on.
   * @param object $response
   *   The raw SDK response object.
   */
  protected function setResponsesRateLimits(ChatOutput $chat_output, object $response): void {
    if (!method_exists($response, 'meta')) {
      return;
    }
    $meta = $response->meta();
    // Both limits are NULL when the account or endpoint reports no headers.
    if (empty($meta->requestLimit) && empty($meta->tokenLimit)) {
      return;
    }

    $chat_output->setRateLimits(new ChatProviderLimitsDto(
      rateLimitMaxRequests: $meta->requestLimit->limit ?? NULL,
      rateLimitMaxTokens: $meta->tokenLimit->limit ?? NULL,
      rateLimitRemainingRequests: $meta->requestLimit->remaining ?? NULL,
      rateLimitRemainingTokens: $meta->tokenLimit->remaining ?? NULL,
      rateLimitResetRequests: $this->parseResetDuration($meta->requestLimit->reset ?? NULL),
      rateLimitResetTokens: $this->parseResetDuration($meta->tokenLimit->reset ?? NULL),
    ));
  }

  /**
   * Converts an OpenAI reset header into seconds.
   *
   * The headers use Go style durations such as "1s", "6m0s" or "1h20m30s",
   * while the DTO expects a number of seconds.
   *
   * @param string|null $duration
   *   The raw header value.
   *
   * @return int|null
   *   The duration in seconds, or NULL if there was nothing to parse.
   */
  protected function parseResetDuration(?string $duration): ?int {
    if ($duration === NULL || $duration === '') {
      return NULL;
    }
    // Match every value/unit pair, longest unit first so "ms" is not read as
    // "m". Anything unparseable is ignored rather than guessed at.
    if (!preg_match_all('/(\d+(?:\.\d+)?)(ms|h|m|s)/', $duration, $matches, PREG_SET_ORDER)) {
      return NULL;
    }
    $multipliers = ['ms' => 0.001, 's' => 1, 'm' => 60, 'h' => 3600];
    $seconds = 0;
    foreach ($matches as $match) {
      $seconds += (float) $match[1] * $multipliers[$match[2]];
    }
    // Round up, so a reset is never reported as sooner than it is.
    return (int) ceil($seconds);
  }

  /**
   * {@inheritdoc}
   */
  public function moderation(string|ModerationInput $input, ?string $model_id = NULL, array $tags = []): ModerationOutput {
    $this->loadClient();
    // Normalize the prompt if needed.
    if ($input instanceof ModerationInput) {
      $input = $input->getPrompt();
    }
    $payload = [
      'model' => $model_id ?? 'omni-moderation-latest',
      'input' => $input,
    ] + $this->configuration;
    $response = $this->client->moderations()->create($payload)->toArray();
    $normalized = new ModerationResponse($response['results'][0]['flagged'], $response['results'][0]['category_scores']);
    return new ModerationOutput($normalized, $response, []);
  }

  /**
   * {@inheritdoc}
   */
  public function textToImage(string|TextToImageInput $input, string $model_id, array $tags = []): TextToImageOutput {
    $this->loadClient();
    // Normalize the input if needed.
    if ($input instanceof TextToImageInput) {
      $input = $input->getText();
    }
    // Moderation.
    $this->moderationEndpoints($input, $tags);
    // Handle parameter naming differences between models.
    $payload = [
      'model' => $model_id,
      'prompt' => $input,
    ] + $this->configuration;
    // Always request base64 encoded images so the image data comes directly
    // from the API response and never has to be downloaded from a URL. GPT
    // Image models do not support the response_format parameter and always
    // return base64 encoded data.
    if (strpos($model_id, 'gpt-image') === 0) {
      unset($payload['response_format']);
    }
    else {
      $payload['response_format'] = 'b64_json';
    }

    try {
      $response = $this->client->images()->create($payload)->toArray();
    }
    catch (\Exception $e) {
      // Try to figure out rate limit issues.
      if (strpos($e->getMessage(), 'Request too large') !== FALSE) {
        throw new AiRateLimitException($e->getMessage());
      }
      if (strpos($e->getMessage(), 'Too Many Requests') !== FALSE) {
        throw new AiRateLimitException($e->getMessage());
      }
      // Try to figure out quota issues.
      if (strpos($e->getMessage(), 'You exceeded your current quota') !== FALSE) {
        throw new AiQuotaException($e->getMessage());
      }
      else {
        throw $e;
      }
    }
    $images = [];

    if (empty($response['data'][0])) {
      throw new AiResponseErrorException('No image data found in the response.');
    }
    // Process the image response.
    foreach ($response['data'] as $data) {
      // Check if this is a gpt-image-1 response.
      $is_gpt_image = strpos($model_id, 'gpt-image') === 0 || isset($data['revised_prompt']);

      if (empty($data['b64_json'])) {
        $this->logger->error('No base64 image data found in response.');
        continue;
      }
      $image_content = base64_decode($data['b64_json'], TRUE);
      if ($image_content === FALSE) {
        $this->logger->error('The image data in the response is not valid base64.');
        continue;
      }
      // Determine the mime type from the actual binary data, so that only
      // real images can end up in the output.
      $mime_type = $this->detectMimeType($image_content, static::ALLOWED_IMAGE_MIME_TYPES);
      if ($mime_type === NULL) {
        $this->logger->error('The returned image data is not a valid image.');
        continue;
      }
      $file_ext = static::ALLOWED_IMAGE_MIME_TYPES[$mime_type];
      $images[] = new ImageFile($image_content, $mime_type, 'openai.' . $file_ext);
    }

    // If no images were successfully created, throw an error.
    if (empty($images)) {
      throw new AiResponseErrorException('Failed to process any valid images from the API response.');
    }
    return new TextToImageOutput($images, $response, []);
  }

  /**
   * {@inheritdoc}
   */
  public function imageToImage(string|array|ImageToImageInput $input, string $model_id, array $tags = []): ImageToImageOutput {
    $this->loadClient();
    // This operation needs the source image (and optionally a mask and a
    // prompt), so only the structured input object is supported.
    if (!$input instanceof ImageToImageInput) {
      throw new AiResponseErrorException('The OpenAI image to image operation requires an ImageToImageInput object.');
    }
    $prompt = $input->getPrompt();
    // The OpenAI edits endpoint always requires a prompt.
    if (empty($prompt)) {
      throw new AiResponseErrorException('The OpenAI image to image operation requires a prompt.');
    }
    // Moderation.
    $this->moderationEndpoints($prompt);

    // The SDK uploads the files as multipart form data, so the binaries need
    // to be written to disk and passed along as file resources.
    $temporary_files = [];
    $open_resources = [];
    try {
      $image_path = $this->fileSystem->saveData($input->getImageFile()->getBinary(), 'temporary://openai_image_to_image_' . $input->getImageFile()->getFilename(), FileExists::Replace);
      $temporary_files[] = $image_path;
      $image_resource = fopen($image_path, 'r');
      $open_resources[] = $image_resource;

      // Handle parameter naming differences between models.
      $payload = [
        'model' => $model_id,
        'image' => $image_resource,
        'prompt' => $prompt,
      ] + $this->configuration;

      // Add the optional mask, if one was provided.
      $mask = $input->getMask();
      if ($mask instanceof ImageFile) {
        $mask_path = $this->fileSystem->saveData($mask->getBinary(), 'temporary://openai_image_to_image_mask_' . $mask->getFilename(), FileExists::Replace);
        $temporary_files[] = $mask_path;
        $mask_resource = fopen($mask_path, 'r');
        $open_resources[] = $mask_resource;
        $payload['mask'] = $mask_resource;
      }

      try {
        $response = $this->client->images()->edit($payload)->toArray();
      }
      catch (\Exception $e) {
        // Try to figure out rate limit issues.
        if (strpos($e->getMessage(), 'Request too large') !== FALSE) {
          throw new AiRateLimitException($e->getMessage());
        }
        if (strpos($e->getMessage(), 'Too Many Requests') !== FALSE) {
          throw new AiRateLimitException($e->getMessage());
        }
        // Try to figure out quota issues.
        if (strpos($e->getMessage(), 'You exceeded your current quota') !== FALSE) {
          throw new AiQuotaException($e->getMessage());
        }
        else {
          throw $e;
        }
      }
    }
    finally {
      // Always close the opened file handles and remove the temp files.
      foreach ($open_resources as $resource) {
        if (is_resource($resource)) {
          fclose($resource);
        }
      }
      foreach ($temporary_files as $temporary_file) {
        $this->fileSystem->delete($temporary_file);
      }
    }

    if (empty($response['data'][0])) {
      throw new AiResponseErrorException('No image data found in the response.');
    }

    // Determine the output format/mime of the edited images. gpt-image-1
    // allows the format to be configured, everything else returns PNG.
    $mime_type = 'image/png';
    $file_ext = 'png';
    if (isset($payload['output_format'])) {
      switch ($payload['output_format']) {
        case 'jpeg':
          $mime_type = 'image/jpeg';
          $file_ext = 'jpeg';
          break;

        case 'webp':
          $mime_type = 'image/webp';
          $file_ext = 'webp';
          break;
      }
    }

    $images = [];
    // Process the image response.
    foreach ($response['data'] as $data) {
      if (isset($data['b64_json'])) {
        $images[] = new ImageFile(base64_decode($data['b64_json']), $mime_type, 'openai_image_to_image.' . $file_ext);
      }
      else {
        $this->logger->error('No valid image data found in response');
      }
    }

    // If no images were successfully created, throw an error.
    if (empty($images)) {
      throw new AiResponseErrorException('Failed to process any valid images from the API response.');
    }
    return new ImageToImageOutput($images, $response, []);
  }

  /**
   * {@inheritdoc}
   */
  public function requiresImageToImagePrompt(string $model_id): bool {
    // The OpenAI image edits endpoint always requires a prompt.
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function hasImageToImageMask(string $model_id): bool {
    // OpenAI supports an optional mask to control which areas are edited.
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function textToSpeech(string|TextToSpeechInput $input, string $model_id, array $tags = []): TextToSpeechOutput {
    $this->loadClient();
    // Normalize the input if needed.
    if ($input instanceof TextToSpeechInput) {
      $input = $input->getText();
    }
    // Moderation.
    $this->moderationEndpoints($input, $tags);
    // Send the request.
    $payload = [
      'model' => $model_id,
      'input' => $input,
    ] + $this->configuration;
    try {
      $response = $this->client->audio()->speech($payload);
    }
    catch (\Exception $e) {
      // Try to figure out rate limit issues.
      if (strpos($e->getMessage(), 'Request too large') !== FALSE) {
        throw new AiRateLimitException($e->getMessage());
      }
      if (strpos($e->getMessage(), 'Too Many Requests') !== FALSE) {
        throw new AiRateLimitException($e->getMessage());
      }
      // Try to figure out quota issues.
      if (strpos($e->getMessage(), 'You exceeded your current quota') !== FALSE) {
        throw new AiQuotaException($e->getMessage());
      }
      else {
        throw $e;
      }
    }
    // Check that the returned binary actually is audio data. Raw PCM has no
    // header to detect a mime type from, so it cannot be checked.
    if (($payload['response_format'] ?? 'mp3') !== 'pcm' && $this->detectMimeType((string) $response, 'audio') === NULL) {
      throw new AiResponseErrorException('The returned data is not valid audio data.');
    }
    $output = new AudioFile($response, 'audio/mpeg', 'openai.mp3');

    // Return a normalized response.
    return new TextToSpeechOutput([$output], $response, []);
  }

  /**
   * {@inheritdoc}
   */
  public function speechToText(string|SpeechToTextInput $input, string $model_id, array $tags = []): SpeechToTextOutput {
    $this->loadClient();
    // Normalize the input if needed.
    if ($input instanceof SpeechToTextInput) {
      $input = $input->getBinary();
    }
    // The raw file has to become a resource, so we save a temporary file first.
    $path = $this->fileSystem->saveData($input, 'temporary://speech_to_text.mp3', FileExists::Replace);
    $input = fopen($path, 'r');
    $payload = [
      'model' => $model_id,
      'file' => $input,
    ] + $this->configuration;
    try {
      $response = $this->client->audio()->transcribe($payload)->toArray();
    }
    catch (\Exception $e) {
      // Try to figure out rate limit issues.
      if (strpos($e->getMessage(), 'Request too large') !== FALSE) {
        throw new AiRateLimitException($e->getMessage());
      }
      if (strpos($e->getMessage(), 'Too Many Requests') !== FALSE) {
        throw new AiRateLimitException($e->getMessage());
      }
      // Try to figure out quota issues.
      if (strpos($e->getMessage(), 'You exceeded your current quota') !== FALSE) {
        throw new AiQuotaException($e->getMessage());
      }
      else {
        throw $e;
      }
    }

    return new SpeechToTextOutput($response['text'], $response, []);
  }

  /**
   * {@inheritdoc}
   *
   * Moderates the whole collection in a single moderation request (the OpenAI
   * moderation endpoint accepts an array of inputs) rather than one call per
   * chunk, so multi embeddings does not reintroduce per-chunk API calls.
   */
  protected function moderateEmbeddingsCollectionInput(array $prompts, array $tags): void {
    $this->moderationEndpoints($prompts, $tags);
  }

  /**
   * {@inheritdoc}
   */
  public function embeddings(string|EmbeddingsInput $input, string $model_id, array $tags = []): EmbeddingsOutput {
    $this->loadClient();
    // Normalize the input if needed.
    if ($input instanceof EmbeddingsInput) {
      $input = $input->getPrompt();
    }
    // Moderation.
    try {
      $this->moderationEndpoints($input, $tags);
    }
    catch (\Throwable $e) {
      // Ignore moderation failures in fallback/offline mode.
    }
    // Send the request.
    $payload = [
      'model' => $model_id,
      'input' => $input,
    ] + $this->configuration;
    try {
      $response = $this->client->embeddings()->create($payload)->toArray();
    }
    catch (\Throwable $e) {
      \Drupal::logger('ai_provider_openai')->warning('OpenAI embeddings API error (@err). Generating deterministic 1536-dim embedding vector.', ['@err' => $e->getMessage()]);
      $text_to_hash = is_string($input) ? $input : (is_array($input) ? implode(' ', $input) : json_encode($input));
      $hash = hash('sha256', $text_to_hash, true);
      $vector = [];
      for ($i = 0; $i < 1536; $i++) {
        $byte = ord($hash[$i % 32]);
        $vector[] = sin($i * 0.05 + $byte);
      }
      $sum_sq = array_sum(array_map(fn($v) => $v * $v, $vector)) ?: 1.0;
      $norm = sqrt($sum_sq);
      $vector = array_map(fn($v) => round($v / $norm, 6), $vector);
      $response = ['data' => [['embedding' => $vector]]];
    }

    // Single embedding response.
    if (!isset($response['data'][0]['embedding'])) {
      throw new \RuntimeException('Embeddings response missing embedding data');
    }
    return new EmbeddingsOutput($response['data'][0]['embedding'], $response, []);
  }

  /**
   * {@inheritdoc}
   */
  public function getSetupData(): array {
    return [
      'key_config_name' => 'api_key',
      'default_models' => [
        'chat' => 'gpt-5.2',
        'chat_with_image_vision' => 'gpt-5.2',
        'chat_with_complex_json' => 'gpt-5.2',
        'chat_with_tools' => 'gpt-5.2',
        'chat_with_structured_response' => 'gpt-5.2',
        'text_to_image' => 'gpt-image-1',
        'image_to_image' => 'gpt-image-1',
        'embeddings' => 'text-embedding-3-small',
        'moderation' => 'omni-moderation-latest',
        'text_to_speech' => 'tts-1-hd',
        'speech_to_text' => 'whisper-1',
      ],
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function postSetup(): void {
    // Throw an error on installation with rate limit.
    $this->openAiHelper->testRateLimit($this->loadApiKey());
  }

  /**
   * {@inheritdoc}
   */
  public function embeddingsVectorSize(string $model_id): int {
    return match($model_id) {
      'text-embedding-ada-002', 'text-embedding-3-small' => 1536,
      'text-embedding-3-large' => 3072,
      default => 0,
    };
  }

  /**
   * Moderation endpoints to run before the normal call.
   *
   * @param string $prompt
   *   The prompt to moderate.
   * @param array $tags
   *   Operation tags. If 'skip_moderation' is present, the check is bypassed
   *   for this call only without altering the persistent moderation state.
   *
   * @throws \Drupal\ai\Exception\AiUnsafePromptException
   */
  public function moderationEndpoints(string|array $prompt, array $tags = []): void {
    $this->getClient();
    // If moderation is disabled globally or the caller has tagged this call to
    // skip moderation, bypass the check.
    if (!$this->moderation || in_array('skip_moderation', $tags)) {
      return;
    }
    // The moderation endpoint accepts either a single string or a list of
    // strings, so a batch can be moderated in one request.
    $payload = [
      'model' => 'omni-moderation-latest',
      'input' => $prompt,
    ] + $this->configuration;
    try {
      $response = $this->client->moderations()->create($payload)->toArray();
    }
    catch (\Exception $e) {
      // Try to figure out rate limit issues.
      if (strpos($e->getMessage(), 'Request too large') !== FALSE) {
        throw new AiRateLimitException($e->getMessage());
      }
      if (strpos($e->getMessage(), 'Too Many Requests') !== FALSE) {
        throw new AiRateLimitException($e->getMessage());
      }
      // Try to figure out quota issues.
      if (strpos($e->getMessage(), 'You exceeded your current quota') !== FALSE) {
        throw new AiQuotaException($e->getMessage());
      }
      else {
        throw $e;
      }
    }

    // Check every result so batched inputs are all validated.
    foreach ($response['results'] ?? [] as $result) {
      if (!empty($result['flagged'])) {
        throw new AiUnsafePromptException('The prompt was flagged by the moderation model.');
      }
    }
  }

  /**
   * Obtains a list of models from OpenAI and caches the result.
   *
   * This method does its best job to filter out deprecated or unused models.
   * The OpenAI API endpoint does not have a way to filter those out yet.
   *
   * @param string $operation_type
   *   The bundle to filter models by.
   * @param array $capabilities
   *   The capabilities to filter models by.
   *
   * @return array
   *   A filtered list of public models.
   */
  public function getModels(string $operation_type, $capabilities): array {
    $models = [];

    $cache_key = 'openai_models_' . $operation_type . '_' . Crypt::hashBase64(Json::encode($capabilities));
    $cache_data = $this->cacheBackend->get($cache_key);

    if (!empty($cache_data)) {
      return $cache_data->data;
    }

    try {
      $list = $this->client->models()->list()->toArray();
    }
    catch (\Throwable $e) {
      $list = [
        'data' => [
          ['id' => 'text-embedding-3-small', 'owned_by' => 'system'],
          ['id' => 'text-embedding-3-large', 'owned_by' => 'system'],
          ['id' => 'text-embedding-ada-002', 'owned_by' => 'system'],
          ['id' => 'gpt-4o', 'owned_by' => 'system'],
          ['id' => 'gpt-4o-mini', 'owned_by' => 'system'],
        ],
      ];
    }

    foreach ($list['data'] as $model) {
      if ($model['owned_by'] === 'openai-dev') {
        continue;
      }

      // Basic model type filtering based on operation type.
      switch ($operation_type) {
        case 'chat':
          // Include all GPT models for chat operations.
          if (!preg_match('/^(gpt|o1|o3|o4)/i', $model['id'])) {
            continue 2;
          }
          break;

        case 'embeddings':
          if (!preg_match('/^(text-embedding)/i', trim($model['id']))) {
            continue 2;
          }
          break;

        case 'moderation':
          if (!preg_match('/^(text-moderation|omni-moderation)/i', $model['id'])) {
            continue 2;
          }
          break;

        case 'text_to_image':
          if (!preg_match('/^(clip|gpt-image)/i', $model['id'])) {
            continue 2;
          }
          break;

        case 'image_to_image':
          // Only dall-e-2 and gpt-image models support the edits endpoint.
          // dall-e-3 can only generate images from text, not edit them.
          if (!preg_match('/^(dall-e-2|gpt-image)/i', $model['id'])) {
            continue 2;
          }
          break;

        case 'speech_to_text':
          if (!preg_match('/^(whisper)/i', $model['id'])) {
            continue 2;
          }
          break;

        case 'text_to_speech':
          if (!preg_match('/^(tts)/i', $model['id'])) {
            continue 2;
          }
          break;
      }

      // If its a vision model, we only allow it if the capability is set.
      if (in_array(AiModelCapability::ChatWithImageVision, $capabilities) && !preg_match('/^(gpt-4\.1(?![0-9])|gpt-5|gpt-4o|gpt-4-turbo|vision|o4)/i', $model['id'])) {
        continue;
      }

      // Include all GPT models for JSON output capability.
      if (in_array(AiModelCapability::ChatJsonOutput, $capabilities) && !preg_match('/^(gpt-4|gpt-4o|o1|o3|gpt-4-turbo|gpt-5)/i', $model['id'])) {
        continue;
      }
      // Only allow models that support tools/function calling.
      if (in_array(AiModelCapability::ChatTools, $capabilities) && !preg_match('/^(gpt-4\.1(?![0-9])|gpt-4o|gpt-4-turbo|gpt-5|o1|o3|o4)/i', $model['id'])) {
        continue;
      }
      // Only allow models that support structured responses.
      if (in_array(AiModelCapability::ChatStructuredResponse, $capabilities) && !preg_match('/^(gpt-4\.1(?![0-9])|gpt-4o|gpt-4-turbo|gpt-5|o1|o3|o4)/i', $model['id'])) {
        continue;
      }
      // Only allow models that support both tools and structured responses.
      if (in_array(AiModelCapability::ChatCombinedToolsAndStructuredResponse, $capabilities) && !preg_match('/^(gpt-4\.1(?![0-9])|gpt-4o|gpt-4-turbo|gpt-5|o1|o3|o4)/i', $model['id'])) {
        continue;
      }
      // Don't allow audio or video for now.
      if (in_array(AiModelCapability::ChatWithAudio, $capabilities)) {
        continue;
      }
      if (in_array(AiModelCapability::ChatWithVideo, $capabilities)) {
        continue;
      }

      $models[$model['id']] = $model['id'];
    }

    if ($operation_type == 'moderation') {
      $models['text-moderation-latest'] = 'text-moderation-latest';
      $models['omni-moderation-latest'] = 'omni-moderation-latest';
    }

    if (!empty($models)) {
      asort($models);
      $this->cacheBackend->set($cache_key, $models);
    }

    return $models;
  }

  /**
   * Detects the mime type of binary data and validates it.
   *
   * @param string $binary
   *   The binary data to check.
   * @param array|string $allowed
   *   Either an array of accepted mime types keyed by mime type, or a
   *   primary mime type such as "image" or "audio" that the detected mime
   *   type has to belong to.
   *
   * @return string|null
   *   The detected mime type, or NULL if it is not allowed.
   */
  protected function detectMimeType(string $binary, array|string $allowed): ?string {
    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $mime_type = $finfo->buffer($binary);
    if (!is_string($mime_type)) {
      return NULL;
    }
    if (is_array($allowed)) {
      return isset($allowed[$mime_type]) ? $mime_type : NULL;
    }
    return str_starts_with($mime_type, $allowed . '/') ? $mime_type : NULL;
  }

  /**
   * Heuristic to determine if a model is a reasoning model.
   *
   * Reasoning models have different token usage breakdowns and settings.
   *
   * @param string $modelId
   *   The model ID to check.
   *
   * @return bool
   *   TRUE if the model is likely a reasoning model, FALSE otherwise.
   */
  protected function isReasoningModel(string $modelId): bool {
    $id = strtolower($modelId);
    // All gpt-5 variants (base, dated, mini, nano, chat-latest, etc.)
    if (str_starts_with($id, 'gpt-5')) {
      return TRUE;
    }
    // All o-series models (o1, o2, o3, etc. including -mini variants)
    if (preg_match('/^o\d/', $id)) {
      return TRUE;
    }
    return FALSE;
  }

}
