<?php

declare(strict_types=1);

namespace Drupal\group3_schema_repair\Drush\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityLastInstalledSchemaRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
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

  /**
   * Repairs the missing active group_roles field storage config.
   */
  #[CLI\Command(name: 'group3-schema-repair:repair-group-roles-storage', aliases: ['g3srrs'])]
  #[CLI\Usage(
    name: 'drush group3-schema-repair:repair-group-roles-storage',
    description: 'Create missing active field.storage.group_relationship.group_roles config after Group 3 schema updates.',
  )]
  public function repairGroupRolesStorage(): void {
    $storage_config = $this->configFactory
      ->get('field.storage.group_relationship.group_roles');
    if (!$storage_config->isNew()) {
      $this->logger()->success(\dt('field.storage.group_relationship.group_roles already exists.'));
      return;
    }

    if (!$this->entityTypeManager->hasDefinition('group_relationship')) {
      throw new \RuntimeException('The group_relationship entity type is not available. Finish enabling/updating Group first.');
    }
    if (!$this->entityTypeManager->hasDefinition('group_role')) {
      throw new \RuntimeException('The group_role entity type is not available. Finish enabling/updating Group first.');
    }

    $installed_definitions = $this->lastInstalledSchemaRepository
      ->getLastInstalledFieldStorageDefinitions('group_relationship');
    if (!isset($installed_definitions['group_roles'])) {
      throw new \RuntimeException('The installed schema repository has no group_relationship.group_roles definition. Run group3-schema-repair:repair-repository before this command.');
    }

    if (!$this->database->schema()->tableExists('group_relationship__group_roles')) {
      throw new \RuntimeException('The group_relationship__group_roles table does not exist. Stop here; this database still needs the Group table migration from update 10300.');
    }

    $this->configFactory->getEditable('field.storage.group_relationship.group_roles')
      ->setData([
        'langcode' => 'en',
        'status' => TRUE,
        'dependencies' => [
          'module' => [
            'group',
            'options',
          ],
        ],
        'id' => 'group_relationship.group_roles',
        'field_name' => 'group_roles',
        'entity_type' => 'group_relationship',
        'type' => 'entity_reference',
        'settings' => [
          'target_type' => 'group_role',
        ],
        'module' => 'core',
        'locked' => TRUE,
        'cardinality' => -1,
        'translatable' => FALSE,
        'indexes' => [],
        'persist_with_no_fields' => TRUE,
        'custom_storage' => FALSE,
      ])
      ->save(TRUE);

    $this->logger()->success(\dt(
      'Created field.storage.group_relationship.group_roles active config. Run drush en anu_to_lms_migrate -y again.',
    ));
  }

}
