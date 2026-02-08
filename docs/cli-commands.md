# CLI Commands

Hilos includes a command-line interface for management:

```bash
# Database migrations
php cli.php migration:up          # Apply migrations
php cli.php migration:down        # Rollback migrations
php cli.php migration:status      # Check migration status
php cli.php migration:retry       # Retry failed migration

# Database schema
php cli.php db:schema:status      # Check schema status
php cli.php db:entity:fix         # Fix Entity classes
php cli.php db:object:fix         # Fix Object classes
php cli.php db:idea:fix           # Fix Idea classes

# System
php cli.php status                # Daemon status
php cli.php monitor               # Monitor daemon
php cli.php help                  # Show help
```
