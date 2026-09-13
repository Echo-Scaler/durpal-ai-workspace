<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_openai\Unit;

use Drupal\ai\OperationType\Embeddings\EmbeddingsCollectionInput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsCollectionOutput;
use Drupal\Tests\UnitTestCase;
use Drupal\ai\Exception\AiUnsafePromptException;
use Drupal\ai_provider_openai\Plugin\AiProvider\OpenAiProvider;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use OpenAI\Factory;

/**
 * Unit tests for OpenAI multi embeddings and moderation handling.
 *
 * Uses a real OpenAI client backed by a mocked Guzzle handler so the SDK's
 * request/response handling is exercised without network access.
 *
 * @coversDefaultClass \Drupal\ai_provider_openai\Plugin\AiProvider\OpenAiProvider
 * @group ai_provider_openai
 */
class OpenAiProviderTest extends UnitTestCase {

  /**
   * Build an OpenAiProvider whose client returns the queued HTTP responses.
   *
   * @param \GuzzleHttp\Psr7\Response[] $responses
   *   Canned HTTP responses, consumed in order.
   * @param bool|null $moderation
   *   The moderation flag to set on the provider.
   *
   * @return \Drupal\ai_provider_openai\Plugin\AiProvider\OpenAiProvider
   *   The provider with a mocked client and stubbed loadClient().
   */
  protected function provider(array $responses, ?bool $moderation = NULL): OpenAiProvider {
    $guzzle = new GuzzleClient(['handler' => HandlerStack::create(new MockHandler($responses))]);
    $client = (new Factory())->withApiKey('test')->withHttpClient($guzzle)->make();

    $provider = $this->getMockBuilder(OpenAiProvider::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['loadClient'])
      ->getMock();
    // loadClient() is a no-op so the injected client is preserved.
    $provider->method('loadClient')->willReturnCallback(fn() => NULL);

    $ref = new \ReflectionObject($provider);
    $clientProp = $ref->getProperty('client');
    $clientProp->setAccessible(TRUE);
    $clientProp->setValue($provider, $client);
    if ($moderation !== NULL) {
      $modProp = $ref->getProperty('moderation');
      $modProp->setAccessible(TRUE);
      $modProp->setValue($provider, $moderation);
    }
    return $provider;
  }

  /**
   * Build an embeddings HTTP response.
   */
  protected function embeddingsResponse(array $data): Response {
    return new Response(200, ['Content-Type' => 'application/json'], json_encode([
      'object' => 'list',
      'model' => 'text-embedding-3-small',
      'data' => $data,
      'usage' => ['prompt_tokens' => 1, 'total_tokens' => 1],
    ]));
  }

  /**
   * Build a moderation HTTP response from a list of flagged booleans.
   */
  protected function moderationResponse(array $flaggedList): Response {
    $results = array_map(fn(bool $flagged) => [
      'flagged' => $flagged,
      'categories' => [],
      'category_scores' => [],
    ], $flaggedList);
    return new Response(200, ['Content-Type' => 'application/json'], json_encode([
      'id' => 'test-id',
      'model' => 'omni-moderation-latest',
      'results' => $results,
    ]));
  }

  /**
   * Multi embeddings are returned in input order, sorted by the index field.
   *
   * @covers \Drupal\ai\Base\OpenAiBasedProviderClientBase::embeddingsCollection
   */
  public function testEmbeddingsCollectionSortedByIndex(): void {
    // Deliberately out of order: index 1 first.
    $provider = $this->provider([
      $this->embeddingsResponse([
        ['object' => 'embedding', 'index' => 1, 'embedding' => [9.0, 9.0]],
        ['object' => 'embedding', 'index' => 0, 'embedding' => [1.0, 1.0]],
      ]),
    ], FALSE);

    $output = $provider->embeddingsCollection(new EmbeddingsCollectionInput(['x', 'y']), 'text-embedding-3-small', ['skip_moderation']);

    // Sorted by index: index-0 vector ([1,1]) comes before index-1 ([9,9]).
    // assertEquals, not assertSame: JSON round-trips whole floats as ints.
    $this->assertSame(EmbeddingsCollectionOutput::class, get_class($output));
    $this->assertEquals([[1.0, 1.0], [9.0, 9.0]], $output->getNormalized());
  }

  /**
   * A mismatch between returned and requested counts throws.
   *
   * @covers \Drupal\ai\Base\OpenAiBasedProviderClientBase::embeddingsCollection
   */
  public function testEmbeddingsCollectionCountMismatchThrows(): void {
    $provider = $this->provider([
      $this->embeddingsResponse([
        ['object' => 'embedding', 'index' => 0, 'embedding' => [1.0, 1.0]],
      ]),
    ], FALSE);

    $this->expectException(\RuntimeException::class);
    $provider->embeddingsCollection(new EmbeddingsCollectionInput(['x', 'y']), 'text-embedding-3-small', ['skip_moderation']);
  }

  /**
   * A flagged collection is rejected with an unsafe-prompt exception.
   *
   * @covers ::moderationEndpoints
   */
  public function testModerationFlaggedThrows(): void {
    $provider = $this->provider([
      $this->moderationResponse([TRUE]),
    ], TRUE);

    $this->expectException(AiUnsafePromptException::class);
    // No skip_moderation tag: moderation runs and throws before embedding.
    $provider->embeddingsCollection(new EmbeddingsCollectionInput(['bad text']), 'text-embedding-3-small', []);
  }

  /**
   * All moderation results are checked, not only the first.
   *
   * @covers ::moderationEndpoints
   */
  public function testModerationChecksAllResults(): void {
    $provider = $this->provider([
      // First clean, second flagged.
      $this->moderationResponse([FALSE, TRUE]),
    ], TRUE);

    $this->expectException(AiUnsafePromptException::class);
    $provider->moderationEndpoints(['fine', 'not fine']);
  }

  /**
   * The skip_moderation tag bypasses moderation entirely.
   *
   * @covers \Drupal\ai\Base\OpenAiBasedProviderClientBase::embeddingsCollection
   */
  public function testSkipModerationTagBypassesModeration(): void {
    // Only an embeddings response is queued; if moderation ran it would consume
    // this (wrong) response and fail, so success proves moderation was skipped.
    $provider = $this->provider([
      $this->embeddingsResponse([
        ['object' => 'embedding', 'index' => 0, 'embedding' => [1.0, 1.0]],
      ]),
    ], TRUE);

    $output = $provider->embeddingsCollection(new EmbeddingsCollectionInput(['x']), 'text-embedding-3-small', ['skip_moderation']);
    $this->assertCount(1, $output->getNormalized());
  }

}
