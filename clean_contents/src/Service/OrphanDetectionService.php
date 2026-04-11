<?php

namespace Drupal\clean_contents\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;

/**
 * Service for detecting orphaned content entities.
 */
class OrphanDetectionService {

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
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $fieldManager;

  /**
   * Output callback for progress indicators.
   *
   * @var callable|null
   */
  protected $outputCallback = NULL;

  /**
   * Constructs an OrphanDetectionService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $field_manager
   *   The entity field manager.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    Connection $database,
    EntityFieldManagerInterface $field_manager
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->fieldManager = $field_manager;
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
   * Find orphaned paragraphs.
   *
   * @return array
   *   Array of orphaned paragraph IDs.
   */
  public function findOrphanedParagraphs() {
    $this->output("Finding orphaned paragraphs...");
    // Get all paragraph IDs.
    $all_paragraph_ids = $this->getAllEntityIds('paragraph');

    if (empty($all_paragraph_ids)) {
      $this->output(" none found\n");
      return [];
    }

    $this->output(" checking references");
    // Get all fields that reference paragraphs.
    $reference_fields = $this->getAllReferencingFields('paragraph', 'entity_reference_revisions');

    // Query each reference field for referenced paragraph IDs.
    $referenced_ids = [];
    foreach ($reference_fields as $field_info) {
      $table = $field_info['table'];
      $column = $field_info['column'];

      if (!$this->database->schema()->tableExists($table)) {
        continue;
      }

      $query = $this->database->select($table, 't')
        ->fields('t', [$column])
        ->distinct();

      $result = $query->execute()->fetchCol();
      $referenced_ids = array_merge($referenced_ids, $result);
      $this->output(".");
    }

    $referenced_ids = array_unique($referenced_ids);

    // Find orphans.
    $orphaned_ids = array_diff($all_paragraph_ids, $referenced_ids);
    $this->output(" found " . count($orphaned_ids) . "\n");

    return array_values($orphaned_ids);
  }

  /**
   * Find orphaned media entities.
   *
   * @return array
   *   Array of orphaned media IDs.
   */
  public function findOrphanedMedia() {
    $this->output("Finding orphaned media...");
    // Get all media IDs.
    $all_media_ids = $this->getAllEntityIds('media');

    if (empty($all_media_ids)) {
      $this->output(" none found\n");
      return [];
    }

    $this->output(" checking references");
    // Get all fields that reference media.
    $reference_fields = $this->getAllReferencingFields('media', 'entity_reference');

    // Query each reference field for referenced media IDs.
    $referenced_ids = [];
    foreach ($reference_fields as $field_info) {
      $table = $field_info['table'];
      $column = $field_info['column'];

      if (!$this->database->schema()->tableExists($table)) {
        continue;
      }

      $query = $this->database->select($table, 't')
        ->fields('t', [$column])
        ->distinct();

      $result = $query->execute()->fetchCol();
      $referenced_ids = array_merge($referenced_ids, $result);
      $this->output(".");
    }

    $referenced_ids = array_unique($referenced_ids);

    // Find orphans.
    $orphaned_ids = array_diff($all_media_ids, $referenced_ids);
    $this->output(" found " . count($orphaned_ids) . "\n");

    return array_values($orphaned_ids);
  }

  /**
   * Find orphaned files.
   *
   * @return array
   *   Array of orphaned file IDs.
   */
  public function findOrphanedFiles() {
    $this->output("Finding orphaned files...");
    // Query files with zero usage count.
    $query = $this->database->select('file_managed', 'fm');
    $query->fields('fm', ['fid']);
    $query->leftJoin('file_usage', 'fu', 'fm.fid = fu.fid');
    $query->isNull('fu.fid');

    $orphaned_ids = $query->execute()->fetchCol();
    $this->output(" found " . count($orphaned_ids) . "\n");

    return $orphaned_ids;
  }

  /**
   * Find orphaned marketo_form entities.
   *
   * @return array
   *   Array of orphaned marketo_form IDs.
   */
  public function findOrphanedMarketoForms() {
    if (!$this->entityTypeManager->hasDefinition('marketo_form')) {
      return [];
    }

    $this->output("Finding orphaned marketo_forms...");
    // Get all marketo_form IDs.
    $all_form_ids = $this->getAllEntityIds('marketo_form');

    if (empty($all_form_ids)) {
      $this->output(" none found\n");
      return [];
    }

    $this->output(" checking references");
    // Get all fields that reference marketo_form.
    $reference_fields = $this->getAllReferencingFields('marketo_form', 'entity_reference');

    // Query each reference field for referenced form IDs.
    $referenced_ids = [];
    foreach ($reference_fields as $field_info) {
      $table = $field_info['table'];
      $column = $field_info['column'];

      if (!$this->database->schema()->tableExists($table)) {
        continue;
      }

      $query = $this->database->select($table, 't')
        ->fields('t', [$column])
        ->distinct();

      $result = $query->execute()->fetchCol();
      $referenced_ids = array_merge($referenced_ids, $result);
      $this->output(".");
    }

    $referenced_ids = array_unique($referenced_ids);

    // Find orphans.
    $orphaned_ids = array_diff($all_form_ids, $referenced_ids);
    $this->output(" found " . count($orphaned_ids) . "\n");

    return array_values($orphaned_ids);
  }

