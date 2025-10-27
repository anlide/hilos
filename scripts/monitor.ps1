# monitor.ps1
$env:DOCKER_CLI_HINTS = "false"
docker-compose -f docker/docker-compose.yml run --rm hilos-cli php framework/src/Bootstrap/cli.php daemon:monitor