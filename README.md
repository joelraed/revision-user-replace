# Revision User Replace

Administrative revision-author reassignment utilities for **Drupal 10/11**, implemented as part of the existing custom `inside_user` module.

The goal is to replace a user as the **author of node and media revisions**, including historical revisions, without changing who owns the content.

> **Important:** This operation rewrites revision metadata in the database. Back up the database, test on staging, and verify results before using it on production. The code has not been independently tested against every Drupal site configuration.

## What we changed

### 1. Corrected revision-author reassignment

The initial implementation searched user-reference fields and attempted to save loaded entity revisions. That could miss revision authors and could have unwanted revision-storage side effects.

The revised approach identifies matching revisions through their revision metadata tables and updates the revision-author column directly. It:
- Includes historical and current **node** and **media** revisions.
- Updates only the **revision author**, not the content owner (`uid`), entity references, revision IDs, dates, or content.
- Resolves the revision author field with Drupal's `getRevisionMetadataKey('revision_user')` rather than assuming node and media share the same physical column name.
- Validates the expected revision table schema before attempting updates.

### 2. Preserved the content-usage field discovery helper

The existing `GetContentUsageCount.php` class calls:

```php
ReassignRevisionUser::getUserReferenceFields($entity_type_id);
```

An earlier refactor removed that method and broke the usage counter. We restored it to discover `entity_reference` fields targeting `user`.

**These are separate concerns:** the existing content-usage counter checks user references in fields; revision reassignment changes only revision authors. Usage counts may include multiple field matches and are **not necessarily equal** to the revision-author count.

### 3. Added Drupal Batch API processing

Reassignment now uses `ReassignRevisionUserBatch`, rather than changing every matching revision in a single form request.

- Processes **up to 100 matching revision rows per batch operation invocation**.
- Runs separate operations for node and media revisions.
- Tracks progress using Drupal's batch sandbox.
- Reports the number of updated revision-author records when complete.
- Uses a database transaction around each chunk, so earlier successful chunks are **not rolled back** if a later chunk fails.
- Resets node/media entity storage caches and invalidates list cache tags at completion.

### 4. Improved the administrative form

`UserReassignRevisionsForm.php` now:
- Shows the **total** number of matching revision-author records.
- Limits the preview to the **first 100** results to avoid loading all revisions on large sites.
- Validates the replacement user and requires a database-backup confirmation.
- Starts the Batch API process when submitted.
- Displays revision details, including entity labels, revision dates, IDs, and available view links.

## Files

| File | Purpose |
| --- | --- |
| `src/ReassignRevisionUser.php` | User-reference field discovery, revision-author lookup/counting, revision-table validation, and a direct replacement helper. |
| `src/ReassignRevisionUserBatch.php` | Chunked Batch API worker, progress tracking, and completion reporting. |
| `src/Form/UserReassignRevisionsForm.php` | Administrative preview, validation, and batch submission. |

This repository contains the above PHP classes and does **not** contain a complete standalone Drupal module. It is intended to be integrated into the existing `inside_user` module, which supplies module metadata, routing, and permissions.

## Deployment and testing

1. Copy or integrate these PHP classes under `modules/custom/inside_user/src/` in the appropriate paths.
2. Confirm the existing module routes to `Drupal\\inside_user\\Form\\UserReassignRevisionsForm`.
3. Make a database backup and use a staging environment first.
4. Clear Drupal's caches:

   ```bash
   drush cr
   ```

5. Open the reassignment form for a user who has only a few authored revisions. Compare the displayed count with the database and confirm the replacement user is different.
6. Submit and verify that the Batch API completes, revision authors are changed, and content owners remain unchanged.
7. Repeat with a user having **more than 100 revisions** to confirm multiple batch requests execute.
8. Check that the existing `GetContentUsageCount.php` functionality still works.

### Read-only baseline checks

For a given source user ID, substitute the numeric ID for `123`:

```bash
drush sql:query "SELECT COUNT(*) FROM node_revision WHERE revision_uid = 123;"
```

For media, **verify the actual physical author column first**; do not assume it is named `revision_uid`:

```bash
drush sql:query "DESCRIBE media_revision;"
```

The form's revision count reflects revision author matches, not arbitrary user-reference matches.

## Caveats

- **Direct database writes** bypass Drupal entity hooks and normal save-time cache invalidation. Confirm cache behavior on your site, especially with custom integrations or moderation workflows.
- **Not atomic across the full batch.** Each chunk is committed separately. If a batch is interrupted, previously processed revisions stay updated; review the result before rerunning.
- **Preview is capped at 100 revisions.** The full count still displays.
- **Schema assumptions remain.** The implementation checks the resolved author field against the revision base table; customized storage should be reviewed before use.
- **Not a complete standalone module.** Routing, access permissions, and the existing content-usage service are managed in the parent `inside_user` codebase.

## Development history

- [PR #1 — Fix reassignment of historical revision authors](https://github.com/joelraed/revision-user-replace/pull/1)
- [PR #2 — Add Batch API processing](https://github.com/joelraed/revision-user-replace/pull/2)
