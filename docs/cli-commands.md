# CLI Commands

Hilos includes a command-line interface for management. Commands can be run via `php cli.php <command>` or `composer run <script>` (in demo projects, Composer scripts wrap Docker and CLI).

## System Commands

```bash
php cli.php daemon:status     # Display daemon status and metrics
php cli.php daemon:monitor    # Real-time daemon monitoring (requires TTY)
php cli.php help              # Show help; use "php cli.php help <command>" for command-specific help
```

## Database Migrations

```bash
php cli.php migration:up [options]           # Apply pending migrations
php cli.php migration:down <version> [options]  # Rollback to specific version
php cli.php migration:status [options]       # Check migration status
php cli.php migration:retry <version> [options] # Retry failed migration
```

### Migration options

| Option | Commands | Description |
|--------|----------|-------------|
| `--db-index=<N>` | migration:*, db:* | Database connection index (default: 0) |
| `--to=<version>` | migration:up | Migrate up to specific version only |
| `--force` | migration:up, migration:down | Force despite failures (use with caution) |

## Database Schema

```bash
php cli.php db:schema:status [options]  # Show schema status (tables, columns, indexes)
php cli.php db:entity:diff [options]    # Compare Entity files with database schema
php cli.php db:entity:fix [options]     # Fix Entity files to match schema
php cli.php db:object:fix [options]     # Fix Object files to match Entity files
php cli.php db:idea:fix [options]       # Legacy: fix Idea files to match Object files
```

### db:schema:status options

| Option | Description |
|--------|-------------|
| `--table=<name>` | Show details for specific table only |
| `--verbose` | Show detailed column and index information |

### db:entity:diff options

| Option | Description |
|--------|-------------|
| `--entity-dir=<path>` | Entity files directory (default: auto-detect) |
| `--entity-ns=<ns>` | Entity namespace prefix (default: auto-detect) |
| `--table=<name>` | Show diff for specific table only |

### db:entity:fix options

| Option | Description |
|--------|-------------|
| `--entity-dir=<path>` | Entity files directory (default: auto-detect) |
| `--entity-ns=<ns>` | Entity namespace prefix (default: auto-detect) |
| `--table=<name>` | Fix specific table only |
| `--dry-run` | Show what would be changed without modifying files |

### db:object:fix options

| Option | Description |
|--------|-------------|
| `--object-dir=<path>` | Object files directory (default: auto-detect) |
| `--entity-dir=<path>` | Entity files directory (default: auto-detect) |
| `--table=<name>` | Fix specific table only |
| `--dry-run` | Show what would be changed without modifying files |
| `--force-repair` | Attempt to repair broken Object/ObjectCollection files |

### db:idea:fix options (legacy)

| Option | Description |
|--------|-------------|
| `--idea-dir=<path>` | Legacy Idea files directory (default: auto-detect) |
| `--idea-collection-dir=<path>` | Legacy IdeaCollection files directory (default: auto-detect) |
| `--object-dir=<path>` | Object files directory (default: auto-detect) |
| `--table=<name>` | Fix specific table only |
| `--dry-run` | Show what would be changed without modifying files |
| `--force-repair` | Attempt to repair broken Idea files |

> TODO(hilos-refactor): rename `db:idea:fix` command family to `db:hilos:fix` and align option names (`--db-dir`, `--db-collection-dir`).

## Project-level Composer Scripts (demo example)

Demo projects (e.g. `chat`) define Composer scripts that run commands inside Docker. Run them from the demo project root:

```bash
# Setup & dependencies
composer run setup-env           # Create .env from .env.example if needed
composer run install-deps        # Install backend Composer packages
composer run frontend-install    # Install frontend npm packages

# Daemon
composer run daemon-start        # Start daemon (Docker)
composer run daemon-stop         # Stop daemon
composer run daemon-restart      # Restart daemon
composer run daemon-status       # Daemon status via CLI
composer run daemon-monitor      # Real-time daemon monitoring

# CLI (pass command after --)
composer run cli -- <command> [options]   # Run any CLI command in Docker

# Migrations
composer run migration:up
composer run migration:down
composer run migration:status
composer run migration:retry

# Database schema & ORM
composer run db:schema:status
composer run db:entity:diff
composer run db:entity:fix
composer run db:entity:fix-dry-run
composer run db:object:fix
composer run db:object:fix-dry-run
composer run db:idea:fix
composer run db:idea:fix-dry-run

# phpMyAdmin (optional)
composer run pma                 # Start phpMyAdmin at http://localhost:8080
composer run pma-stop            # Stop phpMyAdmin

# Frontend
composer run frontend-install    # npm install
composer run frontend-build      # npm run build
composer run frontend-dev        # Start frontend dev server
composer run frontend-stop       # Stop frontend dev server
```
