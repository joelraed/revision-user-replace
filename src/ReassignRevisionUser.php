<?php

namespace Drupal\inside_user;

/**
 * Reassigns revision authors without creating or changing entity revisions.
 *
 * Intended for standard Drupal node and media SQL storage schemas.
 */
final class ReassignRevisionUser {

  /**
   * Discovers user-reference fields for content-usage reporting.
   *
   * Kept for compatibility with GetContentUsageCount::getUserUsages().
   * These fields are for usage counts only; replaceUser() updates revision
   * authors exclusively.
   *
   * @return string[]
   *   User-reference field machine names.
   */
  public static function getUserReferenceFields(string $entity_type_id): array {
    $manager = \Drupal::service('entity_field.manager');
    $fields = [];
    foreach ($manager->getFieldStorageDefinitions($entity_type_id) as $field_name => $definition) {
      if ($definition->getType() === 'entity_reference'
        && $definition->getSetting('target_type') === 'user') {
        $fields[] = $field_name;
      }
    }
    return array_values(array_unique($fields));
  }


  /**
   * Gets revision records whose author is the specified user.
   *
   * @return array<int, array{entity_type: string, revision_id: int}>
   *   Revision identifiers, including historical revisions.
   */
  public static function getRevisions(int $user_id, int $limit = 100): array {
    if ($user_id < 0) {
      return [];
    }

    $database = \Drupal::database();
    $results = [];
    foreach (self::revisionTables() as $entity_type => $info) {
      $query = $database->select($info['table'], 'r');
      $query->fields('r', [$info['revision_key']]);
      $query->condition('r.' . $info['author_key'], $user_id);
      $query->orderBy('r.' . $info['revision_key'], 'DESC');
      $query->range(0, max(1, $limit - count($results)));
      foreach ($query->execute()->fetchCol() as $revision_id) {
        $results[] = [
          'entity_type' => $entity_type,
          'revision_id' => (int) $revision_id,
        ];
      }
      if (count($results) >= $limit) {
        break;
      }
    }
    return $results;
  }

  /**
   * Counts matching revision rows without loading entities.
   */
  public static function countRevisions(int $user_id): int {
    if ($user_id < 0) {
      return 0;
    }
    $database = \Drupal::database();
    $count = 0;
    foreach (self::revisionTables() as $info) {
      $count += (int) $database->select($info['table'], 'r')
        ->condition('r.' . $info['author_key'], $user_id)
        ->countQuery()
        ->execute()
        ->fetchField();
    }
    return $count;
  }

  /**
   * Reassigns revision author metadata on existing revision rows.
   *
   * Does not change entity owners, revision IDs, or content. A direct database
   * update bypasses entity hooks and normal cache invalidation; the caller
   * should rebuild caches after this administrative operation.
   */
  public static function replaceUser(int $old_id, int $new_id): int {
    if ($old_id < 0 || $new_id <= 0 || $old_id === $new_id) {
      return 0;
    }

    $user_storage = \Drupal::entityTypeManager()->getStorage('user');
    if (!$user_storage->load($new_id)) {
      throw new \InvalidArgumentException('Replacement user does not exist.');
    }

    $database = \Drupal::database();
    $transaction = $database->startTransaction();
    $updated = 0;
    try {
      foreach (self::revisionTables() as $info) {
        $updated += (int) $database->update($info['table'])
          ->fields([$info['author_key'] => $new_id])
          ->condition($info['author_key'], $old_id)
          ->execute();
      }
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    unset($transaction);
    return $updated;
  }

  /**
   * Resolves and verifies the physical revision metadata schema.
   *
   * Revision author column names may differ for node and media.
   * Fail closed if a site has changed that mapping.
   */
  public static function revisionTables(): array {
    $manager = \Drupal::entityTypeManager();
    $schema = \Drupal::database()->schema();
    $tables = [];
    foreach (['node', 'media'] as $entity_type) {
      $definition = $manager->getDefinition($entity_type, FALSE);
      if (!$definition) {
        continue;
      }
      $table = $definition->getRevisionTable();
      $revision_key = $definition->getKey('revision');
      $author_key = $definition->getRevisionMetadataKey('revision_user');
      if (!$table || !$revision_key || !$schema->tableExists($table)
        || !$schema->fieldExists($table, $revision_key)
        || !$schema->fieldExists($table, $author_key)) {
        throw new \RuntimeException(sprintf('Unexpected %s revision table schema; no revisions were changed.', $entity_type));
      }
      $tables[$entity_type] = [
        'table' => $table,
        'revision_key' => $revision_key,
        'author_key' => $author_key,
      ];
    }
    return $tables;
  }

}
