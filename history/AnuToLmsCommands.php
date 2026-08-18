<?php

declare(strict_types=1);

namespace Drupal\anu_to_lms_migrate\Drush\Commands;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
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
  #[CLI\Usage(
    name: 'drush anu-to-lms:repair-students',
    description: 'Grant LMS Classes permissions and create missing default classes for migrated courses.',
  )]
  public function repairStudents(): void {
    if (!$this->moduleHandler->moduleExists('lms_classes')) {
      throw new \RuntimeException(
        'The lms_classes module is not enabled. Run drush en lms_classes -y first.',
      );
    }

    $this->configInstaller->installDefaultConfig('module', 'lms_classes');
    $updated_roles = $this->grantCourseStudentPermissions();
    $created_classes = $this->createMissingCourseClasses();

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
  private function createMissingCourseClasses(): int {
    $group_storage = $this->entityTypeManager->getStorage('group');
    $relationship_type_storage = $this->entityTypeManager->getStorage('group_relationship_type');

    if (
      $relationship_type_storage->load('lms_course-lms_classes') === NULL
      || $this->entityTypeManager->getStorage('group_type')->load('lms_class') === NULL
    ) {
      return 0;
    }

    $course_ids = $this->migratedCourseIds();
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
