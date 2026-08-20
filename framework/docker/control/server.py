#!/usr/bin/env python3
"""Whitelisted preview-lane control service.

  GET  /api/status                          -> live status JSON
  POST /api/preview/up | /api/preview/down
  POST /api/demo/<chat|tasks|poll>/<start|stop|restart>
  POST /api/ollama/<start|stop|unload>
  POST /api/ollama/pull/<0.5b|3b>

SECURITY: every action maps to a hard-coded argv list, run without a shell; the
only request-derived values (demo key, model) are validated against fixed sets
and mapped to constants, never interpolated. Reachable only through Caddy on
home.hilos (in-tailnet), but it holds the host Docker socket — keep this an
allowlist.
"""
import json
import os
import re
import subprocess
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

# Strip ANSI escapes / control chars so command tails render cleanly in the UI.
_ANSI = re.compile(r"\x1b\[[0-9;?]*[a-zA-Z]|[\x00-\x08\x0b-\x1f\x7f]")
def clean(s):
    return _ANSI.sub("", s or "").strip()

PORT = 8099
REPO = os.environ.get("HILOS_REPO", "/home/cloud/hilos")
COMPOSE = f"{REPO}/framework/docker/docker-compose.yml"
PREVIEW = f"{REPO}/framework/docker/preview"
CLUSTER = f"{REPO}/demo/cluster/docker/cluster"
OLLAMA = "hilos-ollama-local"

DEMOS = {"chat", "tasks", "poll"}
DEMO_OPS = {"start", "stop", "restart"}
DEMO_DIR = {"chat": "chat", "tasks": "tasks", "poll": "simple-poll"}
MODELS = {"0.5b": "qwen2.5:0.5b", "3b": "qwen2.5:3b"}

_lock = threading.Lock()
last_action = {}  # outcome of the most recent action, surfaced via /api/status


def run(argv, timeout=120):
    try:
        p = subprocess.run(argv, capture_output=True, text=True, timeout=timeout)
        return p.returncode, p.stdout.strip(), p.stderr.strip()
    except (subprocess.TimeoutExpired, OSError) as exc:
        return 124, "", str(exc)


def compose(*args, timeout=120):
    return run(["docker", "compose", "-f", COMPOSE, *args], timeout=timeout)


def preview(*args, timeout=600):
    return run(["bash", PREVIEW, *args], timeout=timeout)


def cluster(*args, timeout=600):
    # The backend-only multi-node cluster demo is driven by its own controller
    # (build + N nodes + mysql), not a single compose stack like the web demos.
    return run(["bash", CLUSTER, *args], timeout=timeout)


def demo_compose(key, *op, timeout=600):
    # Drive the demo's own stack directly (local + preview overlay) so per-demo
    # actions never touch the shared Caddy/DNS infra the preview script bounces.
    d = f"{REPO}/demo/{DEMO_DIR[key]}/docker"
    return run(["docker", "compose",
                "-f", f"{d}/docker-compose.local.yml",
                "-f", f"{d}/docker-compose.preview.yml", *op], timeout=timeout)


def merge(*results):
    code = next((c for c, _, _ in results if c != 0), 0)
    out = "\n".join(o for _, o, _ in results if o).strip()
    err = "\n".join(e for _, _, e in results if e).strip()
    return code, out, err


# --- status -----------------------------------------------------------------
def collect_status():
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
        name, project, state, st = (line.split("\t") + ["", "", "", ""])[:4]
        u = usage.get(name, {})
        containers.append({
            "name": name, "project": project or "(none)", "state": state,
            "status": st, "mem": u.get("mem", ""), "cpu": u.get("cpu", ""),
        })
    containers.sort(key=lambda c: (c["project"], c["name"]))

    host = {}
    _, mem, _ = run(["free", "-m"])
    for line in mem.splitlines():
        if line.startswith("Mem:"):
            f = line.split()
            if len(f) >= 7:
                host = {"total_mib": int(f[1]), "used_mib": int(f[2]),
                        "available_mib": int(f[6])}

    code, models_raw, _ = run(["docker", "exec", OLLAMA, "ollama", "list"])
    models = [ln.split()[0] for ln in models_raw.splitlines()[1:] if ln.split()]
    with _lock:
        la = dict(last_action)
    return {"containers": containers, "host": host,
            "ollama": {"up": code == 0, "models": models}, "last_action": la}


