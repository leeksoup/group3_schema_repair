<?php

declare(strict_types=1);

namespace Drupal\group3_schema_repair\Drush\Commands;

use Drupal\Core\Entity\EntityLastInstalledSchemaRepositoryInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\field\FieldStorageConfigInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for interrupted Group 2 to Group 3 updates.
 */
final class Group3SchemaRepairCommands extends DrushCommands {

  public function __construct(
    private readonly EntityLastInstalledSchemaRepositoryInterface $lastInstalledSchemaRepository,
    private readonly KeyValueFactoryInterface $keyValueFactory,
  ) {
    parent::__construct();
  }

  /**
   * Repairs missing Group 3 installed field-storage definitions.
   */
  #[CLI\Command(name: 'group3-schema-repair:repair-repository', aliases: ['g3srr'])]
  #[CLI\Usage(
    name: 'drush group3-schema-repair:repair-repository',
    description: 'Copy stale group_content installed field definitions to group_relationship before Group update 10305.',
  )]
  public function repairRepository(): void {
    $old_definitions = $this->lastInstalledSchemaRepository
      ->getLastInstalledFieldStorageDefinitions('group_content');
    $new_definitions = $this->lastInstalledSchemaRepository
      ->getLastInstalledFieldStorageDefinitions('group_relationship');

    if ($old_definitions === []) {
      throw new \RuntimeException('No group_content installed field-storage definitions were found to copy.');
    }
    if ($new_definitions !== []) {
      throw new \RuntimeException('group_relationship installed field-storage definitions already exist. Refusing to overwrite them.');
    }

    $installed_storage_schema = $this->keyValueFactory->get('entity.storage_schema.sql');
    $copied = [];
    foreach ($old_definitions as $definition) {
      $copy = clone $definition;
      if ($copy instanceof BaseFieldDefinition) {
        $copy->setTargetEntityTypeId('group_relationship');
      }
      elseif ($copy instanceof FieldStorageConfigInterface) {
        $copy->set('entity_type', 'group_relationship');
        $copy->set('id', str_replace('group_content.', 'group_relationship.', $copy->id()));
      }
      else {
        throw new \RuntimeException('Found a group_content field-storage definition with an unsupported class: ' . get_debug_type($copy));
      }

      $schema = $installed_storage_schema->get('group_content.field_schema_data.' . $copy->getName());
      if ($schema !== NULL) {
        $installed_storage_schema->set('group_relationship.field_schema_data.' . $copy->getName(), $schema);
      }

      $this->lastInstalledSchemaRepository->setLastInstalledFieldStorageDefinition($copy);
      $copied[] = [
        $copy->getName(),
        $copy->getType(),
        $copy instanceof FieldStorageConfigInterface ? $copy->id() : 'base field',
      ];
    }

    $this->io()->table(['Field', 'Type', 'Installed definition'], $copied);
    $this->logger()->success(\dt(
      'Copied @count installed Group field-storage definitions from group_content to group_relationship. Run drush updb -y next.',
      ['@count' => count($copied)],
    ));
  }

}
