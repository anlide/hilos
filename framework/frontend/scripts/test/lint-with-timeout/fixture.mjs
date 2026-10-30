// Real processes for the lint runner's regression, with explicit readiness.
import { spawn } from 'node:child_process'
import { readFileSync } from 'node:fs'
import process from 'node:process'
import { fileURLToPath } from 'node:url'

const [role, mode] = process.argv.slice(2)

function snapshot(pid) {
  const fields = readFileSync(`/proc/${pid}/stat`, 'utf8')
    .split(') ')[1]
    .split(' ')
  return {
    pid,
    parent: Number(fields[1]),
    group: Number(fields[2]),
    start: fields[19],
  }
}

if (role === 'grandchild') {
  process.on('SIGTERM', () => {
    process.stdout.write('grandchild received TERM\n')
    if (mode === 'cooperative') process.exit(0)
  })
  globalThis.setInterval(() => {}, 1_000)
  process.send(snapshot(process.pid))
} else {
  const child = spawn(
    process.execPath,
    [fileURLToPath(import.meta.url), 'grandchild', mode],
    { stdio: ['ignore', 'inherit', 'inherit', 'ipc'] },
  )
  process.on('SIGTERM', () => {
    process.stdout.write('parent received TERM\n')
  })
  child.once('message', (grandchild) => {
    const processes = [grandchild]
    let current = snapshot(process.pid)
    while (true) {
      processes.push(current)
      if (current.pid === current.group) break
      current = snapshot(current.parent)
    }
    process.stdout.write(
      `ready:${JSON.stringify({ processes, args: process.argv.slice(4) })}\n`,
    )
    process.stderr.write('fixture stderr is visible\n')
  })
  process.stdin.once('data', () => {
    // Deliberately leave the grandchild to the wrapper's normal-exit cleanup.
    process.exit(Number(mode))
  })
}
