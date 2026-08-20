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

  /**
   * Repairs missing group_roles field instances on membership relationships.
   */
  #[CLI\Command(name: 'group3-schema-repair:repair-group-roles-instances', aliases: ['g3srri'])]
  #[CLI\Usage(
    name: 'drush group3-schema-repair:repair-group-roles-instances',
    description: 'Create missing group_roles field instances on Group membership relationship types.',
  )]
  public function repairGroupRolesInstances(): void {
    $field_storage = $this->entityTypeManager
      ->getStorage('field_storage_config')
      ->load('group_relationship.group_roles');
    if ($field_storage === NULL) {
      throw new \RuntimeException('field.storage.group_relationship.group_roles is missing. Run group3-schema-repair:repair-group-roles-storage first.');
    }

    $relationship_types = $this->entityTypeManager
      ->getStorage('group_relationship_type')
      ->loadByProperties(['content_plugin' => 'group_membership']);
    if ($relationship_types === []) {
      throw new \RuntimeException('No group_membership relationship types were found.');
    }

    $field_config_storage = $this->entityTypeManager->getStorage('field_config');
    $form_display_storage = $this->entityTypeManager->getStorage('entity_form_display');
    $view_display_storage = $this->entityTypeManager->getStorage('entity_view_display');
    $created = [];

    foreach ($relationship_types as $relationship_type) {
      $relationship_type_id = $relationship_type->id();
      $field_id = "group_relationship.$relationship_type_id.group_roles";
      if ($field_config_storage->load($field_id) !== NULL) {
        continue;
      }

      $field_config_storage->save($field_config_storage->create([
        'field_storage' => $field_storage,
        'bundle' => $relationship_type_id,
        'label' => 'Roles',
        'settings' => [
          'handler' => 'group_type:group_role',
          'handler_settings' => [
            'group_type_id' => $relationship_type->getGroupTypeId(),
          ],
        ],
      ]));

      $default_display_id = "group_relationship.$relationship_type_id.default";
      $form_display = $form_display_storage->load($default_display_id)
        ?: $form_display_storage->create([
          'targetEntityType' => 'group_relationship',
          'bundle' => $relationship_type_id,
          'mode' => 'default',
          'status' => TRUE,
        ]);
      $form_display_storage->save($form_display->setComponent('group_roles', [
        'type' => 'options_buttons',
      ]));

      $view_display = $view_display_storage->load($default_display_id)
        ?: $view_display_storage->create([
          'targetEntityType' => 'group_relationship',
          'bundle' => $relationship_type_id,
          'mode' => 'default',
          'status' => TRUE,
        ]);
      $view_display_storage->save($view_display->setComponent('group_roles', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'settings' => [
          'link' => 0,
        ],
      ]));

      $created[] = [$field_id];
    }

    if ($created !== []) {
      $this->io()->table(['Created field instance'], $created);
    }
    $this->logger()->success(\dt(
      'Created @count missing group_roles field instances.',
      ['@count' => count($created)],
    ));
  }

  /**
   * Removes group_roles rows attached to non-membership relationships.
   */
  #[CLI\Command(name: 'group3-schema-repair:repair-group-roles-table', aliases: ['g3srrt'])]
  #[CLI\Usage(
    name: 'drush group3-schema-repair:repair-group-roles-table',
    description: 'Delete invalid group_roles field rows whose relationship is not a group_membership.',
  )]
  public function repairGroupRolesTable(): void {
    $schema = $this->database->schema();
    if (!$schema->tableExists('group_relationship__group_roles')) {
      throw new \RuntimeException('The group_relationship__group_roles table does not exist.');
    }
    if (!$schema->tableExists('group_relationship_field_data')) {
      throw new \RuntimeException('The group_relationship_field_data table does not exist.');
    }

    $query = $this->database->select('group_relationship__group_roles', 'gr');
    $query->innerJoin('group_relationship_field_data', 'r', 'r.id = gr.entity_id');
    $rows = $query
      ->fields('gr', [
        'bundle',
        'deleted',
        'entity_id',
        'revision_id',
        'langcode',
        'delta',
        'group_roles_target_id',
      ])
      ->fields('r', ['gid', 'plugin_id'])
      ->condition('r.plugin_id', 'group_membership', '<>')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    if ($rows === []) {
      $this->logger()->success(\dt('No invalid group_roles rows were found.'));
      return;
    }

    $this->io()->table(
      ['Relationship', 'Group', 'Plugin', 'Role', 'Delta'],
      array_map(static fn (array $row): array => [
        $row['entity_id'],
        $row['gid'],
        $row['plugin_id'],
        $row['group_roles_target_id'],
        $row['delta'],
      ], $rows),
    );

    foreach ($rows as $row) {
      $this->database->delete('group_relationship__group_roles')
        ->condition('bundle', $row['bundle'])
        ->condition('deleted', $row['deleted'])
        ->condition('entity_id', $row['entity_id'])
        ->condition('revision_id', $row['revision_id'])
        ->condition('langcode', $row['langcode'])
        ->condition('delta', $row['delta'])
        ->execute();
    }

    $this->logger()->success(\dt(
      'Deleted @count invalid group_roles rows from non-membership relationships.',
      ['@count' => count($rows)],
    ));
  }

}
