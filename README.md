# Group 3 schema repair module

For Drupal users upgrading from version 2. There are longstanding issues with the DB migration for this upgrade. See:
* https://www.drupal.org/project/group/issues/3391452
* https://www.drupal.org/project/group/issues/3618552

## Module commands
| Purpose| Standalone command |
| :----------- | :------------ |
| Read-only Group 3 audit | drush group3-schema-repair:audit |
| Audit a user’s migrated-course access too | drush group3-schema-repair:audit USER_ID |
| Repair stale Group 2 references inside Views | drush group3-schema-repair:repair-views |
| Repair installed schema repository before Group update | drush group3-schema-repair:repair-repository |
| 10305 |
| Create missing active group_roles field storage | drush group3-schema-repair:repair-group-roles-storage |
| Create missing membership group_roles field instances | drush group3-schema-repair:repair-group-roles-instances |
| Delete invalid role rows on non-membership | drush group3-schema-repair:repair-group-roles-table |
| relationships |
| Rename/delete stale Group 2 active config | drush group3-schema-repair:repair-stale-config |

## Suggested usage:

```bash
# Back up first.
mkdir -p ../backups
drush sql:dump --gzip --result-file=../backups/pre-group3-repair-$(date +%Y%m%d-%H%M%S).sql

# Enable the standalone repair module and discover its commands.
drush en group3_schema_repair -y
drush cr
drush list --filter=group3-schema-repair

# Always start read-only.
drush group3-schema-repair:audit
drush group3-schema-repair:audit USER_ID
```

The audit reports:

- Stale Group 2 config names and dependencies (`group_content`, `group.content_type`).
- Stale Group 2 references inside Views.
- Malformed `group.role.*` configuration.
- Missing/wrong `group_relationship.group_roles` storage or instances.
- Orphaned/wrong relationship-role database rows.
- Optionally, migrated LMS-course membership and access state for `USER_ID`.

Apply only the repair that matches the audit result:

```bash
# Only when Views are the sole reported stale-reference issue.
drush group3-schema-repair:repair-views
drush cr
drush group3-schema-repair:audit USER_ID
```

```bash
# Only when Group's update process is blocked before update 10305 because
# group_relationship installed field definitions are absent.
drush group3-schema-repair:repair-repository
drush updb -y
drush cr
drush group3-schema-repair:audit
```

```bash
# Required order for missing membership role fields.
drush group3-schema-repair:repair-group-roles-storage
drush group3-schema-repair:repair-group-roles-instances
drush cr
drush group3-schema-repair:audit
```

```bash
# Destructive: deletes group_roles rows attached to relationships that are
# not group_membership. Run only after the audit identifies that exact issue.
drush group3-schema-repair:repair-group-roles-table
drush cr
drush group3-schema-repair:audit
```

```bash
# Broad active-config rewrite: use only when the audit identifies stale
# Group 2 config names/dependencies, then review output carefully.
drush group3-schema-repair:repair-stale-config
drush cr
drush group3-schema-repair:audit
```

Do not use the role-table command to address orphaned rows or nonexistent role IDs: it only removes rows whose owning relationship has the wrong plugin.

