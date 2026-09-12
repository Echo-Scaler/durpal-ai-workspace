<?php

namespace Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

class EntityQueryCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager
  ) {
    parent::__construct();
  }

  #[CLI\Command(name: 'entity:query', aliases: ['eq'])]
  #[CLI\Argument(name: 'entity_type', description: 'The entity type to query.')]
  #[CLI\Usage(name: 'drush entity:query group', description: 'Query entities of the given type.')]
  public function query(string $entity_type): int {
    $storage = $this->entityTypeManager->getStorage($entity_type);
    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();

    if (empty($ids)) {
      $this->io()->note(sprintf('No %s entities found.', $entity_type));
      return 0;
    }

    $entities = $storage->loadMultiple($ids);
    foreach ($entities as $entity) {
      $label = method_exists($entity, 'label') ? $entity->label() : '';
      $this->io()->writeln(sprintf('%s: %s', $entity->id(), $label));
    }

    return 0;
  }

}
