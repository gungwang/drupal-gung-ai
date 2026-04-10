# Specification: clean_contents Custom Drupal Module

## Overview

**Module Name:** `clean_contents`
**Purpose:** Drush-based content cleanup and sanitization tool for development/staging environments
**Location:** `web/modules/custom/clean_contents/`

Provides Drush commands to:
1. Delete all unpublished content entities (nodes, paragraphs, media, custom entities)
2. Delete orphaned entities not referenced by any parent entity
3. Delete old revisions of content entities (keeping only the latest revision)
4. Delete broken path aliases and redirects (pointing to non-existent content)
5. Rename users to test1, test2, test3... (excluding emails containing "gung")

## Requirements

### 1. Delete Unpublished Content

**Target Entity Types:**
- **Nodes** - All content types (blog, event, news, knowledge, resource, offering, tool, training, page, landing_page, campaign_page, homepage, hub, location, person, etc.)
- **Paragraphs** - All 60+ paragraph types (accordion, hero, video, marketo_form, etc.)
- **Media** - All media types (image, file, video, crc_asset, svg)
- **Custom Content Entities:**
  - `marketo_form` (ul_marketo module)
  - `crc_asset` (ul_crc_asset module)
  - `ul_alert` (ul_alerts module)
  - `ul_legal_hold` (ul_legal_hold module)
- **Block Content** - Custom blocks

**Logic:**
- Query each entity type for unpublished status
- For nodes: `status = 0`
- For other entities: check `status` field if exists, or consider unpublished if in draft/unpublished state
- Cascade deletion: deleting a node should trigger deletion of its directly-referenced paragraphs via entity_reference_revisions
- Report counts before deletion
- Provide dry-run option
- Batch processing for large datasets

**Drush Command:**
```bash
drush clean-contents:delete-unpublished [--dry-run] [--entity-type=TYPE]
Aliases: cc:du
```

### 2. Delete Orphaned Content

**Orphan Detection Strategy:**

**A. Orphaned Paragraphs**
- Paragraphs not referenced by:
  - Any node via entity_reference_revisions fields (field_*_content, field_page_content, etc.)
  - Any other paragraph (nested paragraphs like accordion_items)
  - Any marketo_form entity
- Method: Query all paragraph IDs, then query all entity_reference_revisions fields to find referenced paragraph IDs, identify orphans as the difference

**B. Orphaned Media**
- Media entities not referenced by:
  - Any node via entity_reference fields (field_shared_header_media, field_hero_media, etc.)
  - Any paragraph via entity_reference fields
  - Any block_content
- Method: Query all media IDs, check all entity_reference fields with handler 'default:media', identify unreferenced media

**C. Orphaned Files**
- Files in file_managed table not referenced by:
  - Any media entity (field_media_image, field_media_file, etc.)
  - Any node/paragraph file fields directly
- Method: Query file_usage table first, then check file_managed for files with zero usage

**D. Orphaned Custom Entities**
- `marketo_form` entities not referenced by nodes/paragraphs via entity_reference fields
- `crc_asset` entities not referenced
- Check respective reference fields

**E. Orphaned Taxonomy Terms**
- Terms not referenced by any content
- Method: Query entity_reference fields targeting taxonomy_term, identify unreferenced terms
- Caution: Some terms may be intentionally standalone (do not delete by default)

**F. Orphaned Blocks**
- Block content entities not placed in any block configuration
- Query block_content not referenced in block config entities

**Drush Command:**
```bash
drush clean-contents:delete-orphans [--dry-run] [--entity-type=TYPE] [--include-terms]
Aliases: cc:do
```

### 3. Rename Users

**Requirements:**
- Rename all users to sequential test accounts: test1, test2, test3...
- **Preserve users** whose email contains the string "gung" (case-insensitive)
- Update fields:
  - `name` (username) → "test{N}"
  - `mail` (email) → "test{N}@example.com"
  - Optionally reset passwords to a standard value (e.g., "Test1234!")
- Exclude UID 0 (anonymous) and UID 1 (admin) from renaming
- Maintain UID sequence for consistency
- Provide dry-run option

**Logic:**
```
1. Query all users WHERE uid > 1 ORDER BY uid
2. Filter out users WHERE mail LIKE '%gung%'
3. For remaining users, iterate with counter:
   - user->setUsername("test{counter}")
   - user->setEmail("test{counter}@example.com")
   - user->setPassword(user_password("Test1234!"))
   - user->save()
   - counter++
4. Report renamed count
```

**Drush Command:**
```bash
drush clean-contents:rename-users [--dry-run] [--password=PASS]
Aliases: cc:ru
```

### 4. Delete Old Revisions

**Requirements:**
- Delete all old revisions of revisioned content entities
- Keep only the latest/current revision for each entity
- **Target Entity Types:**
  - Nodes (all revisioned content types)
  - Paragraphs (revisioned)
  - Media (if revisioned)
  - Custom revisioned entities (marketo_form, ul_alert, ul_legal_hold)
- Report total revisions deleted
- Provide dry-run option
- Batch processing for large datasets

**Logic:**
```
1. For each revisioned entity type:
   a. Get all entities with multiple revisions
   b. For each entity, get all revision IDs
   c. Identify the current/default revision ID
   d. Delete all revision IDs except the current one
2. Report count of deleted revisions per entity type
3. Process in batches to avoid memory issues
```

**Benefits:**
- Reduces database size significantly
- Improves performance of revision-related queries
- Simplifies content structure for development/staging
- Removes revision history clutter

**Important Notes:**
- This is a destructive operation that cannot be reversed
- All revision history will be permanently lost
- Only the current published/draft version will remain
- Useful for development/staging environments where revision history is not needed

**Drush Command:**
```bash
drush clean-contents:delete-old-revisions [--dry-run] [--entity-type=TYPE]
Aliases: cc:dr
```

### 4. Delete Broken Path Aliases and Redirects

**Requirements:**
- Delete path aliases pointing to deleted content
- Delete redirects pointing to non-existent paths
- **Target Tables:**
  - `path_alias` - Drupal path aliases
  - `redirect` - Redirect entities (if redirect module is enabled)
- Report total broken aliases and redirects deleted
- Provide dry-run option

**Logic:**
```
1. Path Aliases Cleanup:
   a. Query all path aliases from path_alias table
   b. For each alias, extract entity type and ID from path (e.g., /node/123)
   c. Check if entity exists in database
   d. If entity doesn't exist, mark alias as broken
   e. Delete broken aliases

2. Redirects Cleanup (if redirect module exists):
   a. Query all redirect entities
   b. For each redirect, check if redirect_redirect__uri points to existing content
   c. Check if source path still makes sense
   d. Delete redirects pointing to non-existent content

3. Report counts of deleted aliases and redirects
```

**Benefits:**
- Removes 404 errors from broken aliases
- Cleans up redirect loops and dead redirects
- Improves site performance by removing unnecessary database records
- Simplifies URL alias table for development/staging

**Important Notes:**
- Only deletes aliases/redirects pointing to deleted content
- Does not affect valid aliases/redirects
- Useful after bulk content deletion
- Redirect module is optional - command works without it

**Drush Command:**
```bash
drush clean-contents:delete-broken-paths [--dry-run]
Aliases: cc:dbp
```

**Examples:**
```bash
# Preview broken aliases and redirects
ddev drush cc:dbp --dry-run

# Delete all broken path aliases and redirects
ddev drush cc:dbp

# Check broken aliases via SQL
ddev drush sqlq "SELECT path, alias FROM path_alias WHERE path LIKE '/node/%'"

# Count total aliases
ddev drush sqlq "SELECT COUNT(*) FROM path_alias"
```

### 5. Rename Users

**Drush Command:**
```bash
drush clean-contents:cleanup-all [--dry-run] [--skip-unpublished] [--skip-orphans] [--skip-revisions] [--skip-users]
Aliases: cc:all
```

