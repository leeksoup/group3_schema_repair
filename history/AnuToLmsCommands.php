<?php

declare(strict_types=1);

namespace Drupal\anu_to_lms_migrate\Drush\Commands;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
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
