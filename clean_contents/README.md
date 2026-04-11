# WARNING #
## Do NOT run Drush commands without `--dry-run`. There is a serious bug affecting node and paragraph deletion.
## Backup your database first.

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
3. Delete old revisions keeping only the latest
4. Delete broken path aliases and redirects
5. Rename users to sequential test accounts (test1, test2, test3...)

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

**Coding Standards:**
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
