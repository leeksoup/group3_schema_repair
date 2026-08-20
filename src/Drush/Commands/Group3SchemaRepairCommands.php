<?php

declare(strict_types=1);

namespace Drupal\group3_schema_repair\Drush\Commands;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityLastInstalledSchemaRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\field\FieldStorageConfigInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\user\UserInterface;
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
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {
    parent::__construct();
  }

  /**
   * Audits Group 3 membership and role state after a Group 2 upgrade.
   */
  #[CLI\Command(name: 'group3-schema-repair:audit', aliases: ['g3sra'])]
  #[CLI\Argument(name: 'uid', description: 'Optional Drupal user ID to audit against migrated LMS courses.')]
  #[CLI\Usage(
    name: 'drush group3-schema-repair:audit',
    description: 'Report stale Group 2 config, relationship role drift, and optional migrated-course membership state.',
  )]
  #[CLI\Usage(
    name: 'drush group3-schema-repair:audit 123',
    description: 'Also report user 123 effective access on migrated LMS courses when the migration map exists.',
  )]
  public function audit(?string $uid = NULL): void {
    $account = $uid !== NULL ? $this->loadUser($uid) : NULL;
    $issues = 0;

    $issues += $this->auditStaleGroupConfig();
    $issues += $this->auditGroupRoleConfig();
    $issues += $this->auditGroupRolesFieldConfig();
    $issues += $this->auditGroupRolesTable();
    $issues += $this->auditMigratedCourseMemberships($account);

    if ($issues === 0) {
      $this->logger()->success(\dt('Group 3 audit completed with no detected issues.'));
      return;
    }

    $this->logger()->warning(\dt(
      'Group 3 audit completed with @count issue groups. Review the tables above before running any repair.',
      ['@count' => $issues],
    ));
  }

  /**
   * Repairs stale Group 2 references in Views after a Group 3 upgrade.
   */
  #[CLI\Command(name: 'group3-schema-repair:repair-views', aliases: ['g3srv'])]
  #[CLI\Usage(
    name: 'drush group3-schema-repair:repair-views',
    description: 'Rewrite stale group_content references in Views config.',
  )]
  public function repairViews(): void {
    if (!$this->moduleHandler->moduleExists('views')) {
      throw new \RuntimeException('The views module is not enabled.');
    }

    $updated = [];
    foreach ($this->configFactory->listAll('views.view.') as $name) {
      $view = $this->configFactory->getEditable($name);
      $original = $view->getRawData();
      $repaired = $this->replaceGroup2ViewReferences($original);
      if ($name === 'views.view.group_members') {
        $repaired = $this->unsetNestedKey($repaired, [
          'display',
          'default',
          'display_options',
          'arguments',
          'gid',
          'default_argument_skip_url',
        ]);
      }

      if ($repaired !== $original) {
        $view->setData($repaired)->save(TRUE);
        $updated[] = [$name];
      }
    }

    $this->cacheTagsInvalidator->invalidateTags(
      array_map(static fn (array $row): string => 'config:' . $row[0], $updated),
    );

    $this->printAuditRows(
      'Repaired Group 3 Views',
      ['Config'],
      $updated,
      'No stale Group 2 View references needed repair.',
    );
    $this->logger()->success(\dt('Processed @count Views.', ['@count' => count($updated)]));
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

  /**
   * Repairs stale Group 2 active config names after a partial update.
   */
  #[CLI\Command(name: 'group3-schema-repair:repair-stale-config', aliases: ['g3srsc'])]
  #[CLI\Usage(
    name: 'drush group3-schema-repair:repair-stale-config',
    description: 'Rename or delete remaining stale group_content active config after Group update 10300 partially ran.',
  )]
  public function repairStaleConfig(): void {
    $converted = [];
    $deleted = [];
    $dependencies_repaired = [];

    foreach ($this->configFactory->listAll('group.content_type.') as $old_name) {
      $new_name = str_replace('group.content_type.', 'group.relationship_type.', $old_name);
      $this->moveConfig(
        $old_name,
        $new_name,
        static fn (array $data): array => $data,
        $converted,
        $deleted,
      );
    }

    foreach ($this->configFactory->listAll('field.storage.group_content.') as $old_name) {
      $new_name = str_replace('field.storage.group_content.', 'field.storage.group_relationship.', $old_name);
      $this->moveConfig(
        $old_name,
        $new_name,
        static function (array $data) use ($old_name, $new_name): array {
          $data['entity_type'] = 'group_relationship';
          if (($data['id'] ?? NULL) === substr($old_name, strlen('field.storage.'))) {
            $data['id'] = substr($new_name, strlen('field.storage.'));
          }
          return $data;
        },
        $converted,
        $deleted,
      );
    }

    foreach ($this->configFactory->listAll('field.field.group_content.') as $old_name) {
      $new_name = str_replace('field.field.group_content.', 'field.field.group_relationship.', $old_name);
      $this->moveConfig(
        $old_name,
        $new_name,
        static function (array $data) use ($old_name, $new_name): array {
          $data['entity_type'] = 'group_relationship';
          if (($data['id'] ?? NULL) === substr($old_name, strlen('field.field.'))) {
            $data['id'] = substr($new_name, strlen('field.field.'));
          }
          return self::replaceGroup2ConfigDependencies($data);
        },
        $converted,
        $deleted,
      );
    }

    foreach (['entity_form_display', 'entity_view_display'] as $display_key) {
      foreach ($this->configFactory->listAll("core.$display_key.group_content.") as $old_name) {
        $new_name = str_replace("core.$display_key.group_content.", "core.$display_key.group_relationship.", $old_name);
        $this->moveConfig(
          $old_name,
          $new_name,
          static function (array $data): array {
            $data['targetEntityType'] = 'group_relationship';
            if (isset($data['id'])) {
              $data['id'] = preg_replace('/^group_content\./', 'group_relationship.', (string) $data['id']);
            }
            return self::replaceGroup2ConfigDependencies($data);
          },
          $converted,
          $deleted,
        );
      }
    }

    // Other modules can depend on renamed Group config, for example a
    // Pathauto pattern that was configured for a Group 2 content type.
    foreach ($this->configFactory->listAll() as $name) {
      $config = $this->configFactory->getEditable($name);
      $original = $config->getRawData();
      $repaired = self::replaceGroup2ConfigDependencies($original);
      if ($repaired !== $original) {
        $config->setData($repaired)->save(TRUE);
        $dependencies_repaired[] = [$name];
      }
    }

    $rows = array_merge(
      array_map(static fn (array $row): array => [$row[0], $row[1], 'converted'], $converted),
      array_map(static fn (array $row): array => [$row[0], $row[1], 'deleted, replacement existed'], $deleted),
    );
    if ($rows !== []) {
      $this->io()->table(['Old config', 'New config', 'Action'], $rows);
    }
    if ($dependencies_repaired !== []) {
      $this->io()->table(['Updated config dependency'], $dependencies_repaired);
    }

    $this->logger()->success(\dt(
      'Processed @converted stale config conversions, @deleted stale config deletions, and @dependencies repaired config dependencies.',
      [
        '@converted' => count($converted),
        '@deleted' => count($deleted),
        '@dependencies' => count($dependencies_repaired),
      ],
    ));
  }

  /**
   * Moves an old config object to its Group 3 name, or deletes duplicate stale config.
   */
  private function moveConfig(
    string $old_name,
    string $new_name,
    callable $normalizer,
    array &$converted,
    array &$deleted,
  ): void {
    $old_config = $this->configFactory->getEditable($old_name);
    if ($old_config->isNew()) {
      return;
    }

    $new_config = $this->configFactory->getEditable($new_name);
    if ($new_config->isNew()) {
      $new_config->setData($normalizer($old_config->getRawData()))->save(TRUE);
      $converted[] = [$old_name, $new_name];
    }
    else {
      $deleted[] = [$old_name, $new_name];
    }

    $old_config->delete();
  }

  /**
   * Rewrites config dependencies from Group 2 names to Group 3 names.
   */
  private static function replaceGroup2ConfigDependencies(array $data): array {
    if (!empty($data['dependencies']['config']) && is_array($data['dependencies']['config'])) {
      $data['dependencies']['config'] = array_map(static function ($dependency_name): string {
        return str_replace([
          'group.content_type.',
          'field.storage.group_content.',
          'field.field.group_content.',
        ], [
          'group.relationship_type.',
          'field.storage.group_relationship.',
          'field.field.group_relationship.',
        ], (string) $dependency_name);
      }, $data['dependencies']['config']);
    }
    return $data;
  }

  /**
   * Reports active config names and values that still mention Group 2 IDs.
   */
  private function auditStaleGroupConfig(): int {
    $stale_prefixes = [
      'core.entity_form_display.group_content.',
      'core.entity_view_display.group_content.',
      'field.field.group_content.',
      'field.storage.group_content.',
      'group.content_type.',
    ];
    $rows = [];
    foreach ($stale_prefixes as $prefix) {
      foreach ($this->configFactory->listAll($prefix) as $name) {
        $rows[] = [$name, 'stale config name'];
      }
    }

    foreach ($this->configFactory->listAll() as $name) {
      $raw = $this->configFactory->get($name)->getRawData();
      $dependencies = $raw['dependencies']['config'] ?? [];
      foreach ($dependencies as $dependency) {
        if (str_starts_with((string) $dependency, 'group.content_type.')) {
          $rows[] = [$name, 'depends on group.content_type'];
        }
      }

      if (!str_starts_with($name, 'views.view.')) {
        continue;
      }
      if ($this->arrayContainsString($raw, 'group_content')) {
        $rows[] = [$name, 'contains group_content'];
      }
      if ($this->arrayContainsString($raw, 'group.content_type.')) {
        $rows[] = [$name, 'contains group.content_type'];
      }
    }

    $this->printAuditRows(
      'Stale Group 2 config references',
      ['Config', 'Problem'],
      $rows,
      'No stale Group 2 config names or View references detected.',
    );

    return $rows === [] ? 0 : 1;
  }

  /**
   * Reports malformed Group role config.
   */
  private function auditGroupRoleConfig(): int {
    $required = ['id', 'label', 'weight', 'admin', 'scope', 'group_type', 'permissions'];
    $stale = ['audience', 'internal', 'permissions_ui'];
    $valid_scopes = ['outsider', 'insider', 'individual'];
    $rows = [];

    foreach ($this->configFactory->listAll('group.role.') as $name) {
      $data = $this->configFactory->get($name)->getRawData();
      $problems = [];

      foreach ($required as $key) {
        if (!array_key_exists($key, $data)) {
          $problems[] = "missing $key";
        }
      }
      foreach ($stale as $key) {
        if (array_key_exists($key, $data)) {
          $problems[] = "stale $key";
        }
      }
      if (isset($data['scope']) && !in_array($data['scope'], $valid_scopes, TRUE)) {
        $problems[] = 'invalid scope';
      }
      if (
        isset($data['scope'])
        && in_array($data['scope'], ['outsider', 'insider'], TRUE)
        && empty($data['global_role'])
      ) {
        $problems[] = 'missing global_role';
      }
      if (isset($data['permissions']) && !is_array($data['permissions'])) {
        $problems[] = 'permissions not sequence';
      }

      if ($problems !== []) {
        $rows[] = [
          $data['id'] ?? substr($name, strlen('group.role.')),
          $data['group_type'] ?? '',
          $data['scope'] ?? '',
          $data['global_role'] ?? '',
          implode(', ', $problems),
        ];
      }
    }

    $this->printAuditRows(
      'Group role config shape',
      ['Role', 'Group type', 'Scope', 'Global role', 'Problem'],
      $rows,
      'All Group role config uses the Group 3 shape.',
    );

    return $rows === [] ? 0 : 1;
  }

  /**
   * Reports missing field storage/instances for membership role references.
   */
  private function auditGroupRolesFieldConfig(): int {
    $rows = [];
    $field_storage = $this->entityTypeManager
      ->getStorage('field_storage_config')
      ->load('group_relationship.group_roles');
    if ($field_storage === NULL) {
      $rows[] = ['field.storage.group_relationship.group_roles', 'missing'];
    }
    else {
      if ($field_storage->getTargetEntityTypeId() !== 'group_relationship') {
        $rows[] = ['field.storage.group_relationship.group_roles', 'entity_type is not group_relationship'];
      }
      if ($field_storage->getSetting('target_type') !== 'group_role') {
        $rows[] = ['field.storage.group_relationship.group_roles', 'target_type is not group_role'];
      }
    }

    $relationship_type_storage = $this->entityTypeManager->getStorage('group_relationship_type');
    $field_config_storage = $this->entityTypeManager->getStorage('field_config');
    $relationship_types = $relationship_type_storage->loadByProperties([
      'content_plugin' => 'group_membership',
    ]);
    if ($relationship_types === []) {
      $rows[] = ['group_relationship_type:*group_membership', 'no membership relationship types found'];
    }

    foreach ($relationship_types as $relationship_type) {
      $field_id = 'group_relationship.' . $relationship_type->id() . '.group_roles';
      if ($field_config_storage->load($field_id) === NULL) {
        $rows[] = [$field_id, 'missing field instance'];
      }
    }

    $this->printAuditRows(
      'Group membership role field config',
      ['Item', 'Problem'],
      $rows,
      'Membership role field storage and field instances look present.',
    );

    return $rows === [] ? 0 : 1;
  }

  /**
   * Reports relationship-role table drift and orphan rows.
   */
  private function auditGroupRolesTable(): int {
    $schema = $this->database->schema();
    $rows = [];
    if (!$schema->tableExists('group_relationship__group_roles')) {
      $rows[] = ['group_relationship__group_roles', 'table missing', ''];
      $this->printAuditRows(
        'Group relationship role table',
        ['Item', 'Problem', 'Count'],
        $rows,
        'Group relationship role table looks consistent.',
      );
      return 1;
    }

    if (!$schema->tableExists('group_relationship_field_data')) {
      $rows[] = ['group_relationship_field_data', 'table missing', ''];
    }
    else {
      $orphan_query = $this->database->select('group_relationship__group_roles', 'gr');
      $orphan_query->leftJoin('group_relationship_field_data', 'r', 'r.id = gr.entity_id');
      $orphan_relationships = (int) $orphan_query
        ->fields('gr', ['entity_id'])
        ->isNull('r.id')
        ->countQuery()
        ->execute()
        ->fetchField();
      if ($orphan_relationships > 0) {
        $rows[] = ['group_roles.entity_id', 'missing relationship row', (string) $orphan_relationships];
      }

      $wrong_plugin_query = $this->database->select('group_relationship__group_roles', 'gr');
      $wrong_plugin_query->innerJoin('group_relationship_field_data', 'r', 'r.id = gr.entity_id');
      $wrong_plugin = (int) $wrong_plugin_query
        ->fields('gr', ['entity_id'])
        ->condition('r.plugin_id', 'group_membership', '<>')
        ->countQuery()
        ->execute()
        ->fetchField();
      if ($wrong_plugin > 0) {
        $rows[] = ['group_roles.entity_id', 'relationship is not group_membership', (string) $wrong_plugin];
      }
    }

    $target_ids = $this->database->select('group_relationship__group_roles', 'gr')
      ->distinct()
      ->fields('gr', ['group_roles_target_id'])
      ->execute()
      ->fetchCol();
    $role_storage = $this->entityTypeManager->getStorage('group_role');
    foreach ($target_ids as $target_id) {
      if ($role_storage->load($target_id) === NULL) {
        $rows[] = ['group_roles_target_id', 'missing group_role ' . $target_id, ''];
      }
    }

    $this->printAuditRows(
      'Group relationship role table',
      ['Item', 'Problem', 'Count'],
      $rows,
      'Group relationship role table looks consistent.',
    );

    return $rows === [] ? 0 : 1;
  }

  /**
   * Reports migrated LMS course membership and optional user access.
   */
  private function auditMigratedCourseMemberships(?UserInterface $account): int {
    $course_ids = $this->migratedCourseIds();
    if ($course_ids === []) {
      $this->logger()->warning(\dt('No migrated LMS courses were found in migrate_map_anu_to_lms_node_courses.'));
      return $this->database->schema()->tableExists('migrate_map_anu_to_lms_node_courses') ? 1 : 0;
    }

    $rows = [];
    $issues = 0;
    $group_storage = $this->entityTypeManager->getStorage('group');
    foreach ($group_storage->loadMultiple($course_ids) as $course) {
      if (!$course instanceof GroupInterface || $course->bundle() !== 'lms_course') {
        continue;
      }

      $membership_count = $this->countCourseMemberships((int) $course->id());
      $role_ref_count = $this->countCourseMembershipRoleReferences((int) $course->id());
      $owner = $course->getOwner();
      $owner_membership = $owner instanceof UserInterface ? $course->getMember($owner) : FALSE;
      $owner_roles = $owner_membership ? implode(',', array_keys($owner_membership->getRoles())) : '';
      $problem = [];
      if ($membership_count === 0) {
        $problem[] = 'no memberships';
      }
      if (!$owner_membership) {
        $problem[] = 'owner not member';
      }
      if (!$course->access('update', $owner)) {
        $problem[] = 'owner cannot update';
      }

      $row = [
        'course' => $course->id(),
        'memberships' => (string) $membership_count,
        'role_refs' => (string) $role_ref_count,
        'owner_member' => $owner_membership ? 'yes' : 'no',
        'owner_roles' => $owner_roles,
        'owner_update' => $course->access('update', $owner) ? 'yes' : 'no',
        'problem' => implode(', ', $problem),
      ];

      if ($account instanceof UserInterface) {
        $membership = $course->getMember($account);
        $row += [
          'user_member' => $membership ? 'yes' : 'no',
          'user_roles' => $membership ? implode(',', array_keys($membership->getRoles())) : '',
          'user_view' => $course->access('view', $account) ? 'yes' : 'no',
          'user_take' => $course->access('take', $account) ? 'yes' : 'no',
          'user_update' => $course->access('update', $account) ? 'yes' : 'no',
        ];
      }

      if ($problem !== []) {
        $issues = 1;
      }
      $rows[] = $row;
    }

    $headers = ['Course', 'Memberships', 'Role refs', 'Owner member', 'Owner roles', 'Owner update', 'Problem'];
    if ($account instanceof UserInterface) {
      $headers = array_merge($headers, ['User member', 'User roles', 'User view', 'User take', 'User update']);
    }
    $this->io()->section('Migrated LMS course membership');
    $this->io()->table($headers, array_map('array_values', $rows));

    return $issues;
  }

  /**
   * Counts group membership relationships on a course.
   */
  private function countCourseMemberships(int $course_id): int {
    if (!$this->database->schema()->tableExists('group_relationship_field_data')) {
      return 0;
    }

    return (int) $this->database->select('group_relationship_field_data', 'r')
      ->condition('r.gid', $course_id)
      ->condition('r.plugin_id', 'group_membership')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Counts explicit group role references on course memberships.
   */
  private function countCourseMembershipRoleReferences(int $course_id): int {
    $schema = $this->database->schema();
    if (
      !$schema->tableExists('group_relationship_field_data')
      || !$schema->tableExists('group_relationship__group_roles')
    ) {
      return 0;
    }

    $query = $this->database->select('group_relationship_field_data', 'r');
    $query->innerJoin('group_relationship__group_roles', 'gr', 'gr.entity_id = r.id');
    return (int) $query
      ->condition('r.gid', $course_id)
      ->condition('r.plugin_id', 'group_membership')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Prints audit rows or a short OK message.
   */
  private function printAuditRows(string $title, array $headers, array $rows, string $ok_message): void {
    $this->io()->section($title);
    if ($rows === []) {
      $this->io()->writeln($ok_message);
      return;
    }

    $this->io()->table($headers, $rows);
  }

  /**
   * Recursively checks whether a config array contains a string fragment.
   */
  private function arrayContainsString(mixed $value, string $needle): bool {
    if (is_string($value)) {
      return str_contains($value, $needle);
    }
    if (!is_array($value)) {
      return FALSE;
    }

    foreach ($value as $key => $item) {
      if (
        (is_string($key) && str_contains($key, $needle))
        || $this->arrayContainsString($item, $needle)
      ) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Replaces Group 2 View references with Group 3 equivalents.
   */
  private function replaceGroup2ViewReferences(array $data): array {
    $search = ['group_content_plugins', 'group.content_type.', 'group_content'];
    $replace = ['group_relation_plugins', 'group.relationship_type.', 'group_relationship'];
    $new_data = [];

    foreach ($data as $key => $value) {
      $new_key = is_string($key) ? str_replace($search, $replace, $key) : $key;

      if (is_string($value)) {
        $new_data[$new_key] = str_replace($search, $replace, $value);
      }
      elseif (is_array($value)) {
        $new_data[$new_key] = $this->replaceGroup2ViewReferences($value);
      }
      else {
        $new_data[$new_key] = $value;
      }
    }

    return $new_data;
  }

  /**
   * Unsets a nested array key when every parent key exists.
   */
  private function unsetNestedKey(array $data, array $parents): array {
    $key = array_shift($parents);
    if ($key === NULL) {
      return $data;
    }
    if ($parents === []) {
      unset($data[$key]);
      return $data;
    }
    if (!isset($data[$key]) || !is_array($data[$key])) {
      return $data;
    }

    $data[$key] = $this->unsetNestedKey($data[$key], $parents);
    return $data;
  }

  /**
   * Loads a user account from a Drush command argument.
   */
  private function loadUser(string $uid): UserInterface {
    if (!ctype_digit($uid) || (int) $uid <= 0) {
      throw new \InvalidArgumentException('The uid argument must be a positive integer.');
    }

    $account = $this->entityTypeManager->getStorage('user')->load((int) $uid);
    if (!$account instanceof UserInterface) {
      throw new \InvalidArgumentException(sprintf('User %s was not found.', $uid));
    }

    return $account;
  }

  /**
   * Returns destination course IDs from the course migration map, if present.
   */
  private function migratedCourseIds(): array {
    $map_table = 'migrate_map_anu_to_lms_node_courses';
    if (!$this->database->schema()->tableExists($map_table)) {
      return [];
    }

    $ids = $this->database->select($map_table, 'm')
      ->fields('m', ['destid1'])
      ->isNotNull('destid1')
      ->execute()
      ->fetchCol();

    return array_values(array_unique(array_map('intval', $ids)));
  }

}
