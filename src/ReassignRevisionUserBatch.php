<?php

namespace Drupal\inside_user;

use Drupal\Core\Batch\BatchBuilder;

/**
 * Processes revision author reassignment in bounded database batches.
 */
final class ReassignRevisionUserBatch {

  private const CHUNK_SIZE = 100;

  /**
   * Registers operations for each supported revisionable entity type.
   */
  public static function start(int $old_id, int $new_id): void {
    if ($old_id < 0 || $new_id <= 0 || $old_id === $new_id) {
      throw new \InvalidArgumentException('Invalid source or replacement user.');
    }
    if (!\Drupal::entityTypeManager()->getStorage('user')->load($new_id)) {
      throw new \InvalidArgumentException('Replacement user does not exist.');
    }

    // Validate all tables before starting the batch.
    $tables = ReassignRevisionUser::revisionTables();
    $batch = new BatchBuilder();
    $batch->setTitle(t('Reassigning revision authors'))
      ->setInitMessage(t('Preparing revision author updates...'))
      ->setProgressMessage(t('Processing revision author updates...'))
      ->setErrorMessage(t('Revision author reassignment stopped with an error.'))
      ->setFinishCallback([static::class, 'finished']);

    foreach (array_keys($tables) as $entity_type) {
      $batch->addOperation([static::class, 'process'], [$entity_type, $old_id, $new_id]);
    }
    batch_set($batch->toArray());
  }

  /**
   * Processes up to 100 matching revision rows per batch request.
   */
  public static function process(string $entity_type, int $old_id, int $new_id, array &$context): void {
    $tables = ReassignRevisionUser::revisionTables();
    if (!isset($tables[$entity_type])) {
      throw new \RuntimeException('Revision entity type is no longer available.');
    }
    $info = $tables[$entity_type];
    $database = \Drupal::database();

    if (!isset($context['sandbox']['initialized'])) {
      $context['sandbox']['initialized'] = TRUE;
      $context['sandbox']['last_id'] = 0;
      $context['sandbox']['processed'] = 0;
      $context['sandbox']['total'] = (int) $database->select($info['table'], 'r')
        ->condition('r.' . $info['author_key'], $old_id)
        ->countQuery()->execute()->fetchField();
    }

    $query = $database->select($info['table'], 'r');
    $ids = $query->fields('r', [$info['revision_key']])
      ->condition('r.' . $info['author_key'], $old_id)
      ->condition('r.' . $info['revision_key'], $context['sandbox']['last_id'], '>')
      ->orderBy('r.' . $info['revision_key'], 'ASC')
      ->range(0, self::CHUNK_SIZE)
      ->execute()->fetchCol();

    if ($ids) {
      $transaction = $database->startTransaction();
      try {
        $changed = (int) $database->update($info['table'])
          ->fields([$info['author_key'] => $new_id])
          ->condition($info['author_key'], $old_id)
          ->condition($info['revision_key'], $ids, 'IN')
          ->execute();
      }
      catch (\Throwable $e) {
        $transaction->rollBack();
        throw $e;
      }
      unset($transaction);

      $context['results']['updated'] = ($context['results']['updated'] ?? 0) + $changed;
      $context['sandbox']['processed'] += count($ids);
      $context['sandbox']['last_id'] = (int) end($ids);
    }

    $total = $context['sandbox']['total'];
    $context['message'] = t('@entity revisions: @processed of @total scanned.', [
      '@entity' => ucfirst($entity_type),
      '@processed' => $context['sandbox']['processed'],
      '@total' => $total,
    ]);
    // A further iteration confirms that all matching rows are exhausted.
    $context['finished'] = !$ids
      ? 1
      : min(0.99, $context['sandbox']['processed'] / max(1, $total));
  }

  /**
   * Reports the outcome after all operations finish.
   */
  public static function finished(bool $success, array $results, array $operations): void {
    // Some chunks may have succeeded before a failed operation.
    \Drupal::entityTypeManager()->getStorage('node')->resetCache();
    \Drupal::entityTypeManager()->getStorage('media')->resetCache();
    \Drupal::service('cache_tags.invalidator')->invalidateTags([
      'node_list',
      'media_list',
    ]);

    if ($success) {
      \Drupal::messenger()->addStatus(t('@count revision author records updated.', [
        '@count' => $results['updated'] ?? 0,
      ]));
    }
    else {
      \Drupal::messenger()->addError(t('Revision author reassignment did not complete. @count revisions may already have been updated.', [
        '@count' => $results['updated'] ?? 0,
      ]));
    }
  }

}