**Order:**
1. Delete unpublished content (optional)
2. Delete orphaned content (optional)
3. Delete old revisions (optional)
4. Delete broken path aliases and redirects (optional)
5. Rename users (optional)

## Module Structure

```
web/modules/custom/clean_contents/
├── clean_contents.info.yml
├── clean_contents.services.yml
├── composer.json (optional)
├── src/
│   ├── Commands/
│   │   └── CleanContentsCommands.php
│   └── Service/
│       ├── ContentCleanupService.php
│       └── OrphanDetectionService.php
└── README.md
```

## File Specifications

### clean_contents.info.yml
```yaml
name: 'Clean Contents'
type: module
description: 'Drush commands for cleaning unpublished and orphaned content entities, and sanitizing users'
core_version_requirement: ^9 || ^10 || ^11
package: 'UL Custom'
dependencies:
  - drupal:node
  - drupal:paragraphs
  - drupal:media
  - drupal:user
```

### clean_contents.services.yml
```yaml
services:
  clean_contents.cleanup_service:
    class: Drupal\clean_contents\Service\ContentCleanupService
    arguments: ['@entity_type.manager', '@database', '@logger.factory']

  clean_contents.orphan_detection_service:
    class: Drupal\clean_contents\Service\OrphanDetectionService
    arguments: ['@entity_type.manager', '@database', '@entity_field.manager']
```

### CleanContentsCommands.php

**Pattern:** Follow existing drush command patterns from UlDrushCommands and ReportCommands

