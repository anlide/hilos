#!/usr/bin/env python3
"""Whitelisted preview-lane control service.

Read-only slice: exposes GET /api/status only. Mutating actions (preview
up/down, per-demo start/stop, LLM) are added in the next slice as more fixed
entries. SECURITY: no value from a request is ever interpolated into a command;
every action maps to a hard-coded argv list, run without a shell. Reachable only
through Caddy on home.hilos (in-tailnet), but it holds the host Docker socket, so
keep the action set an allowlist.
"""
import json
import subprocess
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

PORT = 8099
OLLAMA_CONTAINER = "hilos-ollama-local"


def run(argv, timeout=15):
    try:
        p = subprocess.run(argv, capture_output=True, text=True, timeout=timeout)
        return p.returncode, p.stdout.strip(), p.stderr.strip()
    except (subprocess.TimeoutExpired, OSError) as exc:
        return 124, "", str(exc)


def collect_status():
    # Containers, joined with live mem/cpu by name.
    _, ps, _ = run([
        "docker", "ps", "-a", "--format",
        '{{.Names}}\t{{.Label "com.docker.compose.project"}}\t{{.State}}\t{{.Status}}',
    ])
    _, stats, _ = run([
        "docker", "stats", "--no-stream", "--format",
        "{{.Name}}\t{{.MemUsage}}\t{{.CPUPerc}}",
    ])
    usage = {}
    for line in filter(None, stats.splitlines()):
        name, mem, cpu = (line.split("\t") + ["", ""])[:3]
        usage[name] = {"mem": mem, "cpu": cpu}

    containers = []
    for line in filter(None, ps.splitlines()):
        parts = (line.split("\t") + ["", "", "", ""])[:4]
        name, project, state, st = parts
        u = usage.get(name, {})
        containers.append({
            "name": name,
            "project": project or "(none)",
            "state": state,
            "status": st,
            "mem": u.get("mem", ""),
            "cpu": u.get("cpu", ""),
        })
    containers.sort(key=lambda c: (c["project"], c["name"]))

    # Host memory (MiB).
    host = {}
    _, mem, _ = run(["free", "-m"])
    for line in mem.splitlines():
        if line.startswith("Mem:"):
            f = line.split()
            if len(f) >= 7:
                host = {"total_mib": int(f[1]), "used_mib": int(f[2]),
                        "available_mib": int(f[6])}

    # Ollama: reachable? which models pulled?
    code, models_raw, _ = run(["docker", "exec", OLLAMA_CONTAINER, "ollama", "list"])
    ollama_up = code == 0
    models = []
    if ollama_up:
        for line in models_raw.splitlines()[1:]:  # skip header
            col = line.split()
            if col:
                models.append(col[0])

    return {"containers": containers, "host": host,
            "ollama": {"up": ollama_up, "models": models}}


class Handler(BaseHTTPRequestHandler):
    def _send(self, code, payload):
        body = json.dumps(payload).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path.rstrip("/") == "/api/status":
            self._send(200, collect_status())
        else:
            self._send(404, {"error": "not found"})

    def log_message(self, *args):  # quieter default logging
        pass


if __name__ == "__main__":
    ThreadingHTTPServer(("0.0.0.0", PORT), Handler).serve_forever()
