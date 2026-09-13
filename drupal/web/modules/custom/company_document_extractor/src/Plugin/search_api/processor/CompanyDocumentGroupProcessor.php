<?php

namespace Drupal\company_document_extractor\Plugin\search_api\processor;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\media\MediaInterface;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;

/**
 * Adds company_id and group_id properties to media datasource for multi-tenant isolation.
 *
 * @SearchApiProcessor(
 *   id = "company_document_group",
 *   label = @Translation("Company Document Group (Tenant ID)"),
 *   description = @Translation("Extracts company_id and group_id from Group relationship for multi-tenant isolation."),
 *   stages = {
 *     "add_properties" = 0,
 *   }
 * )
 */
class CompanyDocumentGroupProcessor extends ProcessorPluginBase {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      $definition_company = [
        'label' => $this->t('Company ID'),
        'description' => $this->t('The ID of the company (group) this document belongs to.'),
        'type' => 'integer',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['company_id'] = new ProcessorProperty($definition_company);

      $definition_group = [
        'label' => $this->t('Group ID'),
        'description' => $this->t('The ID of the group this document belongs to.'),
        'type' => 'integer',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['group_id'] = new ProcessorProperty($definition_group);
    }

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item) {
    try {
      $entity = $item->getOriginalObject()->getValue();
      if ($entity instanceof MediaInterface) {
        $relationships = \Drupal::entityTypeManager()->getStorage('group_relationship')->loadByProperties([
          'entity_id' => $entity->id(),
        ]);
        foreach ($relationships as $rel) {
          if ($rel instanceof \Drupal\group\Entity\GroupRelationshipInterface && str_starts_with($rel->getPluginId(), 'group_media:')) {
            $gid = (int) $rel->getGroupId();
            $fields = $item->getFields();
            if (isset($fields['company_id'])) {
              $fields['company_id']->addValue($gid);
            }
            if (isset($fields['group_id'])) {
              $fields['group_id']->addValue($gid);
            }
            break;
          }
        }
      }
    }
    catch (\Throwable $e) {
      \Drupal::logger('company_document_extractor')->error('Error resolving company/group ID for item @id: @msg', [
        '@id' => $item->getId(),
        '@msg' => $e->getMessage(),
      ]);
    }
  }

}