**Class Structure:**
```php
<?php

namespace Drupal\clean_contents\Commands;

use Drush\Commands\DrushCommands;
use Drupal\clean_contents\Service\ContentCleanupService;
use Drupal\clean_contents\Service\OrphanDetectionService;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;

/**
 * Drush commands for cleaning unpublished and orphaned content.
 */
class CleanContentsCommands extends DrushCommands {

  /**
   * The content cleanup service.
   *
   * @var \Drupal\clean_contents\Service\ContentCleanupService
   */
  protected $cleanupService;

  /**
   * The orphan detection service.
   *
   * @var \Drupal\clean_contents\Service\OrphanDetectionService
   */
  protected $orphanService;

  /**
   * Constructs a CleanContentsCommands object.
   *
   * @param \Drupal\clean_contents\Service\ContentCleanupService $cleanup_service
   *   The content cleanup service.
   * @param \Drupal\clean_contents\Service\OrphanDetectionService $orphan_service
   *   The orphan detection service.
   */
  public function __construct(
    ContentCleanupService $cleanup_service,
    OrphanDetectionService $orphan_service
  ) {
    parent::__construct();
    $this->cleanupService = $cleanup_service;
    $this->orphanService = $orphan_service;
  }

  /**
   * Delete all unpublished content entities.
   *
   * WARNING: This is a destructive operation. Always backup your database first.
   *
   * @command clean-contents:delete-unpublished
   * @aliases cc:du
   * @option dry-run Run in dry-run mode without deleting
   * @option entity-type Specific entity type to clean (node, paragraph, media, etc.)
   * @usage clean-contents:delete-unpublished --dry-run
   *   Preview which unpublished entities will be deleted
   * @usage clean-contents:delete-unpublished --entity-type=node
   *   Delete only unpublished nodes
   */
  public function deleteUnpublished(array $options = ['dry-run' => FALSE, 'entity-type' => NULL]) {
    $dry_run = $options['dry-run'];
    $entity_type = $options['entity-type'];

    $this->output()->writeln('');
    $this->output()->writeln('<info>Clean Contents: Delete Unpublished Entities</info>');
    $this->output()->writeln('====================================');

    if ($dry_run) {
      $this->output()->writeln('<comment>DRY RUN MODE - No entities will be deleted</comment>');
    }
    else {
      $this->output()->writeln('<error>WARNING: This will permanently delete entities! BACK UP THE DATABASE FIRST!!! BACK UP THE DATABASE FIRST!!!</error>');
      if (!$this->io()->confirm('Are you sure you want to continue?', FALSE)) {
        $this->output()->writeln('<comment>Operation cancelled.</comment>');
        return;
      }
    }

    $results = $this->cleanupService->deleteUnpublished($entity_type, $dry_run);

    $this->output()->writeln('');
    $this->output()->writeln('<info>Results:</info>');
    foreach ($results as $type => $count) {
      $this->output()->writeln("  $type: $count entities " . ($dry_run ? 'found' : 'deleted'));
    }
    $this->output()->writeln('');
  }

  /**
   * Delete orphaned content entities.
   *
   * WARNING: This is a destructive operation. Always backup your database first.
   *
   * @command clean-contents:delete-orphans
   * @aliases cc:do
   * @option dry-run Run in dry-run mode without deleting
   * @option entity-type Specific entity type to clean
   * @option include-terms Include taxonomy terms in orphan cleanup
   * @usage clean-contents:delete-orphans --dry-run
   *   Preview which orphaned entities will be deleted
   * @usage clean-contents:delete-orphans --entity-type=paragraph
   *   Delete only orphaned paragraphs
   */
  public function deleteOrphans(array $options = ['dry-run' => FALSE, 'entity-type' => NULL, 'include-terms' => FALSE]) {
    $dry_run = $options['dry-run'];
    $entity_type = $options['entity-type'];
    $include_terms = $options['include-terms'];

    $this->output()->writeln('');
    $this->output()->writeln('<info>Clean Contents: Delete Orphaned Entities</info>');
    $this->output()->writeln('====================================');

    if ($dry_run) {
      $this->output()->writeln('<comment>DRY RUN MODE - No entities will be deleted</comment>');
    }
    else {
      $this->output()->writeln('<error>WARNING: This will permanently delete entities! BACK UP THE DATABASE FIRST!!! BACK UP THE DATABASE FIRST!!!</error>');
      if (!$this->io()->confirm('Are you sure you want to continue?', FALSE)) {
        $this->output()->writeln('<comment>Operation cancelled.</comment>');
        return;
      }
    }

    $results = $this->cleanupService->deleteOrphaned($entity_type, $include_terms, $dry_run);

    $this->output()->writeln('');
    $this->output()->writeln('<info>Results:</info>');
    foreach ($results as $type => $count) {
      $this->output()->writeln("  $type: $count orphaned entities " . ($dry_run ? 'found' : 'deleted'));
    }
    $this->output()->writeln('');
  }

  /**
   * Delete old revisions keeping only the latest.
   *
   * WARNING: This is a destructive operation. Always backup your database first.
   *
   * @command clean-contents:delete-old-revisions
   * @aliases cc:dr
   * @option dry-run Run in dry-run mode without deleting
   * @option entity-type Specific entity type to clean (node, paragraph, media, etc.)
   * @usage clean-contents:delete-old-revisions --dry-run
   *   Preview how many old revisions will be deleted
   * @usage clean-contents:delete-old-revisions --entity-type=node
   *   Delete old revisions for nodes only
   */
  public function deleteOldRevisions(array $options = ['dry-run' => FALSE, 'entity-type' => NULL]) {
    $dry_run = $options['dry-run'];
    $entity_type = $options['entity-type'];

    $this->output()->writeln('');
    $this->output()->writeln('<info>Clean Contents: Delete Old Revisions</info>');
    $this->output()->writeln('====================================');

    if ($dry_run) {
      $this->output()->writeln('<comment>DRY RUN MODE - No revisions will be deleted</comment>');
    }
    else {
      $this->output()->writeln('<error>WARNING: This will permanently delete revision history!</error>');
      if (!$this->io()->confirm('Are you sure you want to continue?', FALSE)) {
        $this->output()->writeln('<comment>Operation cancelled.</comment>');
        return;
      }
    }

    $results = $this->cleanupService->deleteOldRevisions($entity_type, $dry_run);

    $this->output()->writeln('');
    $this->output()->writeln('<info>Results:</info>');
    foreach ($results as $type => $count) {
      $this->output()->writeln("  $type: $count old revisions " . ($dry_run ? 'found' : 'deleted'));
    }
    $this->output()->writeln('');
  }

  /**
   * Delete broken path aliases and redirects.
   *
   * WARNING: This is a destructive operation. Always backup your database first.
   *
   * @command clean-contents:delete-broken-paths
   * @aliases cc:dbp
   * @option dry-run Run in dry-run mode without deleting
   * @usage clean-contents:delete-broken-paths --dry-run
   *   Preview which broken aliases and redirects will be deleted
   * @usage clean-contents:delete-broken-paths
   *   Delete all broken path aliases and redirects
   */
  public function deleteBrokenPaths(array $options = ['dry-run' => FALSE]) {
    $dry_run = $options['dry-run'];

    $this->output()->writeln('');
    $this->output()->writeln('<info>Clean Contents: Delete Broken Path Aliases and Redirects</info>');
    $this->output()->writeln('====================================');

    if ($dry_run) {
      $this->output()->writeln('<comment>DRY RUN MODE - No aliases/redirects will be deleted</comment>');
    }
    else {
      $this->output()->writeln('<error>WARNING: This will permanently delete broken paths!</error>');
      if (!$this->io()->confirm('Are you sure you want to continue?', FALSE)) {
        $this->output()->writeln('<comment>Operation cancelled.</comment>');
        return;
      }
    }

    $results = $this->cleanupService->deleteBrokenPaths($dry_run);

    $this->output()->writeln('');
    $this->output()->writeln('<info>Results:</info>');
    $this->output()->writeln("  path_alias: {$results['path_alias']} broken aliases " . ($dry_run ? 'found' : 'deleted'));
    if (isset($results['redirect'])) {
      $this->output()->writeln("  redirect: {$results['redirect']} broken redirects " . ($dry_run ? 'found' : 'deleted'));
    }
    $this->output()->writeln('');
  }

  /**
   * Rename users to test1, test2, test3... except emails containing "gung".
   *
   * WARNING: This modifies user accounts. Always backup your database first.
   *
   * @command clean-contents:rename-users
   * @aliases cc:ru
   * @option dry-run Run in dry-run mode without renaming
   * @option password Set password for renamed users (default: Test1234!)
   * @usage clean-contents:rename-users --dry-run
   *   Preview which users will be renamed
   * @usage clean-contents:rename-users --password="MyPass123"
   *   Rename users and set custom password
   */
  public function renameUsers(array $options = ['dry-run' => FALSE, 'password' => 'Test1234!']) {
    $dry_run = $options['dry-run'];
    $password = $options['password'];

    $this->output()->writeln('');
    $this->output()->writeln('<info>Clean Contents: Rename Users</info>');
    $this->output()->writeln('====================================');

    if ($dry_run) {
      $this->output()->writeln('<comment>DRY RUN MODE - No users will be renamed</comment>');
    }
    else {
      $this->output()->writeln('<error>WARNING: This will permanently rename user accounts!</error>');
      if (!$this->io()->confirm('Are you sure you want to continue?', FALSE)) {
        $this->output()->writeln('<comment>Operation cancelled.</comment>');
        return;
      }
    }

    $result = $this->cleanupService->renameUsers($password, $dry_run);

    $this->output()->writeln('');
    $this->output()->writeln('<info>Results:</info>');
    $this->output()->writeln("  {$result['renamed']} users " . ($dry_run ? 'will be renamed' : 'renamed'));
    $this->output()->writeln("  {$result['preserved']} users preserved (UID 1, anonymous, 'gung' emails)");
    $this->output()->writeln('');
  }

  /**
   * Run all cleanup operations.
   *
   * WARNING: This is a destructive operation. Always backup your database first.
   *
   * @command clean-contents:cleanup-all
   * @aliases cc:all
   * @option dry-run Run in dry-run mode
   * @option skip-unpublished Skip unpublished content deletion
   * @option skip-orphans Skip orphaned content deletion
   * @option skip-revisions Skip old revision deletion
   * @option skip-broken-paths Skip broken path aliases/redirects deletion
   * @option skip-users Skip user renaming
   * @usage clean-contents:cleanup-all --dry-run
   *   Preview all cleanup operations
   * @usage clean-contents:cleanup-all --skip-users
   *   Run cleanup without renaming users
   */
  public function cleanupAll(array $options = [
    'dry-run' => FALSE,
    'skip-unpublished' => FALSE,
    'skip-orphans' => FALSE,
    'skip-revisions' => FALSE,
    'skip-broken-paths' => FALSE,
    'skip-users' => FALSE
  ]) {
    $dry_run = $options['dry-run'];

    $this->output()->writeln('');
    $this->output()->writeln('<info>===========================================</info>');
    $this->output()->writeln('<info>Clean Contents: Complete Cleanup</info>');
    $this->output()->writeln('<info>===========================================</info>');
    $this->output()->writeln('');

    if ($dry_run) {
      $this->output()->writeln('<comment>DRY RUN MODE - No changes will be made</comment>');
    }
    else {
      $this->output()->writeln('<error>WARNING: This will permanently modify your database!</error>');
      $this->output()->writeln('<error>Make sure you have a backup before proceeding.</error>');
      if (!$this->io()->confirm('Are you sure you want to continue?', FALSE)) {
        $this->output()->writeln('<comment>Operation cancelled.</comment>');
        return;
      }
    }

    $this->output()->writeln('');

    // Delete unpublished content.
    if (!$options['skip-unpublished']) {
      $this->deleteUnpublished(['dry-run' => $dry_run, 'entity-type' => NULL]);
    }

    // Delete orphaned content.
    if (!$options['skip-orphans']) {
      $this->deleteOrphans(['dry-run' => $dry_run, 'entity-type' => NULL, 'include-terms' => FALSE]);
    }

    // Delete old revisions.
    if (!$options['skip-revisions']) {
      $this->deleteOldRevisions(['dry-run' => $dry_run, 'entity-type' => NULL]);
    }

    // Delete broken path aliases and redirects.
    if (!$options['skip-broken-paths']) {
      $this->deleteBrokenPaths(['dry-run' => $dry_run]);
    }

    // Rename users.
    if (!$options['skip-users']) {
      $this->renameUsers(['dry-run' => $dry_run, 'password' => 'Test1234!']);
    }

    $this->output()->writeln('');
    $this->output()->writeln('<info>===========================================</info>');
    $this->output()->writeln('<info>Cleanup Complete!</info>');
    $this->output()->writeln('<info>===========================================</info>');
    $this->output()->writeln('');
  }
}
```

### ContentCleanupService.php

**Responsibilities:**
- Delete unpublished entities by type
- Delete orphaned entities
- Delete old revisions keeping only the latest
- Delete broken path aliases and redirects
- Rename users
- Batch processing for large operations
- Logging and reporting

