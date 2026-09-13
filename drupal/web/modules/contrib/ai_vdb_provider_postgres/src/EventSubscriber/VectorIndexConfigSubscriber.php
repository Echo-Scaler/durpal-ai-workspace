<?php

namespace Drupal\ai_vdb_provider_postgres\EventSubscriber;

use Drupal\ai\Enum\VdbSimilarityMetrics;
use Drupal\ai_vdb_provider_postgres\Enum\VectorIndexStrategy;
use Drupal\ai_vdb_provider_postgres\Exception\VectorIndexException;
use Drupal\ai_vdb_provider_postgres\PostgresPgvectorClient;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Component\Plugin\PluginManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Applies the configured vector index strategy after a Search API server save.
 *
 * Because the upstream ai_search module does not call
 * AiVdbProviderInterface::submitSettingsForm() during form submission,
 * the index strategy must be applied at config-save time instead.
 */
class VectorIndexConfigSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a VectorIndexConfigSubscriber.
   *
   * @param \Drupal\Component\Plugin\PluginManagerInterface $vdbProviderManager
   *   The VDB provider plugin manager.
   * @param \Drupal\ai_vdb_provider_postgres\PostgresPgvectorClient $pgvectorClient
   *   The Postgres pgvector client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger channel factory.
   */
  public function __construct(
    protected readonly PluginManagerInterface $vdbProviderManager,
    protected readonly PostgresPgvectorClient $pgvectorClient,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ConfigEvents::SAVE => ['onConfigSave', -10],
    ];
  }

  /**
   * Applies the vector index strategy when a Search API server config is saved.
   *
   * The priority is set to -10 so that the upstream ai_search subscriber
   * (which creates the collection) runs first, ensuring the table exists
   * before we attempt to create or drop the index.
   *
   * @param \Drupal\Core\Config\ConfigCrudEvent $event
   *   The config entity save event.
   */
  public function onConfigSave(ConfigCrudEvent $event): void {
    $config = $event->getConfig();

    if (!str_starts_with($config->getName(), 'search_api.server.')) {
      return;
    }

    $data = $config->getRawData();

    if (
      !isset($data['backend']) ||
      $data['backend'] !== 'search_api_ai_search' ||
      !isset($data['backend_config']['database']) ||
      $data['backend_config']['database'] !== 'postgres'
    ) {
      return;
    }

    $backendConfig = $data['backend_config'];
    $collectionName = $backendConfig['database_settings']['collection'] ?? NULL;
    $databaseName = $backendConfig['database_settings']['database_name'] ?? NULL;
    $strategyValue = $backendConfig['database_settings']['vector_index_strategy']
      ?? VectorIndexStrategy::None->value;
    $metricValue = $backendConfig['database_settings']['metric']
      ?? VdbSimilarityMetrics::CosineSimilarity->value;

    if (!$collectionName || !$databaseName) {
      return;
    }

    $strategy = VectorIndexStrategy::tryFrom($strategyValue) ?? VectorIndexStrategy::None;
    $metric = VdbSimilarityMetrics::tryFrom($metricValue) ?? VdbSimilarityMetrics::CosineSimilarity;

    try {
      /** @var \Drupal\ai_vdb_provider_postgres\Plugin\VdbProvider\PostgresProvider $provider */
      $provider = $this->vdbProviderManager->createInstance('postgres');
      $connection = $provider->getConnection($databaseName);
      if (!$connection) {
        return;
      }

      $collections = $provider->getCollections($databaseName);
      if (!is_array($collections) || !in_array($collectionName, $collections, strict: TRUE)) {
        // Collection does not exist yet; nothing to index.
        return;
      }

      $this->pgvectorClient->ensureVectorIndex($collectionName, $strategy, $connection, $metric);
    }
    catch (VectorIndexException $e) {
      $this->loggerFactory->get('ai_vdb_provider_postgres')->error(
        'Failed to apply vector index strategy "@strategy" on collection "@collection": @message',
        [
          '@strategy' => $strategy->value,
          '@collection' => $collectionName,
          '@message' => $e->getMessage(),
        ],
      );
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('ai_vdb_provider_postgres')->warning(
        'Vector index strategy could not be applied on "@collection": @message',
        [
          '@collection' => $collectionName,
          '@message' => $e->getMessage(),
        ],
      );
    }
  }

}