# --- action allowlist -------------------------------------------------------
def resolve(parts):
    """Return a zero-arg callable running the allowed action, or None."""
    if parts == ["api", "preview", "up"]:
        return lambda: preview("up")
    if parts == ["api", "preview", "down"]:
        return lambda: preview("down")
    if (len(parts) == 4 and parts[:2] == ["api", "demo"]
            and parts[2] in DEMOS and parts[3] in DEMO_OPS):
        key, op = parts[2], parts[3]
        if op == "start":
            return lambda: demo_compose(key, "up", "-d")
        if op == "stop":
            return lambda: demo_compose(key, "stop")
        return lambda: demo_compose(key, "restart")
    if parts == ["api", "cluster", "up"]:
        return lambda: cluster("up")
    if parts == ["api", "cluster", "down"]:
        return lambda: cluster("down", timeout=300)
    if parts == ["api", "cluster", "restart"]:
        return lambda: cluster("restart")
    if parts == ["api", "ollama", "start"]:
        return lambda: compose("--profile", "ollama", "up", "-d")
    if parts == ["api", "ollama", "stop"]:
        return lambda: compose("rm", "-sf", "ollama-local",
                               "ollama-local-gpu-nvidia", "ollama-local-gpu-amd")
    if parts == ["api", "ollama", "unload"]:
        return lambda: merge(*(compose("exec", "-T", "ollama-local", "ollama", "stop", m)
                               for m in MODELS.values()))
    if len(parts) == 4 and parts[:3] == ["api", "ollama", "pull"] and parts[3] in MODELS:
        return lambda: compose("exec", "-T", "ollama-local", "ollama", "pull",
                               MODELS[parts[3]], timeout=1800)
    return None


class Handler(BaseHTTPRequestHandler):
    def _send(self, code, payload):
        body = json.dumps(payload).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _parts(self):
        return [p for p in self.path.split("?")[0].split("/") if p]

    def do_GET(self):
        parts = self._parts()
        if parts == ["api", "status"]:
            self._send(200, collect_status())
        elif parts == ["api", "cluster", "status"]:
            _, out, err = cluster("status-json", timeout=60)
            try:
                payload = json.loads(out) if out.strip() else {"nodes": []}
            except ValueError:
                payload = {"nodes": [], "error": clean(err) or "status unavailable"}
            self._send(200, payload)
        else:
            self._send(404, {"error": "not found"})

    def do_POST(self):
        parts = self._parts()
        thunk = resolve(parts)
        if thunk is None:
            self._send(404, {"ok": False, "error": "unknown action"})
            return
        path = "/" + "/".join(parts)
        with _lock:
            last_action.clear()
            last_action.update({"path": path, "running": True})

        def worker():
            code, out, err = thunk()
            blob = clean(out) or clean(err)
            tail = blob.splitlines()[-1][:300] if blob else ""
            with _lock:
                last_action.update({"path": path, "running": False,
                                    "ok": code == 0, "tail": tail})

        threading.Thread(target=worker, daemon=True).start()
        # Return immediately: an action may bounce Caddy (the very proxy this
        # response travels through) or the target demo, so we must not wait for
        # it. The UI polls /api/status and reads last_action for the outcome.
        self._send(202, {"started": path})

    def log_message(self, *args):
        pass


if __name__ == "__main__":
    ThreadingHTTPServer(("0.0.0.0", PORT), Handler).serve_forever()