**Complete Class:**
```php
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

      $query = $storage->getQuery()
        ->condition('status', 0)
        ->accessCheck(FALSE);

      $ids = $query->execute();

      if (empty($ids)) {
        return 0;
      }

      $count = count($ids);

      if (!$dry_run) {
        $this->deleteEntityBatch($entity_type_id, $ids);
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

    $types_to_check = [];

    if ($entity_type_id) {
      $types_to_check[$entity_type_id] = 'findOrphaned' . ucfirst($entity_type_id) . 's';
    }
    else {
      $types_to_check = [
        'paragraph' => 'findOrphanedParagraphs',
        'media' => 'findOrphanedMedia',
        'file' => 'findOrphanedFiles',
        'marketo_form' => 'findOrphanedMarketoForms',
        'crc_asset' => 'findOrphanedCrcAssets',
      ];

      if ($include_terms) {
        $types_to_check['taxonomy_term'] = 'findOrphanedTerms';
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
        $this->deleteEntityBatch($type, $orphaned_ids);
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

      // Get all entity IDs.
      $query = $storage->getQuery()->accessCheck(FALSE);
      $entity_ids = $query->execute();

      if (empty($entity_ids)) {
        return 0;
      }

      $revision_count = 0;

      // Process each entity.
      foreach ($entity_ids as $entity_id) {
        // Get all revision IDs for this entity.
        $revision_ids = $storage->revisionIds($entity_id);

        // Skip if entity has only one revision.
        if (count($revision_ids) <= 1) {
          continue;
        }

        // Load the entity to get the current revision ID.
        $entity = $storage->load($entity_id);
        if (!$entity) {
          continue;
        }

        $current_revision_id = $entity->getRevisionId();

        // Delete all revisions except the current one.
        foreach ($revision_ids as $revision_id) {
          if ($revision_id != $current_revision_id) {
            if (!$dry_run) {
              try {
                $storage->deleteRevision($revision_id);
              }
              catch (\Exception $e) {
                $this->logger->error('Error deleting revision @rid of @type @id: @message', [
                  '@rid' => $revision_id,
                  '@type' => $entity_type_id,
                  '@id' => $entity_id,
                  '@message' => $e->getMessage(),
                ]);
              }
            }
            $revision_count++;
          }
        }
      }

      if (!$dry_run && $revision_count > 0) {
        $this->logger->info('Deleted @count old revisions of @type entities.', [
          '@count' => $revision_count,
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
      // Query all path aliases.
      $query = $this->database->select('path_alias', 'pa')
        ->fields('pa', ['id', 'path', 'alias']);

      $aliases = $query->execute()->fetchAll();

      foreach ($aliases as $alias_record) {
        // Parse the internal path (e.g., /node/123).
        if (preg_match('#^/(\\w+)/(\\d+)$#', $alias_record->path, $matches)) {
          $entity_type = $matches[1];
          $entity_id = $matches[2];

          // Check if entity exists.
          if ($this->entityTypeManager->hasDefinition($entity_type)) {
            $storage = $this->entityTypeManager->getStorage($entity_type);
            $entity = $storage->load($entity_id);

            if (!$entity) {
              // Entity doesn't exist - alias is broken.
              if (!$dry_run) {
                $this->database->delete('path_alias')
                  ->condition('id', $alias_record->id)
                  ->execute();
              }
              $broken_count++;
            }
          }
        }
      }

      if (!$dry_run && $broken_count > 0) {
        $this->logger->info('Deleted @count broken path aliases.', [
          '@count' => $broken_count,
        ]);
      }

      return $broken_count;
    }
    catch (\\Exception $e) {
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

      $redirects = $storage->loadMultiple($redirect_ids);
      $broken_redirects = [];

      foreach ($redirects as $redirect) {
        // Get the redirect target URI.
        $redirect_uri = $redirect->get('redirect_redirect')->get(0)->getValue();

        if (isset($redirect_uri['uri'])) {
          $uri = $redirect_uri['uri'];

          // Check if it's an internal URI pointing to content.
          if (preg_match('#^internal:/(\\w+)/(\\d+)#', $uri, $matches) ||
              preg_match('#^entity:(\\w+)/(\\d+)#', $uri, $matches)) {
            $entity_type = $matches[1];
            $entity_id = $matches[2];

            // Check if entity exists.
            if ($this->entityTypeManager->hasDefinition($entity_type)) {
              $target_storage = $this->entityTypeManager->getStorage($entity_type);
              $entity = $target_storage->load($entity_id);

              if (!$entity) {
                // Target entity doesn't exist - redirect is broken.
                $broken_redirects[] = $redirect->id();
                $broken_count++;
              }
            }
          }
        }
      }

      if (!$dry_run && !empty($broken_redirects)) {
        $entities_to_delete = $storage->loadMultiple($broken_redirects);
        $storage->delete($entities_to_delete);

        $this->logger->info('Deleted @count broken redirects.', [
          '@count' => $broken_count,
        ]);
      }

      return $broken_count;
    }
    catch (\\Exception $e) {
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

    foreach ($batches as $batch) {
      try {
        $entities = $storage->loadMultiple($batch);
        $storage->delete($entities);
      }
      catch (\Exception $e) {
        $this->logger->error('Error deleting @type entities: @message', [
          '@type' => $entity_type_id,
          '@message' => $e->getMessage(),
        ]);
      }
    }
  }
}
```

### OrphanDetectionService.php

**Responsibilities:**
- Detect orphaned paragraphs
- Detect orphaned media
- Detect orphaned files
- Detect orphaned custom entities
- Detect orphaned taxonomy terms (optional)
- Discover entity reference fields dynamically

**Complete Class:**
```php
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
   * Find orphaned paragraphs.
   *
   * @return array
   *   Array of orphaned paragraph IDs.
   */
  public function findOrphanedParagraphs() {
    // Get all paragraph IDs.
    $all_paragraph_ids = $this->getAllEntityIds('paragraph');

    if (empty($all_paragraph_ids)) {
      return [];
    }

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
    }

    $referenced_ids = array_unique($referenced_ids);

    // Find orphans.
    $orphaned_ids = array_diff($all_paragraph_ids, $referenced_ids);

    return array_values($orphaned_ids);
  }

  /**
   * Find orphaned media entities.
   *
   * @return array
   *   Array of orphaned media IDs.
   */
  public function findOrphanedMedia() {
    // Get all media IDs.
    $all_media_ids = $this->getAllEntityIds('media');

    if (empty($all_media_ids)) {
      return [];
    }

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
    }

    $referenced_ids = array_unique($referenced_ids);

    // Find orphans.
    $orphaned_ids = array_diff($all_media_ids, $referenced_ids);

    return array_values($orphaned_ids);
  }

  /**
   * Find orphaned files.
   *
   * @return array
   *   Array of orphaned file IDs.
   */
  public function findOrphanedFiles() {
    // Query files with zero usage count.
    $query = $this->database->select('file_managed', 'fm')
      ->fields('fm', ['fid'])
      ->leftJoin('file_usage', 'fu', 'fm.fid = fu.fid')
      ->isNull('fu.fid');

    $orphaned_ids = $query->execute()->fetchCol();

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

    // Get all marketo_form IDs.
    $all_form_ids = $this->getAllEntityIds('marketo_form');

    if (empty($all_form_ids)) {
      return [];
    }

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
    }

    $referenced_ids = array_unique($referenced_ids);

    // Find orphans.
    $orphaned_ids = array_diff($all_form_ids, $referenced_ids);

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

    // Get all crc_asset IDs.
    $all_asset_ids = $this->getAllEntityIds('crc_asset');

    if (empty($all_asset_ids)) {
      return [];
    }

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
    }

    $referenced_ids = array_unique($referenced_ids);

    // Find orphans.
    $orphaned_ids = array_diff($all_asset_ids, $referenced_ids);

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

          if ($field_type === 'entity_reference_revisions') {
            $column = $field_name . '_target_revision_id';
          }
          else {
            $column = $field_name . '_target_id';
          }

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
```

### README.md

