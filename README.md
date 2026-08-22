# Group 3 schema repair module

For Drupal users upgrading from version 2. There are longstanding issues with the DB migration for this upgrade. See:
* https://www.drupal.org/project/group/issues/3391452
* https://www.drupal.org/project/group/issues/3618552

## Module commands
| Purpose | Standalone command |
| :--- | :--- |
| Read-only Group 3 audit | `drush group3-schema-repair:audit` |
| Audit a user’s migrated-course access too | `drush group3-schema-repair:audit USER_ID` |
| Display installed and runtime entity definitions | `drush group3-schema-repair:show-entity-definitions` |
| Record missing installed Group 3 entity definitions | `drush group3-schema-repair:repair-entity-definitions` |
| Complete the Group 10301 relationship-field update | `drush group3-schema-repair:repair-relationship-fields` |
| Repair installed field-storage definitions before Group update 10305 | `drush group3-schema-repair:repair-repository` |
| Repair stale Group 2 references inside Views | `drush group3-schema-repair:repair-views` |
| Create missing active `group_roles` field storage | `drush group3-schema-repair:repair-group-roles-storage` |
| Create missing membership `group_roles` field instances | `drush group3-schema-repair:repair-group-roles-instances` |
| Delete invalid role rows on non-membership relationships | `drush group3-schema-repair:repair-group-roles-table` |
| Rename/delete stale Group 2 active config | `drush group3-schema-repair:repair-stale-config` |

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
# When Status Report says group_relationship_type and/or group_relationship
# "needs to be installed", but the audit reports the runtime entity types and
# relationship tables are present.
drush group3-schema-repair:repair-entity-definitions
drush cr
drush group3-schema-repair:audit
```

This command refuses to run if either Group 3 relationship table is absent.
For an existing `group_relationship` definition, it follows Group update 10300
by refreshing Drupal's entity-storage schema metadata and its relationship
table indexes. It does not create, rename, or delete content tables or data.

If the audit instead says that the installed `group_relationship` definition
differs from the runtime definition, synchronize that existing metadata entry:

```bash
drush group3-schema-repair:repair-entity-definitions --synchronize
drush cr
drush group3-schema-repair:audit
```

If that mismatch remains, collect the two definitions before making any more
changes:

```bash
drush group3-schema-repair:show-entity-definitions
```

If Status Report then lists only these Group relationship fields as needing an
update—UUID, creator, parent group, content, group type, and `group_roles`—the
entity-level repair succeeded and exposed the next Group update step. After a
fresh database backup, run the guarded Group 10301-equivalent repair:

```bash
drush group3-schema-repair:repair-relationship-fields
drush cr
drush group3-schema-repair:audit
```

The command refuses any field set other than those six updated fields. It uses
Drupal's fieldable-entity update API to refresh relationship-field indexes and
schema metadata without deleting relationship data.

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
