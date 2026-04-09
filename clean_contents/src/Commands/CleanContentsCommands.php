<?php

namespace Drupal\clean_contents\Commands;

use Drush\Commands\DrushCommands;
use Drupal\clean_contents\Service\ContentCleanupService;
use Drupal\clean_contents\Service\OrphanDetectionService;

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

    // Set the orphan service on the cleanup service.
    $this->cleanupService->setOrphanService($orphan_service);

    // Set output callback for progress indicators.
    $this->cleanupService->setOutputCallback(function ($message) {
      $this->output()->write($message);
    });
    $this->orphanService->setOutputCallback(function ($message) {
      $this->output()->write($message);
    });
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
    'skip-users' => FALSE,
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
