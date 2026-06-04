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
php cli.php db:migration:up [options]           # Apply pending migrations
php cli.php db:migration:down <version> [options]  # Rollback to specific version
php cli.php db:migration:status [options]       # Check migration status
php cli.php db:migration:retry <version> [options] # Retry failed migration
```

### Migration options

| Option | Commands | Description |
|--------|----------|-------------|
| `--db-index=<N>` | db:migration:*, db:* | Database connection index (default: 0) |
| `--to=<version>` | db:migration:up | Migrate up to specific version only |
| `--force` | db:migration:up, db:migration:down | Force despite failures (use with caution) |

## Database Schema

```bash
php cli.php db:schema:status [options]  # Show schema status (tables, columns, indexes)
php cli.php db:entity:diff [options]    # Compare Entity files with database schema
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
composer run db:migration:up
composer run db:migration:down
composer run db:migration:status
composer run db:migration:retry

# Database schema & ORM
composer run db:schema:status
composer run db:entity:diff

# phpMyAdmin (optional)
composer run pma                 # Start phpMyAdmin at http://localhost:8080
composer run pma-stop            # Stop phpMyAdmin

# Frontend
composer run frontend-install    # npm install
composer run frontend-build      # npm run build
composer run frontend-dev        # Start frontend dev server
composer run frontend-stop       # Stop frontend dev server
```
