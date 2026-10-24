import {
  mkdtemp,
  mkdir,
  readFile,
  readdir,
  rm,
  writeFile,
} from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { expect, it } from 'vitest'

import { createLegacyBackupFixture } from './backupFixture.mjs'

it('publishes the actual offset bytes without rewriting its source or losing archive bytes', async () => {
  const root = await mkdtemp(join(tmpdir(), 'hilos-backup-fixture-'))
  try {
    const scope = join(root, 'schema-only')
    await mkdir(scope)
    const id = '2026-01-01_00-00-00'
    const base = `${id}-test-schema-only`
    const original = JSON.stringify({
      id,
      env: 'test',
      scope: 'schema-only',
      createdAt: '2026-10-24T10:00:00.000000Z',
      keep: false,
      sha256: 'recorded-digest',
    })
    const archive = Buffer.from('stand archive bytes')
    await writeFile(join(scope, `${base}.json`), original)
    await writeFile(join(scope, `${base}.tar.gz`), archive)
    const legacy = '2026-01-01T03:30:05.123456+03:00'
    const fixture = await createLegacyBackupFixture(root, id, legacy)
    try {
      expect(await fixture.read()).toBe(fixture.beforePublish)
      expect(JSON.parse(fixture.beforePublish)).toMatchObject({
        id: fixture.id,
        createdAt: legacy,
        keep: true,
        sha256: 'recorded-digest',
      })
      expect(
        await readFile(join(scope, `${fixture.id}-test-schema-only.tar.gz`)),
      ).toEqual(archive)
    } finally {
      await fixture.dispose()
    }
    expect(await readFile(join(scope, `${base}.json`), 'utf8')).toBe(original)
    expect((await readdir(scope)).sort()).toEqual([
      `${base}.json`,
      `${base}.tar.gz`,
    ])
  } finally {
    await rm(root, { recursive: true, force: true })
  }
})