**Complete Documentation:**
```markdown
# Clean Contents Module

Drush-based content cleanup and sanitization tool for Drupal development and staging environments.

## ⚠️ WARNING

**This module contains destructive operations!**

- Always backup your database before running any commands
- Test commands with `--dry-run` first
- Never run on production environments
- Commands cannot be reversed once executed

## Purpose

This module provides Drush commands to:
1. Delete all unpublished content entities (nodes, paragraphs, media, custom entities)
2. Delete orphaned entities not referenced by any parent entity
3. Rename users to sequential test accounts (test1, test2, test3...)

## Installation

1. Place this module in `web/modules/custom/clean_contents/`
2. Enable the module:
   ```bash
   ddev drush en clean_contents
   ```

## Commands

### 1. Delete Unpublished Content

```bash
drush clean-contents:delete-unpublished [--dry-run] [--entity-type=TYPE]
drush cc:du  # Short alias
```

**What it does:**
- Deletes unpublished nodes, paragraphs, media, block_content
- Deletes unpublished custom entities (marketo_form, crc_asset, ul_alert, ul_legal_hold)
- Cascade deletion: removing a node triggers deletion of its referenced paragraphs

**Options:**
- `--dry-run` - Preview what will be deleted without making changes
- `--entity-type=TYPE` - Limit deletion to specific entity type (node, paragraph, media, etc.)

**Examples:**
```bash
# Preview unpublished content
ddev drush cc:du --dry-run

# Delete all unpublished content
ddev drush cc:du

# Delete only unpublished nodes
ddev drush cc:du --entity-type=node
```

### 2. Delete Orphaned Content

```bash
drush clean-contents:delete-orphans [--dry-run] [--entity-type=TYPE] [--include-terms]
drush cc:do  # Short alias
```

**What it does:**
- Detects orphaned paragraphs (not referenced by any node/paragraph)
- Detects orphaned media (not referenced by nodes/paragraphs/blocks)
- Detects orphaned files (not referenced by media or file fields)
- Detects orphaned custom entities (marketo_form, crc_asset not referenced)

**Options:**
- `--dry-run` - Preview what will be deleted without making changes
- `--entity-type=TYPE` - Limit deletion to specific entity type
- `--include-terms` - Include taxonomy terms in orphan cleanup (opt-in)

**Examples:**
```bash
# Preview orphaned content
ddev drush cc:do --dry-run

# Delete all orphaned content
ddev drush cc:do

# Delete only orphaned paragraphs
ddev drush cc:do --entity-type=paragraph

# Include taxonomy term cleanup
ddev drush cc:do --include-terms
```

### 3. Delete Old Revisions

```bash
drush clean-contents:delete-old-revisions [--dry-run] [--entity-type=TYPE]
drush cc:dr  # Short alias
```

**What it does:**
- Deletes all old revisions of revisioned content entities
- Keeps only the latest/current revision for each entity
- Targets: nodes, paragraphs, media (if revisioned), and custom revisioned entities (marketo_form, ul_alert, ul_legal_hold)
- Significantly reduces database size
- Improves performance of revision-related queries

**Important:**
- **All revision history will be permanently lost**
- This is a destructive operation that cannot be reversed
- Only the current published/draft version will remain
- Useful for development/staging environments where revision history is not needed

**Options:**
- `--dry-run` - Preview how many old revisions will be deleted without making changes
- `--entity-type=TYPE` - Limit deletion to specific entity type (node, paragraph, media, etc.)

**Examples:**
```bash
# Preview old revisions that will be deleted
ddev drush cc:dr --dry-run

# Delete all old revisions from all revisioned entity types
ddev drush cc:dr

# Delete old revisions for nodes only
ddev drush cc:dr --entity-type=node

# Check how many node revisions exist before cleanup
ddev drush sqlq "SELECT entity_id, COUNT(*) as rev_count FROM node_revision GROUP BY entity_id HAVING rev_count > 1"
```

### 4. Delete Broken Path Aliases and Redirects

```bash
drush clean-contents:delete-broken-paths [--dry-run]
drush cc:dbp  # Short alias
```

**What it does:**
- Deletes path aliases pointing to deleted content
- Deletes redirects pointing to non-existent paths (if redirect module is enabled)
- Improves site performance by removing unnecessary database records
- Removes 404 errors from broken aliases

**Important:**
- Only deletes aliases/redirects pointing to deleted content
- Does not affect valid aliases/redirects
- Useful after bulk content deletion
- Redirect module is optional - command works without it

**Options:**
- `--dry-run` - Preview how many broken paths will be deleted without making changes

**Examples:**
```bash
# Preview broken aliases and redirects
ddev drush cc:dbp --dry-run

# Delete all broken path aliases and redirects
ddev drush cc:dbp

# Check broken aliases via SQL
ddev drush sqlq "SELECT path, alias FROM path_alias WHERE path LIKE '/node/%'"

# Count total aliases
ddev drush sqlq "SELECT COUNT(*) FROM path_alias"

# Check if redirect module is enabled
ddev drush pm:list --filter=redirect
```

### 5. Rename Users

```bash
drush clean-contents:rename-users [--dry-run] [--password=PASS]
drush cc:ru  # Short alias
```

**What it does:**
- Renames users to test1, test2, test3...
- Sets emails to test1@example.com, test2@example.com, etc.
- Resets passwords to configurable value (default: Test1234!)
- **Preserves:**
  - UID 0 (anonymous user)
  - UID 1 (admin user)
  - Any user whose email contains "gung" (case-insensitive)

**Options:**
- `--dry-run` - Preview what will be renamed without making changes
- `--password=PASS` - Set custom password for renamed users (default: Test1234!)

**Examples:**
```bash
# Preview user renaming
ddev drush cc:ru --dry-run

# Rename users with default password
ddev drush cc:ru

# Rename users with custom password
ddev drush cc:ru --password="MyTestPass123"
```

### 6. Cleanup All

```bash
drush clean-contents:cleanup-all [--dry-run] [--skip-unpublished] [--skip-orphans] [--skip-revisions] [--skip-broken-paths] [--skip-users]
drush cc:all  # Short alias
```

**What it does:**
- Runs all cleanup operations in sequence
- Orchestrates complete environment sanitization

**Options:**
- `--dry-run` - Preview all operations without making changes
- `--skip-unpublished` - Skip unpublished content deletion
- `--skip-orphans` - Skip orphaned content deletion
- `--skip-revisions` - Skip old revision deletion
- `--skip-broken-paths` - Skip broken path aliases/redirects deletion
- `--skip-users` - Skip user renaming

**Examples:**
```bash
# Preview complete cleanup
ddev drush cc:all --dry-run

# Run complete cleanup
ddev drush cc:all

# Run cleanup without renaming users
ddev drush cc:all --skip-users

# Only delete unpublished content and old revisions
ddev drush cc:all --skip-orphans --skip-broken-paths --skip-users

# Skip only broken paths cleanup
ddev drush cc:all --skip-broken-paths
```

## Orphan Detection Strategy

### Paragraphs
1. Query all paragraph IDs from database
2. Find all entity_reference_revisions fields that target paragraphs
3. Query these fields to get all referenced paragraph IDs
4. Identify orphans: paragraphs not in the referenced list

### Media
1. Query all media IDs
2. Find all entity_reference fields that target media
3. Query these fields to get all referenced media IDs
4. Identify orphans: media not in the referenced list

### Files
1. Query file_usage table for file usage counts
2. Identify files with zero usage
3. These are candidates for deletion

### Custom Entities
- Same pattern as media: find all reference fields, compare with existing entities

## Entity Types Handled

**Core Entity Types:**
- `node` - All content type bundles
- `paragraph` - All paragraph type bundles
- `media` - All media type bundles
- `block_content` - Custom blocks
- `taxonomy_term` - All vocabularies (opt-in with --include-terms)
- `file` - Managed files

**Custom Content Entity Types:**
- `marketo_form` - Marketo form entities (ul_marketo module)
- `crc_asset` - CRC asset entities (ul_crc_asset module)
- `ul_alert` - Alert entities (ul_alerts module)
- `ul_legal_hold` - Legal hold entities (ul_legal_hold module)

## Safety Features

1. **Dry-run mode** - All commands support --dry-run to preview changes
2. **Confirmation prompts** - Interactive confirmation before destructive operations
3. **Batch processing** - Processes entities in batches of 50 to prevent memory exhaustion
4. **Error handling** - Continues processing if individual entity deletion fails
5. **Logging** - All operations logged to watchdog (clean_contents channel)
6. **Targeted deletion** - Can limit operations to specific entity types

## Performance

- Batch processing: 50 entities per batch (configurable in service)
- Optimized database queries for orphan detection
- Can handle 10,000+ entities without timeout
- Progress reporting for long operations

## Troubleshooting

**"Entity type does not exist" errors**
- Some custom entity types may not be enabled
- Module will skip entity types that don't exist

**Memory errors**
- Batch size can be adjusted in ContentCleanupService::$batchSize
- Try limiting operations with --entity-type option

**Permission errors**
- Run commands as user with sufficient permissions
- Commands use accessCheck(FALSE) to bypass access control

**Slow performance**
- Large datasets may take time to process
- Orphan detection involves multiple database queries
- Consider using --entity-type to process one type at a time

## Best Practices

1. **Always backup first** - Use `ddev snapshot` or export database
2. **Test with dry-run** - Always run with --dry-run before actual deletion
3. **Verify results** - Check counts and entity types in dry-run output
4. **Run in sequence** - If running all operations, use cleanup-all command
5. **Monitor logs** - Check watchdog logs after operations
6. **Test incrementally** - Start with one entity type before doing all

## Validation

After running cleanup operations:

```bash
# Count unpublished nodes
ddev drush sqlq "SELECT COUNT(*) FROM node_field_data WHERE status = 0"

