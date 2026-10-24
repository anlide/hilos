import {
  copyFile,
  readFile,
  readdir,
  rename,
  rm,
  writeFile,
} from 'node:fs/promises'
import { join } from 'node:path'
import { randomUUID } from 'node:crypto'

/**
 * Publish a separate legacy sidecar beside a copy of a real stand archive.
 * The source is never rewritten. Only files are prepared: the running backup
 * agent's existing filesystem watch owns discovery and every RT write.
 * The caller supplies its stand's mounted storage root and always calls dispose.
 */
export async function createLegacyBackupFixture(root, sourceId, createdAt) {
  const scopeDir = join(root, 'schema-only')
  const names = (await readdir(scopeDir)).filter(
    (name) => name.startsWith(`${sourceId}-`) && name.endsWith('.json'),
  )
  if (names.length !== 1) throw new Error('Expected one source backup sidecar')
  const sourceBase = names[0].slice(0, -'.json'.length)
  const source = JSON.parse(await readFile(join(scopeDir, names[0]), 'utf8'))
  const id = `${sourceId}-legacy-${randomUUID()}`
  const base = `${id}-${source.env}-${source.scope}`
  const sidecar = join(scopeDir, `${base}.json`)
  const archive = join(scopeDir, `${base}.tar.gz`)
  const staged = join(scopeDir, `.${base}.tmp`)
  const dispose = async () => {
    // Files this fixture alone owns; no database or runtime reset is involved.
    await rm(sidecar, { force: true })
    await rm(archive, { force: true })
    await rm(staged, { force: true })
  }
  try {
    await copyFile(join(scopeDir, `${sourceBase}.tar.gz`), archive)
    await writeFile(
      staged,
      JSON.stringify({ ...source, id, createdAt, keep: true }),
      { flag: 'wx' },
    )
    // Retain the bytes BEFORE publishing to the scanner's *.json boundary.
    const beforePublish = await readFile(staged, 'utf8')
    await rename(staged, sidecar)
    return {
      id,
      beforePublish,
      read: () => readFile(sidecar, 'utf8'),
      dispose,
    }
  } catch (error) {
    await dispose()
    throw error
  }
}
