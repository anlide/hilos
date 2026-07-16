// writeFileAtomic writes and overwrites through a temp sibling + rename, leaving
// no `.tmp` file behind on success.
import { mkdtempSync, readFileSync, readdirSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { afterEach, beforeEach, expect, it } from 'vitest'

import { writeFileAtomic } from '../src/writeFileAtomic.js'

let dir: string

beforeEach(() => {
  dir = mkdtempSync(join(tmpdir(), 'prerender-atomic-'))
})

afterEach(() => {
  rmSync(dir, { recursive: true, force: true })
})

it('writes the file contents', () => {
  const path = join(dir, 'about.html')

  writeFileAtomic(path, '<h1>About</h1>')

  expect(readFileSync(path, 'utf8')).toBe('<h1>About</h1>')
})

it('overwrites an existing file', () => {
  const path = join(dir, 'about.html')

  writeFileAtomic(path, 'first')
  writeFileAtomic(path, 'second')

  expect(readFileSync(path, 'utf8')).toBe('second')
})

it('leaves no temp file behind', () => {
  writeFileAtomic(join(dir, 'about.html'), '<h1>About</h1>')

  expect(readdirSync(dir).some((name) => name.endsWith('.tmp'))).toBe(false)
})