# Count orphaned paragraphs
ddev drush cc:do --dry-run --entity-type=paragraph

# List users
ddev drush sqlq "SELECT uid, name, mail FROM users_field_data WHERE uid > 1"

# Check watchdog logs
ddev drush wd-show --type=clean_contents
```

## Development

**Tests:**
- Manual testing steps documented in specification
- Create test content and verify cleanup
- Verify published content and preserved users are untouched

** Coding Standards:**
```bash
ddev composer code-sniff
```

## Support

For issues or questions:
- Check watchdog logs: `drush wd-show --type=clean_contents`
- Review this README and command help: `drush cc:du --help`
- Refer to specification document in ai/SPEC-clean-contents-module.md

## License

GPL-2.0-or-later
```

## Entity Types to Handle

### Core Entity Types
1. **node** - All content type bundles
2. **paragraph** - All paragraph type bundles
3. **media** - All media type bundles
4. **block_content** - Custom blocks
5. **taxonomy_term** - All vocabularies (optional)
6. **file** - Managed files

### Custom Content Entity Types
7. **marketo_form** - Marketo form entities (ul_marketo)
8. **crc_asset** - CRC asset entities (ul_crc_asset)
9. **ul_alert** - Alert entities (ul_alerts)
10. **ul_legal_hold** - Legal hold entities (ul_legal_hold)

## Dependencies & Services

### Service Injection:
- `@entity_type.manager` - EntityTypeManagerInterface
- `@database` - Database Connection
- `@entity_field.manager` - EntityFieldManagerInterface
- `@logger.factory` - LoggerChannelFactoryInterface

### Module Dependencies:
- drupal:node
- drupal:paragraphs
- drupal:media
- drupal:user

### Optional Dependencies (check existence):
- ul_marketo (for marketo_form cleanup)
- ul_crc_asset (for crc_asset cleanup)
- ul_alerts (for ul_alert cleanup)
- ul_legal_hold (for ul_legal_hold cleanup)

## Safety & Best Practices

### Safety Measures
1. **Dry-run by default** - Consider making dry-run the default, require explicit `--execute` flag
2. **Confirmation prompts** - Ask for confirmation before deleting in non-dry-run mode
3. **Detailed reporting** - Output counts and entity types before deletion
4. **Logging** - Log all deletions to watchdog
5. **Batch processing** - Process deletions in batches to avoid memory issues
6. **Transaction support** - Wrap deletions in database transactions where possible
7. **Backup recommendation** - Document requirement to backup database before running

### Performance Considerations
1. Use EntityQuery for most efficient queries
2. Process in batches of 50-100 entities
3. Use direct database queries for orphan detection (more efficient than loading entities)
4. Clear caches periodically during batch processing
5. Report progress for long operations

### Error Handling
1. Catch exceptions during entity deletion
2. Continue processing if individual entity deletion fails
3. Report all errors at end
4. Use try-catch blocks with detailed error logging

## Testing Approach

### Manual Testing Steps
1. **Setup Test Environment**
   - Create unpublished nodes, paragraphs, media
   - Create orphaned paragraphs (delete parent node manually via SQL)
   - Create orphaned media
   - Create test users with various emails
   - Create multiple revisions of content (edit and save nodes multiple times)

2. **Test delete-unpublished**
   - Run with --dry-run, verify counts
   - Run without dry-run, verify deletion
   - Test --entity-type filter
   - Verify published content is untouched

3. **Test delete-orphans**
   - Run with --dry-run, verify orphan detection
   - Run without dry-run, verify deletion
   - Test --entity-type filter
   - Verify referenced entities are untouched

4. **Test delete-old-revisions**
   - Create nodes/paragraphs with 5+ revisions
   - Run with --dry-run, verify revision counts
   - Run without dry-run, verify only latest revision remains
   - Test --entity-type filter
   - Verify current revisions are preserved
   - Check database size reduction

5. **Test delete-broken-paths**
   - Create a node with path alias
   - Delete the node via SQL (keeping the alias orphaned)
   - Create a redirect pointing to deleted content
   - Run with --dry-run, verify broken paths detected
   - Run without dry-run, verify broken aliases/redirects deleted
   - Verify valid aliases/redirects are preserved

6. **Test rename-users**
   - Run with --dry-run, verify user list
   - Verify "gung" email preservation
   - Run without dry-run, verify renaming
   - Verify UID 1 is untouched

7. **Test cleanup-all**
   - Run with --dry-run
   - Run with various skip flags (including --skip-revisions, --skip-broken-paths)
   - Verify combined operation
   - Verify order of operations

### Validation Commands
```bash
# Count unpublished nodes
ddev drush sqlq "SELECT COUNT(*) FROM node_field_data WHERE status = 0"

# Count orphaned paragraphs (requires custom query)
ddev drush clean-contents:delete-orphans --dry-run --entity-type=paragraph

# Count nodes with multiple revisions
ddev drush sqlq "SELECT COUNT(DISTINCT nid) FROM node_revision GROUP BY nid HAVING COUNT(*) > 1"

# Show revision counts per node
ddev drush sqlq "SELECT nid, COUNT(*) as rev_count FROM node_revision GROUP BY nid HAVING rev_count > 1 ORDER BY rev_count DESC LIMIT 10"

# Count total node revisions
ddev drush sqlq "SELECT COUNT(*) FROM node_revision"

# Preview old revisions to be deleted
ddev drush clean-contents:delete-old-revisions --dry-run

# Count all path aliases
ddev drush sqlq "SELECT COUNT(*) FROM path_alias"

# Find potentially broken aliases
ddev drush sqlq "SELECT path, alias FROM path_alias WHERE path LIKE '/node/%' LIMIT 10"

# Preview broken paths to be deleted
ddev drush clean-contents:delete-broken-paths --dry-run

# List users
ddev drush sqlq "SELECT uid, name, mail FROM users_field_data WHERE uid > 1"

# Verify cleanup
ddev drush clean-contents:cleanup-all --dry-run
```

## Documentation Requirements

### README.md Content
1. Module purpose and use cases
2. Installation instructions
3. Command reference with examples
4. Safety warnings and backup recommendations
5. Troubleshooting common issues
6. Performance notes for large sites

### Code Documentation
1. Comprehensive PHPDoc for all methods
2. Inline comments for complex logic
3. Examples in command annotations
4. Service documentation

## Implementation Phases

### Phase 1: Foundation
1. Create module structure and info file
2. Create services.yml
3. Create CleanContentsCommands skeleton
4. Create ContentCleanupService skeleton
5. Create OrphanDetectionService skeleton

