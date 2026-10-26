# Repository: Public Repository Hygiene

Read this before writing any text into the tracked tree — a rule file, a code
comment, a test fixture, a script header, a README, a commit message — while
working from a task, a plan, a note, or the output of a tool that named a
machine, a directory on somebody's disk, or the tooling of one particular line.

## Core Rule

Private names and local paths that arrive with a task are the input of your
reconnaissance, not text for a public file. The name of a machine, a host
name, the absolute path of a checkout on one host, the name or the layout of a
private pipeline, a link into a private note, map, or mockup — all of these
stay outside the tracked tree, and outside every commit message, because the
history is as public as the tree.

Keep what the reference was for. A sentence that pointed at a private file
usually carried a fact: which script measures a thing, which document
describes a mechanism, which mockup a screen follows. Rewrite the sentence so
that fact survives on its own: point at the public file that holds the same
record, describe what the private source said, or name the input the task
hands over ("the approved mockup handed in with the task") without naming
where it is kept.

Do not widen the ban to the paths that belong to the project. A path a
container is built around, a path read from a variable, a public author's
name, the name of a public source of a mechanism — none of these says where a
private checkout lives, and none of them is a leak.

## Allowed Examples

- **A container's own path.** `/var/run/docker.sock`, `/srv/preview`,
  `/data/caddy/pki/authorities/local`, `/etc/caddy/conf.d/*.caddyfile`: the
  path exists inside the image and is the same on every host.
- **A parameterized path.** `${HILOS_REPO}`, `$repo/demo/$1/.env`,
  `${HILOS_STAND_SUFFIX:-}`: the text names a variable, and the value stays
  with the host that sets it.
- **A relative path of this repository.** `scripts/log-streams.php`,
  `framework/docker/preview`, `docs/agents/architecture/logs.md`: the address
  holds in every checkout.
- **A public, reusable name.** `chat.hilos`, `home.hilos`, `ca.hilos/root.crt`:
  the preview lane serves them on any host that runs it.
- **A measurement attributed without a machine.** "measured on the test box,
  run 0659, seven lanes, 22m20s" keeps the date, the run, the lane count and
  every number; the host name added nothing a reader of the public tree could
  use.
- **A public author, and a public source named without a local path.** The
  name of a person in a commit, and the name of a library or a project a
  mechanism was taken from.
- **A ticket key.** `HIL-872` is the name of a decision; it is not the address
  of a private file.

## Workflow

1. Before writing, sort what the task gave you: facts about the code go into
   the file, addresses of where you learned them do not.
2. Replace every private reference with one of the public shapes above — a
   relative path of this repository, a synthetic example, an explicit
   configuration value read from the environment, or a description of what the
   reference said. Keep the meaning; a sentence that loses its point is not
   cleaner, it is wrong.
3. Attribute a measurement to the stand neutrally — "the test box", and "the
   old box" for the machine that preceded it — and keep every date, run
   number, duration and lane count as measured.
4. Before handing the work over, read the text you added as the public
   repository will show it: the diff of the tracked files and the commit
   message. A name, a path or a link that only makes sense on one host is
   taken out there, not after the merge.
5. This rule is not checked by machine. The reading in step 4 is the check.

## Preview Configuration

The preview lane (`framework/docker/preview`, the `preview` and `control`
profiles of `framework/docker/docker-compose.yml`, `framework/docker/caddy/Caddyfile`)
is public and reusable: it learns the checkout it serves from the environment
and assumes nothing about the host it runs on.

- **`HILOS_REPO` is the absolute root of the checkout the lane drives.** The
  control service (`framework/docker/control/server.py`) reads it with no
  default and refuses to start — before it binds its HTTP port — when the value
  is empty, relative, or names no Hilos checkout (either `framework/docker/docker-compose.yml`
  or `framework/docker/preview` is missing under it).
- **The root is bind-mounted at the same path inside the container as on the
  host.** The control service hands `docker compose` paths under `HILOS_REPO`,
  and the host's docker daemon resolves them; a mount at another path would
  hand it paths that exist nowhere. Set `HILOS_REPO=<absolute root of your checkout>`;
  it has no placeholder value a service may fall back to.
- **The `control` profile needs the value; the other profiles do not.** The
  compose file passes `${HILOS_REPO:-}` and declares the bind in long form with
  `create_host_path: false` and a stand-in source, so the file keeps parsing
  for every other profile (`preview`, `test`, `agent-host`, and the Ollama
  ones) with the variable unset, and docker never creates a directory on the
  host for it.
- **`preview up` sets the root by itself.** The preview script exports
  `HILOS_REPO` as the root of the checkout it was started from, so the lane is
  always this checkout's; nothing in the tree names a particular host's
  directory.
- **The names stay shared.** `home.hilos`, `chat.hilos`, `tasks.hilos`,
  `polls.hilos` and their `ws.`, `pma.` and `status.` subdomains are the lane's
  on every host; the CA root is served as `/root.crt` on `ca.hilos` and carries
  no host's name. Do not add a catalog of real machines, hosts or addresses to
  this repository to make the lane "complete": a host that runs the lane sets
  `HILOS_PREVIEW_BIND` (and `HILOS_REPO` for control) in its own environment,
  and host-local site blocks live in a directory of the host's own, which it
  names with `HILOS_CADDY_EXTRA` in its `framework/docker/.env`; the compose
  file mounts that directory as Caddy's `conf.d`. The in-repo
  `framework/docker/caddy/conf.d` is the default when the variable is unset
  and stays empty: a site block put there lands in the tracked tree.

How the lane's bind address relates to the loopback default of every other
stack is in [frontend/build-and-docker.md](frontend/build-and-docker.md),
"Published ports bind to loopback".
