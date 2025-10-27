# Environment Configuration

This file explains how to configure Hilos using `.env` file.

## Quick Start

1. Copy `.env.example` to `.env`:
   ```bash
   cp .env.example .env
   ```

2. Edit `.env` and adjust values if needed

3. Restart Docker containers:
   ```bash
   cd docker
   docker-compose down
   docker-compose up -d
   ```

## Configuration Variables

### Network Configuration

- `HILOS_DAEMON_HOST` - IP address of daemon container (default: `172.25.0.10`)
  - Used by CLI and Monitor to connect to daemon
  - Should match `DOCKER_DAEMON_IP` value

- `HTTP_STATUS_HOST` - Host for HTTP status server (default: `0.0.0.0`)
  - `0.0.0.0` = listen on all interfaces

- `HTTP_STATUS_PORT` - Port for HTTP status endpoint (default: `8090`)

- `WORKER_COMM_HOST` - Host for worker communication (default: `0.0.0.0`)

- `WORKER_COMM_PORT` - Port for worker communication (default: `8091`)

### Database Configuration

- `DB_HOST` - Database hostname (default: `hilos-mysql`)
- `DB_PORT` - Database port (default: `3306`)
- `DB_NAME` - Database name (default: `hilos_db`)
- `DB_USER` - Database user (default: `hilos_user`)
- `DB_PASSWORD` - Database password
- `DB_ROOT_PASSWORD` - Database root password

### Logging

- `DAEMON_LOG_FILE` - Daemon log file path (default: `/var/log/hilos/daemon.log`)
- `DAEMON_ERROR_LOG_FILE` - Daemon error log file path (default: `/var/log/hilos/daemon-error.log`)

### Docker Network

- `DOCKER_NETWORK_SUBNET` - Docker network subnet (default: `172.25.0.0/16`)
- `DOCKER_DAEMON_IP` - Fixed IP for daemon container (default: `172.25.0.10`)

## Important Notes

- **Never commit `.env` to version control** - it contains sensitive data
- Always commit `.env.example` with default values
- All variables have default values, so `.env` file is optional for development

## Troubleshooting

### DNS Resolution Delays

If you experience 3-5 second delays when connecting to daemon:

1. Check that `HILOS_DAEMON_HOST` is set to IP address (`172.25.0.10`), not hostname (`hilos`)
2. Using IP addresses avoids DNS resolution delays

### Port Conflicts

If ports are already in use:

1. Change `HTTP_STATUS_PORT` and `WORKER_COMM_PORT` in `.env`
2. Restart containers: `docker-compose down && docker-compose up -d`
