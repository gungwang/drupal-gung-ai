<?php

namespace Drupal\clean_contents\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for cleaning up content entities.
 */
class ContentCleanupService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The orphan detection service.
   *
   * @var \Drupal\clean_contents\Service\OrphanDetectionService
   */
  protected $orphanService;

  /**
   * Batch size for entity deletion.
   *
   * @var int
   */
  protected $batchSize = 50;

  /**
   * Output callback for progress indicators.
   *
   * @var callable|null
   */
  protected $outputCallback = NULL;

  /**
   * Constructs a ContentCleanupService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    Connection $database,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->logger = $logger_factory->get('clean_contents');
  }

  /**
   * Set the orphan detection service.
   *
   * @param \Drupal\clean_contents\Service\OrphanDetectionService $orphan_service
   *   The orphan detection service.
   */
  public function setOrphanService(OrphanDetectionService $orphan_service) {
    $this->orphanService = $orphan_service;
  }

  /**
   * Set the output callback for progress indicators.
   *
   * @param callable $callback
   *   A callback function that accepts a string message.
   */
  public function setOutputCallback(callable $callback) {
    $this->outputCallback = $callback;
  }

  /**
   * Output a progress message.
   *
   * @param string $message
   *   The message to output.
   */
  protected function output($message) {
    if ($this->outputCallback) {
      call_user_func($this->outputCallback, $message);
    }
  }

  /**
   * Delete unpublished content entities.
   *
   * @param string|null $entity_type_id
   *   Optional entity type to limit deletion.
   * @param bool $dry_run
   *   If TRUE, only report what would be deleted.
   *
   * @return array
   *   Array of entity type => count deleted.
   */
  public function deleteUnpublished($entity_type_id = NULL, $dry_run = FALSE) {
    $results = [];

    $types_to_clean = $entity_type_id ? [$entity_type_id] : [
      'node',
      'paragraph',
      'media',
      'block_content',
      'marketo_form',
      'crc_asset',
      'ul_alert',
      'ul_legal_hold',
    ];

    foreach ($types_to_clean as $type) {
      // Skip if entity type doesn't exist.
      if (!$this->entityTypeManager->hasDefinition($type)) {
        continue;
      }

      $count = $this->deleteUnpublishedByEntityType($type, $dry_run);
      if ($count > 0) {
        $results[$type] = $count;
      }
    }

    return $results;
  }

  /**
   * Delete unpublished entities of a specific type.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param bool $dry_run
   *   If TRUE, only report what would be deleted.
   *
   * @return int
   *   Number of entities deleted or found.
   */
  public function deleteUnpublishedByEntityType($entity_type_id, $dry_run = FALSE) {
    try {
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);

      // Check if entity type has a status field.
      $keys = $entity_type->getKeys();
      if (!isset($keys['status'])) {
        return 0;
      }

      $this->output("Finding unpublished {$entity_type_id} entities...");
      $query = $storage->getQuery()
        ->condition('status', 0)
        ->accessCheck(FALSE);

      $ids = $query->execute();

      if (empty($ids)) {
        return 0;
      }

      $count = count($ids);
      $this->output(" found {$count}\n");

      if (!$dry_run) {
        $this->output("Deleting {$entity_type_id} entities: ");
        $this->deleteEntityBatch($entity_type_id, $ids);
        $this->output(" done\n");
        $this->logger->info('Deleted @count unpublished @type entities.', [
          '@count' => $count,
          '@type' => $entity_type_id,
        ]);
      }

      return $count;
    }
    catch (\Exception $e) {
      $this->logger->error('Error deleting unpublished @type: @message', [
        '@type' => $entity_type_id,
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * Delete orphaned content entities.
   *
   * @param string|null $entity_type_id
   *   Optional entity type to limit deletion.
   * @param bool $include_terms
   *   Whether to include taxonomy terms.
   * @param bool $dry_run
   *   If TRUE, only report what would be deleted.
   *
   * @return array
   *   Array of entity type => count deleted.
   */
  public function deleteOrphaned($entity_type_id = NULL, $include_terms = FALSE, $dry_run = FALSE) {
    $results = [];

    if (!$this->orphanService) {
      throw new \Exception('Orphan detection service not set.');
    }

    // Map entity types to their orphan detection methods.
    $method_map = [
      'paragraph' => 'findOrphanedParagraphs',
      'media' => 'findOrphanedMedia',
      'file' => 'findOrphanedFiles',
      'marketo_form' => 'findOrphanedMarketoForms',
      'crc_asset' => 'findOrphanedCrcAssets',
      'taxonomy_term' => 'findOrphanedTerms',
    ];

    $types_to_check = [];

    if ($entity_type_id) {
      // Use specific entity type if provided.
      if (isset($method_map[$entity_type_id])) {
        $types_to_check[$entity_type_id] = $method_map[$entity_type_id];
      }
      else {
        $this->logger->warning('Unknown entity type for orphan detection: @type', [
          '@type' => $entity_type_id,
        ]);
        return $results;
      }
    }
    else {
      // Use all entity types except taxonomy_term (opt-in).
      $types_to_check = [
        'paragraph' => $method_map['paragraph'],
        'media' => $method_map['media'],
        'file' => $method_map['file'],
        'marketo_form' => $method_map['marketo_form'],
        'crc_asset' => $method_map['crc_asset'],
      ];

      if ($include_terms) {
        $types_to_check['taxonomy_term'] = $method_map['taxonomy_term'];
      }
    }

    foreach ($types_to_check as $type => $method) {
      if (!method_exists($this->orphanService, $method)) {
        continue;
      }

      $orphaned_ids = $this->orphanService->$method();

      if (empty($orphaned_ids)) {
        continue;
      }

      $count = count($orphaned_ids);
      $results[$type] = $count;

      if (!$dry_run) {
        $this->output("Deleting orphaned {$type}: ");
        $this->deleteEntityBatch($type, $orphaned_ids);
        $this->output(" done\n");
        $this->logger->info('Deleted @count orphaned @type entities.', [
          '@count' => $count,
          '@type' => $type,
        ]);
      }
    }

    return $results;
  }

  /**
   * Delete old revisions keeping only the latest.
   *
   * @param string|null $entity_type_id
   *   Optional entity type to limit deletion.
   * @param bool $dry_run
   *   If TRUE, only report what would be deleted.
   *
   * @return array
   *   Array of entity type => count of revisions deleted.
   */
  public function deleteOldRevisions($entity_type_id = NULL, $dry_run = FALSE) {
    $results = [];

    $types_to_clean = $entity_type_id ? [$entity_type_id] : [
      'node',
      'paragraph',
      'media',
      'marketo_form',
      'ul_alert',
      'ul_legal_hold',
    ];

    foreach ($types_to_clean as $type) {
      // Skip if entity type doesn't exist.
      if (!$this->entityTypeManager->hasDefinition($type)) {
        continue;
      }

      $entity_type = $this->entityTypeManager->getDefinition($type);

      // Skip if entity type is not revisioned.
      if (!$entity_type->isRevisionable()) {
        continue;
      }

      $count = $this->deleteOldRevisionsByEntityType($type, $dry_run);
      if ($count > 0) {
        $results[$type] = $count;
      }
    }

    return $results;
  }

  /**
   * Delete old revisions for a specific entity type.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param bool $dry_run
   *   If TRUE, only report what would be deleted.
   *
   * @return int
   *   Number of revisions deleted or found.
   */
  protected function deleteOldRevisionsByEntityType($entity_type_id, $dry_run = FALSE) {
    try {
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);

      // Get table and key information.
      $base_table = $entity_type->getBaseTable();
      $revision_table = $entity_type->getRevisionTable();
      $revision_data_table = $entity_type->getRevisionDataTable();
      $id_key = $entity_type->getKey('id');
      $revision_key = $entity_type->getKey('revision');

      if (!$revision_table) {
        return 0;
      }

      // Find old revisions using database query.
      // Strategy: Find all revisions that are NOT the default/current revision.
      $query = $this->database->select($revision_table, 'r');
      $query->fields('r', [$revision_key]);

      // Join with base table to exclude current revisions.
      $query->leftJoin($base_table, 'b', "r.{$id_key} = b.{$id_key} AND r.{$revision_key} = b.{$revision_key}");
      $query->isNull("b.{$revision_key}");

      $old_revision_ids = $query->execute()->fetchCol();

      if (empty($old_revision_ids)) {
        return 0;
      }

      $revision_count = count($old_revision_ids);

      if (!$dry_run) {
        $this->output("Deleting {$entity_type_id} revisions: ");

        // Get all revision field tables for this entity type.
        $field_revision_tables = $this->getRevisionFieldTables($entity_type_id, $revision_table);

        // Use bulk database deletion for performance.
        // Process in larger batches (500) for efficiency.
        $batch_size = 500;
        $batches = array_chunk($old_revision_ids, $batch_size);
        $total_batches = count($batches);
        $deleted = 0;

        foreach ($batches as $batch_num => $batch) {
          try {
            // Delete from all field revision tables first.
            foreach ($field_revision_tables as $field_table) {
              $this->database->delete($field_table)
                ->condition('revision_id', $batch, 'IN')
                ->execute();
            }

            // Delete from revision data table if it exists.
            if ($revision_data_table) {
              $this->database->delete($revision_data_table)
                ->condition($revision_key, $batch, 'IN')
                ->execute();
            }

            // Delete from main revision table.
            $num_deleted = $this->database->delete($revision_table)
              ->condition($revision_key, $batch, 'IN')
              ->execute();

            $deleted += $num_deleted;

            // Show progress every 10 batches or on last batch.
            if (($batch_num + 1) % 10 == 0 || ($batch_num + 1) == $total_batches) {
              $this->output(".");
            }
          }
          catch (\Exception $e) {
            $this->logger->error('Error deleting revision batch for @type: @message', [
              '@type' => $entity_type_id,
              '@message' => $e->getMessage(),
            ]);
          }
        }

        $this->output(" done\n");

        $this->logger->info('Deleted @count old revisions of @type entities.', [
          '@count' => $deleted,
          '@type' => $entity_type_id,
        ]);
      }

      return $revision_count;
    }
    catch (\Exception $e) {
      $this->logger->error('Error deleting old revisions of @type: @message', [
        '@type' => $entity_type_id,
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * Get all field revision tables for an entity type.
   *
   * @param string $entity_type_id
   *   Entity type ID.
   * @param string $revision_table
   *   Main revision table name.
   *
   * @return array
   *   Array of table names.
   */
  protected function getRevisionFieldTables($entity_type_id, $revision_table) {
    $tables = [];

    // Query information_schema to find all revision field tables.
    $schema = $this->database->schema();
    $prefix = $revision_table . '__field_';

    // Get all tables that match the pattern.
    $query = $this->database->query(
      "SELECT TABLE_NAME FROM information_schema.TABLES 
       WHERE TABLE_SCHEMA = DATABASE() 
       AND TABLE_NAME LIKE :prefix",
      [':prefix' => $this->database->escapeLike($prefix) . '%']
    );

    while ($row = $query->fetchAssoc()) {
      $tables[] = $row['TABLE_NAME'];
    }

    return $tables;
  }

  /**
   * Delete broken path aliases and redirects.
   *
   * @param bool $dry_run
   *   If TRUE, only report what would be deleted.
   *
   * @return array
   *   Array with 'path_alias' and optionally 'redirect' counts.
   */
  public function deleteBrokenPaths($dry_run = FALSE) {
    $results = [
      'path_alias' => 0,
      'redirect' => 0,
    ];

    // 1. Clean up broken path aliases.
    $results['path_alias'] = $this->deleteBrokenPathAliases($dry_run);

    // 2. Clean up broken redirects (if redirect module exists).
    if ($this->entityTypeManager->hasDefinition('redirect')) {
      $results['redirect'] = $this->deleteBrokenRedirects($dry_run);
    }

    return $results;
  }

  /**
   * Delete broken path aliases.
   *
   * @param bool $dry_run
   *   If TRUE, only report what would be deleted.
   *
   * @return int
   *   Number of broken aliases deleted or found.
   */
  protected function deleteBrokenPathAliases($dry_run = FALSE) {
    $broken_count = 0;

    try {
      // Define entity types to check against.
      $entity_types_to_check = [
        'node' => 'node_field_data',
        'media' => 'media_field_data',
        'taxonomy_term' => 'taxonomy_term_field_data',
        'user' => 'users_field_data',
      ];

      $broken_alias_ids = [];

      // For each entity type, find broken aliases using JOIN.
      foreach ($entity_types_to_check as $entity_type => $data_table) {
        if (!$this->database->schema()->tableExists($data_table)) {
          continue;
        }

        // Get the ID key for this entity type.
        $entity_type_def = $this->entityTypeManager->getDefinition($entity_type);
        $id_key = $entity_type_def->getKey('id');

        // Build query to find aliases pointing to non-existent entities.
        $query = $this->database->select('path_alias', 'pa');
        $query->fields('pa', ['id']);
        $query->leftJoin($data_table, 'e', "SUBSTRING(pa.path, " . (strlen("/{$entity_type}/") + 1) . ") = CAST(e.{$id_key} AS CHAR)");
        $query->condition('pa.path', $this->database->escapeLike("/{$entity_type}/") . '%', 'LIKE');
        $query->isNull("e.{$id_key}");

        $result = $query->execute()->fetchCol();
        $broken_alias_ids = array_merge($broken_alias_ids, $result);
      }

      $broken_alias_ids = array_unique($broken_alias_ids);
      $broken_count = count($broken_alias_ids);

      if (!$dry_run && $broken_count > 0) {
        // Delete in batches.
        $batches = array_chunk($broken_alias_ids, $this->batchSize);
        foreach ($batches as $batch) {
          $this->database->delete('path_alias')
            ->condition('id', $batch, 'IN')
            ->execute();
        }

        $this->logger->info('Deleted @count broken path aliases.', [
          '@count' => $broken_count,
        ]);
      }

      return $broken_count;
    }
    catch (\Exception $e) {
      $this->logger->error('Error deleting broken path aliases: @message', [
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * Delete broken redirects.
   *
   * @param bool $dry_run
   *   If TRUE, only report what would be deleted.
   *
   * @return int
   *   Number of broken redirects deleted or found.
   */
  protected function deleteBrokenRedirects($dry_run = FALSE) {
    $broken_count = 0;

    try {
      $storage = $this->entityTypeManager->getStorage('redirect');

      // Get all redirects.
      $query = $storage->getQuery()->accessCheck(FALSE);
      $redirect_ids = $query->execute();

      if (empty($redirect_ids)) {
        return 0;
      }

      $broken_redirects = [];

      // Cache for entity existence checks to avoid repeated loads.
      $entity_exists_cache = [];

      // Process redirects in batches to reduce memory usage.
      $redirect_batches = array_chunk($redirect_ids, $this->batchSize);

      foreach ($redirect_batches as $batch) {
        $redirects = $storage->loadMultiple($batch);

        foreach ($redirects as $redirect) {
          // Get the redirect target URI.
          $redirect_uri = $redirect->get('redirect_redirect')->get(0)->getValue();

          if (isset($redirect_uri['uri'])) {
            $uri = $redirect_uri['uri'];

            // Check if it's an internal URI pointing to content.
            if (preg_match('#^internal:/(\w+)/(\d+)#', $uri, $matches) ||
                preg_match('#^entity:(\w+)/(\d+)#', $uri, $matches)) {
              $entity_type = $matches[1];
              $entity_id = $matches[2];

              // Use cache to avoid repeated existence checks.
              $cache_key = "{$entity_type}:{$entity_id}";

              if (!isset($entity_exists_cache[$cache_key])) {
                // Check if entity exists using database query (faster than loading).
                if ($this->entityTypeManager->hasDefinition($entity_type)) {
                  $target_storage = $this->entityTypeManager->getStorage($entity_type);
                  $exists_query = $target_storage->getQuery()
                    ->accessCheck(FALSE)
                    ->condition($this->entityTypeManager->getDefinition($entity_type)->getKey('id'), $entity_id)
                    ->range(0, 1);
                  $exists = !empty($exists_query->execute());
                  $entity_exists_cache[$cache_key] = $exists;
                }
                else {
                  $entity_exists_cache[$cache_key] = FALSE;
                }
              }

              if (!$entity_exists_cache[$cache_key]) {
                // Target entity doesn't exist - redirect is broken.
                $broken_redirects[] = $redirect->id();
                $broken_count++;
              }
            }
          }
        }
      }

      if (!$dry_run && !empty($broken_redirects)) {
        // Delete in batches.
        $delete_batches = array_chunk($broken_redirects, $this->batchSize);
        foreach ($delete_batches as $batch) {
          $entities_to_delete = $storage->loadMultiple($batch);
          $storage->delete($entities_to_delete);
        }

        $this->logger->info('Deleted @count broken redirects.', [
          '@count' => $broken_count,
        ]);
      }

      return $broken_count;
    }
    catch (\Exception $e) {
      $this->logger->error('Error deleting broken redirects: @message', [
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * Rename users to test accounts.
   *
   * @param string $password
   *   The password to set for renamed users.
   * @param bool $dry_run
   *   If TRUE, only report what would be renamed.
   *
   * @return array
   *   Array with 'renamed' and 'preserved' counts.
   */
  public function renameUsers($password, $dry_run = FALSE) {
    $user_storage = $this->entityTypeManager->getStorage('user');

    // Get all users except anonymous and admin.
    $query = $user_storage->getQuery()
      ->condition('uid', 1, '>')
      ->sort('uid', 'ASC')
      ->accessCheck(FALSE);

    $uids = $query->execute();
    $users = $user_storage->loadMultiple($uids);

    $counter = 1;
    $renamed_count = 0;
    $preserved_count = 0;

    foreach ($users as $user) {
      $email = $user->getEmail();

      // Preserve users with "gung" in email.
      if (stripos($email, 'gung') !== FALSE) {
        $preserved_count++;
        continue;
      }

      if (!$dry_run) {
        $user->setUsername("test{$counter}");
        $user->setEmail("test{$counter}@example.com");
        $user->setPassword($password);
        $user->save();

        $this->logger->info('Renamed user @uid to test@counter', [
          '@uid' => $user->id(),
          '@counter' => $counter,
        ]);
      }

      $counter++;
      $renamed_count++;
    }

    return [
      'renamed' => $renamed_count,
      'preserved' => $preserved_count,
    ];
  }

  /**
   * Delete entities in batches.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param array $ids
   *   The entity IDs to delete.
   */
  protected function deleteEntityBatch($entity_type_id, array $ids) {
    $storage = $this->entityTypeManager->getStorage($entity_type_id);

    // Process in batches to avoid memory issues.
    $batches = array_chunk($ids, $this->batchSize);
    $total_batches = count($batches);
    $current_batch = 0;

    foreach ($batches as $batch) {
      $success = $this->deleteEntitiesWithRetry($storage, $batch, $entity_type_id);

      if ($success) {
        $current_batch++;
        // Output progress dot every 10 batches or on last batch.
        if ($current_batch % 10 == 0 || $current_batch == $total_batches) {
          $this->output(".");
        }
      }
    }
  }

  /**
   * Delete entities with retry logic for deadlock errors.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   Entity storage handler.
   * @param array $batch
   *   Array of entity IDs to delete.
   * @param string $entity_type_id
   *   Entity type ID.
   * @param int $max_retries
   *   Maximum number of retry attempts.
   *
   * @return bool
   *   TRUE if deletion succeeded, FALSE otherwise.
   */
  protected function deleteEntitiesWithRetry($storage, array $batch, $entity_type_id, $max_retries = 3) {
    $attempt = 0;

    while ($attempt < $max_retries) {
      try {
        $entities = $storage->loadMultiple($batch);
        $storage->delete($entities);
        return TRUE;
      }
      catch (\Exception $e) {
        $is_deadlock = $this->isDeadlockError($e);

        if ($is_deadlock && $attempt < $max_retries - 1) {
          // Wait before retrying (exponential backoff: 100ms, 200ms, 400ms).
          // microseconds.
          $wait_time = 100000 * pow(2, $attempt);
          usleep($wait_time);
          $attempt++;
          continue;
        }

        // Log error and give up.
        $this->logger->error('Error deleting @type entities after @attempts attempts: @message', [
          '@type' => $entity_type_id,
          '@attempts' => $attempt + 1,
          '@message' => $e->getMessage(),
        ]);
        return FALSE;
      }
    }

    return FALSE;
  }

  /**
   * Check if an exception is a database deadlock error.
   *
   * @param \Exception $e
   *   The exception to check.
   *
   * @return bool
   *   TRUE if this is a deadlock error.
   */
  protected function isDeadlockError(\Exception $e) {
    $message = $e->getMessage();

    // Check for MySQL deadlock error code or SQLSTATE.
    return (
      strpos($message, 'SQLSTATE[40001]') !== FALSE ||
      strpos($message, '1213 Deadlock') !== FALSE ||
      strpos($message, 'Serialization failure') !== FALSE
    );
  }

}