### Phase 2: Delete Unpublished
1. Implement deleteUnpublishedNodes()
2. Implement deleteUnpublishedParagraphs()
3. Implement deleteUnpublishedMedia()
4. Implement deleteUnpublishedByEntityType() for custom entities
5. Implement deleteUnpublished() command
6. Add batch processing
7. Add dry-run logic

### Phase 3: Delete Orphans
1. Implement findOrphanedParagraphs()
2. Implement findOrphanedMedia()
3. Implement findOrphanedFiles()
4. Implement findOrphanedMarketoForms()
5. Implement getAllReferencingFields() helper
6. Implement deleteOrphans() command
7. Add dry-run logic

### Phase 4: Delete Old Revisions
1. Implement deleteOldRevisionsByEntityType()
2. Implement deleteOldRevisions() for all revisioned entity types
3. Add revision ID querying logic
4. Add deleteRevision() calls
5. Implement deleteOldRevisions() command
6. Add dry-run logic
7. Add revision count reporting

### Phase 5: Delete Broken Paths
1. Implement deleteBrokenPathAliases()
2. Implement deleteBrokenRedirects()
3. Implement deleteBrokenPaths() for both path aliases and redirects
4. Add path parsing logic for entity detection
5. Add redirect module existence check
6. Implement deleteBrokenPaths() command
7. Add dry-run logic

### Phase 6: Rename Users
1. Implement user query filtering
2. Implement rename logic with sequential naming
3. Implement password reset
4. Implement renameUsers() command
5. Add dry-run logic

### Phase 7: Combined Command
1. Implement cleanupAll() orchestration
2. Add skip flags (including --skip-revisions, --skip-broken-paths)
3. Add comprehensive reporting

### Phase 8: Polish
1. Add logging throughout
2. Optimize queries
3. Add progress reporting
4. Write README.md
5. Test all commands
6. Performance testing

## Reference Files

**Existing patterns to follow:**
- [web/modules/custom/ul_drush_commands/src/Commands/UlDrushCommands.php](../web/modules/custom/ul_drush_commands/src/Commands/UlDrushCommands.php) - Drush command structure, user queries, entity operations
- [web/modules/custom/ul_report/src/Commands/ReportCommands.php](../web/modules/custom/ul_report/src/Commands/ReportCommands.php) - Service injection, command options
- [web/modules/custom/ul_duplicate_aliases/src/Commands/UlDuplicateAliases.php](../web/modules/custom/ul_duplicate_aliases/src/Commands/UlDuplicateAliases.php) - Deletion logic examples
- [web/modules/custom/ul_salesforce/src/UlSalesforceContentGenerator.php](../web/modules/custom/ul_salesforce/src/UlSalesforceContentGenerator.php) - Entity cleanup examples

**Entity configuration references:**
- [config/ul_enterprise_profile/default/node.type.*.yml](../config/ul_enterprise_profile/default/) - Node bundles
- [config/ul_enterprise_profile/default/paragraphs.paragraphs_type.*.yml](../config/ul_enterprise_profile/default/) - Paragraph bundles
- [config/ul_enterprise_profile/default/field.storage.*.yml](../config/ul_enterprise_profile/default/) - Field definitions and reference structures

**Custom entity references:**
- [web/modules/custom/ul_marketo/src/Entity/MarketoForm.php](../web/modules/custom/ul_marketo/src/Entity/MarketoForm.php) - MarketoForm entity
- [web/modules/custom/ul_crc_asset/src/Entity/CRCAsset.php](../web/modules/custom/ul_crc_asset/src/Entity/CRCAsset.php) - CRCAsset entity
- [web/modules/custom/ul_alerts/src/Entity/Alert.php](../web/modules/custom/ul_alerts/src/Entity/Alert.php) - Alert entity

## Acceptance Criteria

### Functional Requirements ✓
- [ ] Drush command `clean-contents:delete-unpublished` deletes all unpublished nodes, paragraphs, media, and custom entities
- [ ] Drush command `clean-contents:delete-orphans` detects and deletes orphaned entities
- [ ] Drush command `clean-contents:delete-old-revisions` deletes old revisions keeping only the latest
- [ ] Drush command `clean-contents:delete-broken-paths` deletes broken path aliases and redirects
- [ ] Drush command `clean-contents:rename-users` renames users excluding "gung" emails
- [ ] Drush command `clean-contents:cleanup-all` runs all operations with configurable skip flags
- [ ] All commands support `--dry-run` mode
- [ ] All commands support filtering by `--entity-type` (where applicable)
- [ ] Commands provide detailed reporting before and after operations
- [ ] Old revision deletion only affects revisioned entity types
- [ ] Current/latest revisions are never deleted
- [ ] Broken path cleanup only affects aliases/redirects pointing to deleted content
- [ ] Valid path aliases and redirects are never deleted

### Safety Requirements ✓
- [ ] Dry-run mode does not modify database
- [ ] Confirmation prompts before destructive operations
- [ ] Batch processing prevents memory exhaustion
- [ ] Error handling prevents partial failures from breaking execution
- [ ] All operations are logged to watchdog

### Performance Requirements ✓
- [ ] Can process 10,000+ entities without timeout
- [ ] Uses batch processing for large datasets
- [ ] Orphan detection uses optimized database queries
- [ ] Progress reporting for long operations

### Code Quality Requirements ✓
- [ ] Follows Drupal coding standards
- [ ] Comprehensive PHPDoc documentation
- [ ] Service-based architecture with dependency injection
- [ ] Modular, testable code
- [ ] Complete README.md with examples

## Open Questions

1. **Destructive Operation Defaults** - Should dry-run be the default with explicit `--execute` flag required? Or current approach with `--dry-run` flag?
   - **Recommendation:** Current approach (--dry-run flag) aligns with Drush conventions, but add prominent warnings in help text

2. **Taxonomy Term Cleanup** - Should orphaned taxonomy terms be deleted by default, or require explicit opt-in?
   - **Recommendation:** Make it opt-in with `--include-terms` flag, as some terms may be intentionally standalone

3. **Revision Cleanup** - Should we also clean up old revisions of deleted entities?
   - **Recommendation:** Drupal's entity deletion should handle this automatically, but verify during testing

4. **User Password Policy** - Should the default password for renamed users be configurable or hardcoded?
   - **Recommendation:** Make it configurable via `--password` option with default of "Test1234!"

5. **Block Content Cleanup** - Should unpublished blocks be deleted, or only orphaned blocks?
   - **Recommendation:** Include blocks in unpublished cleanup and orphan detection

6. **Performance Threshold** - What batch size is optimal for this site's typical dataset?
   - **Recommendation:** Default to 50, make configurable if needed via option

7. **Custom Entity Detection** - Should the module dynamically detect all custom content entity types, or use hardcoded list?
   - **Recommendation:** Use hardcoded list of known custom entities, with option to extend via configuration

## Overview

**Module Name:** `clean_contents`
**Purpose:** Drush-based content cleanup and sanitization tool for development/staging environments
**Location:** `web/modules/custom/clean_contents/`

Provides Drush commands to:
1. Delete all unpublished content entities (nodes, paragraphs, media, custom entities)
2. Delete orphaned entities not referenced by any parent entity
3. Rename users to test1, test2, test3... (excluding emails containing "gung")

---

## Drush Commands

### 1. `clean-contents:delete-unpublished` (alias: `cc:du`)
- Deletes unpublished nodes, paragraphs, media, block_content, and custom entities (marketo_form, crc_asset, ul_alert, ul_legal_hold)
- Options: `--dry-run`, `--entity-type=TYPE`
- Cascade deletion: removing a node triggers deletion of its paragraphs via entity_reference_revisions

