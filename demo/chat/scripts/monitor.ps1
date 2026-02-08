# monitor.ps1
$env:DOCKER_CLI_HINTS = "false"
docker-compose -f docker/docker-compose.local.yml run --rm chat-monitor-local php backend/Bootstrap/cli.php daemon:monitor

