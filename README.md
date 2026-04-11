
# WARNING #
## Do NOT run Drush commands without `--dry-run`. There is a serious bug affecting node and paragraph deletion.
## Backup your database first.

===============================================================

# Drupal Gung AI
AI-powered Drupal development and automation tools.

## Overview

This module suite provides intelligent automation tools for Drupal development, content management, and site maintenance. All tools are designed with AI assistance to handle complex Drupal operations efficiently and safely.

## Submodules

### Clean Contents (`clean_contents/`)

Drush-based content cleanup and sanitization tool for development and staging environments.

**Features:**
- Delete unpublished content entities
- Remove orphaned entities (paragraphs, media, files, etc.)
- Clean old revisions
- Fix broken path aliases and redirects
- Rename users to test accounts


**Documentation:**
- See `clean_contents/README.md`
- Specification: `SPEC-clean-contents-module.md`
- Implementation session log: `SESSION-clean-contents-implementation.md`

## Installation

Enable the parent module and any desired submodules:

```bash
ddev drush en drupal-gung-ai
ddev drush en clean_contents
```

Or enable submodules directly (they can work independently):

```bash
ddev drush en clean_contents
```

## Development

This module suite is developed with AI assistance (Claude/GitHub Copilot) to accelerate Drupal development and provide battle-tested automation tools.

### Adding New Submodules

1. Create a new directory under `drupal-gung-ai/`
2. Follow Drupal module naming conventions
3. Add comprehensive documentation
4. Include dry-run modes for destructive operations
5. Implement proper error handling and logging

## Best Practices

- Always backup databases before running destructive operations
- Use `--dry-run` flags to preview changes
- Test in development environments first
- Check watchdog logs after operations
- Follow Drupal coding standards

## Support

- Check individual submodule README files for specific documentation
- Review watchdog logs: `drush wd-show`
- Refer to specification documents in the `ai/` directory

## License

GPL-2.0-or-later