### 2. `clean-contents:delete-orphans` (alias: `cc:do`)
- Detects orphaned paragraphs (not referenced by any node/paragraph)
- Detects orphaned media (not referenced by nodes/paragraphs/blocks)
- Detects orphaned files (not referenced by media or file fields)
- Detects orphaned custom entities (marketo_form, crc_asset not referenced)
- Options: `--dry-run`, `--entity-type=TYPE`, `--include-terms`

### 3. `clean-contents:rename-users` (alias: `cc:ru`)
- Renames users to testN, sets email to testN@example.com
- Excludes UID 1, UID 0, and emails containing "gung" (case-insensitive)
- Options: `--dry-run`, `--password=PASS` (default: Test1234!)

### 4. `clean-contents:cleanup-all` (alias: `cc:all`)
- Runs all three operations in sequence
- Options: `--dry-run`, `--skip-unpublished`, `--skip-orphans`, `--skip-users`

---

## Module Structure

web/modules/custom/clean_contents/
├── clean_contents.info.yml
├── clean_contents.services.yml
├── README.md
└── src/
├── Commands/
│ └── CleanContentsCommands.php
└── Service/
├── ContentCleanupService.php
└── OrphanDetectionService.php


---

## Implementation Steps

**Phase 1: Foundation** (*parallel with Phase 2-4*)
1. Create module files: info.yml, services.yml, README skeleton
2. Create CleanContentsCommands, ContentCleanupService, OrphanDetectionService skeletons

**Phase 2: Delete Unpublished** (*parallel with Phase 3-4*)
3. Implement ContentCleanupService methods: deleteUnpublishedNodes(), deleteUnpublishedParagraphs(), deleteUnpublishedMedia(), deleteUnpublishedByEntityType()
4. Implement batch processing (50 entities per batch)
5. Implement deleteUnpublished() command with dry-run logic

**Phase 3: Delete Orphans** (*depends on Phase 1*)
6. Implement OrphanDetectionService.getAllReferencingFields() - finds all entity_reference fields targeting specific entity type
7. Implement findOrphanedParagraphs() - compares all paragraph IDs vs. referenced IDs from entity_reference_revisions fields
8. Implement findOrphanedMedia(), findOrphanedFiles(), findOrphanedMarketoForms() using same pattern
9. Implement deleteOrphans() command with dry-run logic

**Phase 4: Rename Users** (*parallel with Phase 2-3*)
10. Implement user filtering: query users WHERE uid > 1 AND mail NOT LIKE '%gung%'
11. Implement sequential renaming: setUsername("testN"), setEmail("testN@example.com"), setPassword()
12. Implement renameUsers() command with dry-run logic

**Phase 5: Combined & Polish** (*depends on Phase 2-4*)
13. Implement cleanupAll() orchestration with skip flags
14. Add comprehensive logging, progress reporting, error handling
15. Write README.md with safety warnings, usage examples, backup recommendations
16. Test all commands with dry-run and execute modes

---

## Relevant Files

**Patterns to follow:**
- [web/modules/custom/ul_drush_commands/src/Commands/UlDrushCommands.php](../web/modules/custom/ul_drush_commands/src/Commands/UlDrushCommands.php) — Drush command structure, constructor injection (EntityTypeManager, Connection), user query patterns (getValidUsers()), command annotations
- [web/modules/custom/ul_report/src/Commands/ReportCommands.php](../web/modules/custom/ul_report/src/Commands/ReportCommands.php) — Service injection pattern, command options handling
- [web/modules/custom/ul_duplicate_aliases/src/Commands/UlDuplicateAliases.php](../web/modules/custom/ul_duplicate_aliases/src/Commands/UlDuplicateAliases.php) — Entity deletion patterns ($entity->delete())
- [web/modules/custom/ul_salesforce/src/UlSalesforceContentGenerator.php](../web/modules/custom/ul_salesforce/src/UlSalesforceContentGenerator.php) — Cleanup method examples with entity loading and deletion

**Entity references:**
- [web/modules/custom/ul_marketo/src/Entity/MarketoForm.php](../web/modules/custom/ul_marketo/src/Entity/MarketoForm.php) — Custom content entity pattern
- [config/ul_enterprise_profile/default/field.storage.node.*.yml](../config/ul_enterprise_profile/default/) — Entity reference field structures (entity_reference_revisions for paragraphs, entity_reference for media/taxonomy)

---

## Orphan Detection Strategy

**Algorithm (Paragraphs Example):**

Query all paragraph IDs from paragraph_field_data table
Query all entity_reference_revisions fields (field_*_content, etc.) to get referenced paragraph revision IDs
Convert revision IDs to entity IDs
Find orphans: array_diff(all_paragraph_ids, referenced_paragraph_ids)
Delete orphaned paragraphs in batches


**Fields to check for references:**
- Nodes→Paragraphs: field_page_content, field_landing_page_content, field_homepage_content, field_*_content (60+ fields)
- Nodes/Paragraphs→Media: field_shared_header_media, field_hero_media, field_video_media
- Media→Files: field_media_image, field_media_file
- Check via EntityFieldManager to dynamically discover all reference fields

---

## Verification

**Automated checks:**
1. Run `ddev drush clean-contents:delete-unpublished --dry-run` — verify counts match SQL: `SELECT COUNT(*) FROM node_field_data WHERE status = 0`
2. Run `ddev drush clean-contents:delete-orphans --dry-run` — manually verify orphan detection by creating orphaned paragraph (delete parent node via SQL)
3. Run `ddev drush clean-contents:rename-users --dry-run` — verify "gung" emails excluded, UID 1 excluded
4. Run `ddev drush clean-contents:cleanup-all --dry-run` — verify combined report
5. Execute each command without dry-run, verify entities deleted/renamed
6. Check watchdog logs for all operations
7. Run PHPCS: `ddev composer code-sniff`

**Manual validation:**
1. Create test content: unpublished node, orphaned paragraph, test users
2. Run dry-run, verify detection
3. Run execute, verify cleanup
4. Verify published content and "gung" users untouched

---

## Decisions & Safety

**Includes:**
- All content entity types: nodes, paragraphs, media, block_content, marketo_form, crc_asset, ul_alert, ul_legal_hold
- Dry-run mode for all commands
- Batch processing to prevent memory issues
- Detailed reporting before/after operations
- Error handling with continued processing
- Watchdog logging for audit trail

**Excludes:**
- Taxonomy term cleanup (opt-in via `--include-terms` flag)
- Entity revision cleanup (handled automatically by Drupal)
- Configuration entity cleanup
- User deletion (only renaming)

**Safety measures:**
- Dry-run flag defaults to safe preview
- Batch size of 50 prevents memory exhaustion
- Transaction support where possible
- README will emphasize database backup requirement
- Confirmation prompts for destructive operations

---

## Further Considerations

1. **Dry-run default behavior** — Current plan: `--dry-run` flag for preview. Alternative: require explicit `--execute` flag?
   - **Recommendation A** (current): Use `--dry-run` flag (aligns with Drush conventions)
   - **Recommendation B**: Require `--execute` flag (safer, but non-standard)

2. **Orphaned taxonomy term handling** — Should terms be deleted automatically or opt-in?
   - **Recommendation**: Opt-in via `--include-terms` flag (some terms may be standalone references)

3. **Custom entity detection** — Hardcode known entities or dynamically detect all custom content entities?
   - **Recommendation**: Hardcode known list (marketo_form, crc_asset, ul_alert, ul_legal_hold) for predictability

---

## Full Detailed Specification

For complete implementation details including:
- File specifications (clean_contents.info.yml, clean_contents.services.yml)
- Complete class structures with PHPDoc
- Service responsibilities and key methods
- Orphan detection algorithms with code examples
- Complete entity type handling details
- Dependencies & service injection
- Safety & best practices
- Complete testing approach
- Documentation requirements
- Acceptance criteria

Please refer to the session memory plan at `/memories/session/plan.md` or request the full specification document.
