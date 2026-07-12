# Docker: Ollama and GPU

Ollama is one stack in the single framework compose file, selected by profile. It exposes port 11434 to the host. Demos connect via `LLM_LOCAL_URL` without depending on any specific container name or project—they just use the host port or external AI service URL.

## Framework Ollama

**Location:** the `ollama` / `ollama-gpu-nvidia` / `ollama-gpu-amd` profiles of `framework/docker/docker-compose.yml` (one service per variant, shared data volume). See [framework/README.md](../framework/README.md#docker-single-framework-compose-file) for the one-file rule.

### Start Ollama

```bash
# CPU (from repo root)
docker compose -f framework/docker/docker-compose.yml --profile ollama up -d

# NVIDIA GPU
docker compose -f framework/docker/docker-compose.yml --profile ollama-gpu-nvidia up -d

# AMD ROCm
docker compose -f framework/docker/docker-compose.yml --profile ollama-gpu-amd up -d
```

Or via composer (from repo root):

```bash
composer run ollama:start
composer run ollama:start-gpu-nvidia
composer run ollama:start-gpu-amd
```

### Port exposure

Ollama exposes **port 11434** to the host. Demo containers reach it via `http://host.docker.internal:11434` (Windows/Mac). On Linux, add `extra_hosts: host.docker.internal:host-gateway` to demo services or set `LLM_LOCAL_URL` to host IP.

### Pull models

After starting Ollama, pull models for chat demo (qwen2.5:0.5b, 3b, 7b):

```bash
composer run ollama:pull
composer run ollama:pull-gpu-nvidia
composer run ollama:pull-gpu-amd
```

## Demo connection

Demos use `LLM_LOCAL_URL` environment variable. Default in Docker: `http://host.docker.internal:11434`. Demo does not depend on framework internals—it may be framework Ollama, external Ollama, or another AI service on that port.

## GPU prerequisites

| Vendor | Command | Prerequisites |
|--------|---------|----------------|
| **NVIDIA** | `ollama:start-gpu-nvidia` | [NVIDIA Container Toolkit](https://docs.nvidia.com/datacenter/cloud-native/container-toolkit/) |
| **AMD** | `ollama:start-gpu-amd` | ROCm, `/dev/kfd` and `/dev/dri` on host |

## Environment variables

| Variable | Default | Description |
|----------|---------|-------------|
| `OLLAMA_HOST_PORT` | 11434 | Host port for Ollama |
| `LLM_LOCAL_URL` | `http://host.docker.internal:11434` | URL demos use to reach Ollama (in demo .env) |
