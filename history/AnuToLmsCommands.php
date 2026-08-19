<?php

declare(strict_types=1);

namespace Drupal\anu_to_lms_migrate\Drush\Commands;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigInstallerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\user\UserInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Anu to LMS migration maintenance.
 */
final class AnuToLmsCommands extends DrushCommands {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ConfigInstallerInterface $configInstaller,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct();
  }

  /**
   * Grants a user teacher access to migrated LMS courses.
   */
  #[CLI\Command(name: 'anu-to-lms:make-teacher', aliases: ['atlmt'])]
  #[CLI\Argument(name: 'uid', description: 'Drupal user ID to make a teacher on migrated LMS courses.')]
  #[CLI\Usage(name: 'drush anu-to-lms:make-teacher 123', description: 'Grant user 123 LMS teacher access to all migrated courses.')]
  public function makeTeacher(string $uid): void {
    $account = $this->loadUser($uid);
    $this->assertTeacherRoleConfiguration();
    $changed_user = FALSE;

    if (!$account->hasRole('lms_teacher')) {
      $account->addRole('lms_teacher');
      $account->save();
      $changed_user = TRUE;
    }

    $course_ids = $this->migratedCourseIds();
    if ($course_ids === []) {
      $this->logger()->warning(\dt('No migrated LMS courses were found in migrate_map_anu_to_lms_node_courses.'));
      return;
    }

    $rows = [];
    $added_memberships = 0;
    $group_storage = $this->entityTypeManager->getStorage('group');
    foreach ($group_storage->loadMultiple($course_ids) as $course) {
      if (!$course instanceof GroupInterface || $course->bundle() !== 'lms_course') {
        continue;
      }

      $membership = $course->getMember($account);
      if (!$membership) {
        $course->addMember($account);
        $membership = $course->getMember($account);
        $added_memberships++;
      }

      $roles = $membership ? array_keys($membership->getRoles()) : [];
      $rows[] = [
        'course' => $course->id(),
        'member' => $membership ? 'yes' : 'no',
        'user_teacher' => $account->hasRole('lms_teacher') ? 'yes' : 'no',
        'group_roles' => implode(',', $roles),
        'view' => $course->access('view', $account) ? 'yes' : 'no',
        'take' => $course->access('take', $account) ? 'yes' : 'no',
        'update' => $course->access('update', $account) ? 'yes' : 'no',
      ];
    }

    $this->cacheTagsInvalidator->invalidateTags([
      'config:group.role.lms_course-teacher',
      'group_relationship_list:plugin:group_membership',
    ]);

    if ($rows !== []) {
      $this->io()->table(
        ['Course', 'Member', 'User teacher', 'Group roles', 'View', 'Take', 'Update'],
        array_map('array_values', $rows),
      );
    }

    $this->logger()->success(\dt(
      'Processed @count migrated courses for user @uid. Added @memberships memberships. Global lms_teacher role @role.',
      [
        '@count' => count($rows),
        '@uid' => $account->id(),
        '@memberships' => $added_memberships,
        '@role' => $changed_user ? 'added' : 'already existed',
      ],
    ));
  }

  /**
   * Repairs LMS Classes student management for migrated LMS courses.
   */
  #[CLI\Command(name: 'anu-to-lms:repair-students', aliases: ['atlrs'])]
  #[CLI\Option(
    name: 'all-courses',
    description: 'Repair every LMS course, not only Anu-migrated courses.',
  )]
  #[CLI\Option(name: 'course-id', description: 'Repair one LMS course ID.')]
  #[CLI\Usage(
    name: 'drush anu-to-lms:repair-students',
    description: 'Grant LMS Classes permissions and create missing default classes.',
  )]
  #[CLI\Usage(
    name: 'drush anu-to-lms:repair-students --course-id=13',
    description: 'Create a missing default class for LMS course 13.',
  )]
  #[CLI\Usage(
    name: 'drush anu-to-lms:repair-students --all-courses',
    description: 'Create missing default classes for all LMS courses.',
  )]
  public function repairStudents(
    array $options = [
      'all-courses' => FALSE,
      'course-id' => NULL,
    ],
  ): void {
    if (!$this->moduleHandler->moduleExists('lms_classes')) {
      throw new \RuntimeException(
        'The lms_classes module is not enabled. Run drush en lms_classes -y first.',
      );
    }

    $this->configInstaller->installDefaultConfig('module', 'lms_classes');
    $updated_roles = $this->grantCourseStudentPermissions();
    $created_classes = $this->createMissingCourseClasses(
      $this->studentRepairCourseIds($options),
    );

    $this->cacheTagsInvalidator->invalidateTags([
      'config:group.role.lms_course-teacher',
      'group_relationship_list:plugin:lms_classes',
      'group_relationship_list:plugin:group_membership',
    ]);

    $this->logger()->success(\dt(
      'Updated @roles LMS course roles and created @classes default classes for migrated courses.',
      [
        '@roles' => $updated_roles,
        '@classes' => $created_classes,
      ],
    ));
  }

  /**
   * Audits Group 3 membership and role state after a Group 2 upgrade.
   */
  #[CLI\Command(name: 'anu-to-lms:audit-group3', aliases: ['atlag3'])]
  #[CLI\Argument(name: 'uid', description: 'Optional Drupal user ID to audit against migrated LMS courses.')]
  #[CLI\Usage(
    name: 'drush anu-to-lms:audit-group3',
    description: 'Report stale Group 2 config, relationship role drift, and migrated-course membership state.',
  )]
  #[CLI\Usage(
    name: 'drush anu-to-lms:audit-group3 123',
    description: 'Also report user 123 effective access on each migrated course.',
  )]
  public function auditGroup3(?string $uid = NULL): void {
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
  #[CLI\Command(name: 'anu-to-lms:repair-group3-views', aliases: ['atlrg3v'])]
  #[CLI\Usage(
    name: 'drush anu-to-lms:repair-group3-views',
    description: 'Rewrite stale group_content references in Views config.',
  )]
  public function repairGroup3Views(): void {
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
   * Confirms the LMS synchronized teacher role configuration exists.
   */
  private function assertTeacherRoleConfiguration(): void {
    if ($this->entityTypeManager->getStorage('user_role')->load('lms_teacher') === NULL) {
      throw new \RuntimeException('The lms_teacher user role does not exist. Run drush updb -y and drush cr first.');
    }
    if ($this->entityTypeManager->getStorage('group_role')->load('lms_course-teacher') === NULL) {
      throw new \RuntimeException('The lms_course-teacher group role does not exist. Run drush updb -y and drush cr first.');
    }
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

    foreach ($this->configFactory->listAll('views.view.') as $name) {
      $raw = $this->configFactory->get($name)->getRawData();
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
      return 1;
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
   * Grants course roles the permissions required for the Students tab.
   */
  private function grantCourseStudentPermissions(): int {
    $role_storage = $this->entityTypeManager->getStorage('group_role');
    $roles = $role_storage->loadByProperties([
      'group_type' => 'lms_course',
      'scope' => ['insider', 'individual'],
    ]);

    $permissions = [
      'add students',
      'view students',
      'create lms_classes relationship',
      'delete own lms_classes relationship',
      'update any lms_classes relationship',
      'update own lms_classes relationship',
      'view lms_classes relationship',
    ];

    $updated = 0;
    foreach ($roles as $role) {
      $changed = FALSE;
      foreach ($permissions as $permission) {
        if (!$role->hasPermission($permission)) {
          $role->grantPermission($permission);
          $changed = TRUE;
        }
      }
      if ($changed) {
        $role->save();
        $updated++;
      }
    }

    return $updated;
  }

  /**
   * Creates a default class for migrated LMS courses that have no classes.
   */
  private function createMissingCourseClasses(?array $course_ids = NULL): int {
    $group_storage = $this->entityTypeManager->getStorage('group');
    $relationship_type_storage = $this->entityTypeManager->getStorage('group_relationship_type');

    if (
      $relationship_type_storage->load('lms_course-lms_classes') === NULL
      || $this->entityTypeManager->getStorage('group_type')->load('lms_class') === NULL
    ) {
      return 0;
    }

    $course_ids ??= $this->migratedCourseIds();
    if ($course_ids === []) {
      return 0;
    }

    $created = 0;
    foreach ($group_storage->loadMultiple($course_ids) as $course) {
      if (!$course instanceof Course || $course->getRelationships('lms_classes') !== []) {
        continue;
      }

      $class = $group_storage->create([
        'type' => 'lms_class',
        'label' => $course->label() . ' class',
        'uid' => $course->getOwnerId(),
      ]);
      $class->save();

      if ($class instanceof GroupInterface && !$class->getMember($class->getOwner())) {
        $class_type = $class->getGroupType();
        $values = [];
        if ($class_type->creatorMustCompleteMembership()) {
          $values['group_roles'] = $class_type->getCreatorRoleIds();
        }
        $class->addMember($class->getOwner(), $values);
      }

      $course->addRelationship($class, 'lms_classes');
      $created++;
    }

    return $created;
  }

  /**
   * Returns the LMS course IDs selected by repair-students options.
   */
  private function studentRepairCourseIds(array $options): ?array {
    $course_id = $options['course-id'] ?? NULL;
    if ($course_id !== NULL) {
      if (!ctype_digit((string) $course_id) || (int) $course_id <= 0) {
        throw new \InvalidArgumentException('The --course-id option must be a positive integer.');
      }
      return [(int) $course_id];
    }

    if (!empty($options['all-courses'])) {
      return $this->allLmsCourseIds();
    }

    return NULL;
  }

  /**
   * Returns IDs for every LMS course group.
   */
  private function allLmsCourseIds(): array {
    $ids = $this->entityTypeManager->getStorage('group')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'lms_course')
      ->execute();

    return array_values(array_map('intval', $ids));
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
   * Returns destination course IDs from the course migration map.
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