  /**
   * Find orphaned crc_asset entities.
   *
   * @return array
   *   Array of orphaned crc_asset IDs.
   */
  public function findOrphanedCrcAssets() {
    if (!$this->entityTypeManager->hasDefinition('crc_asset')) {
      return [];
    }

    $this->output("Finding orphaned crc_assets...");
    // Get all crc_asset IDs.
    $all_asset_ids = $this->getAllEntityIds('crc_asset');

    if (empty($all_asset_ids)) {
      $this->output(" none found\n");
      return [];
    }

    $this->output(" checking references");
    // Get all fields that reference crc_asset.
    $reference_fields = $this->getAllReferencingFields('crc_asset', 'entity_reference');

    // Query each reference field for referenced asset IDs.
    $referenced_ids = [];
    foreach ($reference_fields as $field_info) {
      $table = $field_info['table'];
      $column = $field_info['column'];

      if (!$this->database->schema()->tableExists($table)) {
        continue;
      }

      $query = $this->database->select($table, 't')
        ->fields('t', [$column])
        ->distinct();

      $result = $query->execute()->fetchCol();
      $referenced_ids = array_merge($referenced_ids, $result);
      $this->output(".");
    }

    $referenced_ids = array_unique($referenced_ids);

    // Find orphans.
    $orphaned_ids = array_diff($all_asset_ids, $referenced_ids);
    $this->output(" found " . count($orphaned_ids) . "\n");

    return array_values($orphaned_ids);
  }

  /**
   * Find orphaned taxonomy terms.
   *
   * @param string|null $vocabulary
   *   Optional vocabulary to limit to.
   *
   * @return array
   *   Array of orphaned term IDs.
   */
  public function findOrphanedTerms($vocabulary = NULL) {
    // Get all term IDs.
    $query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery();
    if ($vocabulary) {
      $query->condition('vid', $vocabulary);
    }
    $query->accessCheck(FALSE);
    $all_term_ids = $query->execute();

    if (empty($all_term_ids)) {
      return [];
    }

    // Get all fields that reference taxonomy terms.
    $reference_fields = $this->getAllReferencingFields('taxonomy_term', 'entity_reference');

    // Query each reference field for referenced term IDs.
    $referenced_ids = [];
    foreach ($reference_fields as $field_info) {
      $table = $field_info['table'];
      $column = $field_info['column'];

      if (!$this->database->schema()->tableExists($table)) {
        continue;
      }

      $query = $this->database->select($table, 't')
        ->fields('t', [$column])
        ->distinct();

      $result = $query->execute()->fetchCol();
      $referenced_ids = array_merge($referenced_ids, $result);
    }

    $referenced_ids = array_unique($referenced_ids);

    // Find orphans.
    $orphaned_ids = array_diff($all_term_ids, $referenced_ids);

    return array_values($orphaned_ids);
  }

  /**
   * Get all entity IDs of a specific type.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   *
   * @return array
   *   Array of entity IDs.
   */
  protected function getAllEntityIds($entity_type_id) {
    try {
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      $query = $storage->getQuery()->accessCheck(FALSE);
      return $query->execute();
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Get all fields that reference a specific entity type.
   *
   * @param string $target_entity_type
   *   The target entity type being referenced.
   * @param string $field_type
   *   The field type (entity_reference or entity_reference_revisions).
   *
   * @return array
   *   Array of field info with 'table' and 'column' keys.
   */
  public function getAllReferencingFields($target_entity_type, $field_type = 'entity_reference') {
    $fields = [];

    // Get all field definitions.
    $field_map = $this->fieldManager->getFieldMap();

    foreach ($field_map as $entity_type_id => $field_info) {
      foreach ($field_info as $field_name => $field_data) {
        // Check if field type matches.
        if ($field_data['type'] !== $field_type) {
          continue;
        }

        // Get field storage definition.
        $field_storage_definitions = $this->fieldManager->getFieldStorageDefinitions($entity_type_id);

        if (!isset($field_storage_definitions[$field_name])) {
          continue;
        }

        $field_storage = $field_storage_definitions[$field_name];
        $settings = $field_storage->getSettings();

        // Check if field targets our entity type.
        if (isset($settings['target_type']) && $settings['target_type'] === $target_entity_type) {
          // Determine table and column names.
          $table = $entity_type_id . '__' . $field_name;

          // Always use _target_id to compare against entity IDs.
          // Using _target_revision_id would compare revision IDs against
          // entity IDs, causing nearly all entities to appear orphaned.
          $column = $field_name . '_target_id';

          $fields[] = [
            'entity_type' => $entity_type_id,
            'field_name' => $field_name,
            'table' => $table,
            'column' => $column,
          ];
        }
      }
    }

    return $fields;
  }

}
