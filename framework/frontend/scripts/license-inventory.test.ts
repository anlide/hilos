// The license inventory's collection rules against a real tmp tree: which lock
// entries become rows, what the door behind a path-linked SDK package adds, and
// where the line between failing the build and recording an absent text runs.
// A real tree rather than mocks, in the style of its neighbour staleness.test.ts:
// every rule here turns on a file being there or not being there.
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { afterEach, beforeEach, expect, it } from 'vitest'

import {
  collectLicenseEntries,
  renderInventoryModule,
} from './license-inventory.mjs'

let dir: string

beforeEach(() => {
  dir = mkdtempSync(join(tmpdir(), 'hilos-license-'))
})

afterEach(() => {
  rmSync(dir, { recursive: true, force: true })
})

/** Write a file inside the tmp tree, creating its parents. */
function writeAt(path: string, contents: string): void {
  const full = join(dir, path)
  mkdirSync(join(full, '..'), { recursive: true })
  writeFileSync(full, contents)
}

/** Write a JSON file inside the tmp tree. */
function writeJsonAt(path: string, value: unknown): void {
  writeAt(path, JSON.stringify(value))
}

const frontendDir = (): string => join(dir, 'project/frontend')

/**
 * The whole fixture: a project whose npm lock links one SDK package, the SDK
 * workspace behind that link, and both install roots.
 */
function writeTree(): void {
  writeJsonAt('project/composer.lock', {
    packages: [
      {
        name: 'anlide/hilos',
        version: '2.0.0',
        license: ['MIT'],
        dist: { type: 'path', url: '/hilos' },
      },
      {
        name: 'minishlink/web-push',
        version: 'v11.0.0',
        license: ['MIT'],
        source: { url: 'https://github.com/web-push-libs/web-push-php.git' },
      },
      { name: 'vendor/unlicensed', version: '1.0.0' },
    ],
    'packages-dev': [
      { name: 'phpunit/phpunit', version: '11.0.0', license: ['BSD-3-Clause'] },
    ],
  })
  writeAt('project/vendor/anlide/hilos/LICENSE', 'The MIT License (MIT)\n')

  writeJsonAt('project/frontend/package-lock.json', {
    lockfileVersion: 3,
    packages: {
      '': { name: 'chat' },
      '../../workspace/vue': { version: '0.0.0' },
      'node_modules/@hilos/vue': {
        resolved: '../../workspace/vue',
        link: true,
      },
      'node_modules/vue': { version: '3.5.35', license: 'MIT' },
      'node_modules/eslint': { version: '10.4.1', license: 'MIT', dev: true },
    },
  })
  writeJsonAt('project/frontend/node_modules/vue/package.json', {
    name: 'vue',
    version: '3.5.35',
    repository: { type: 'git', url: 'git+https://github.com/vuejs/core.git' },
  })
  writeAt(
    'project/frontend/node_modules/vue/LICENSE',
    'MIT, from the package\n',
  )

  writeJsonAt('workspace/package-lock.json', {
    lockfileVersion: 3,
    packages: {
      '': { name: 'hilos-frontend' },
      vue: {
        name: '@hilos/vue',
        version: '0.0.0',
        dependencies: { '@hilos/core': '*', bootstrap: '^5.3.3' },
        peerDependencies: { vue: '^3' },
      },
      core: {
        name: '@hilos/core',
        version: '0.0.0',
        dependencies: { zod: '^4' },
      },
      'node_modules/@hilos/core': { resolved: 'core', link: true },
      'node_modules/@hilos/vue': { resolved: 'vue', link: true },
      'node_modules/bootstrap': { version: '5.3.8', license: 'MIT' },
      'node_modules/zod': { version: '4.4.3', license: 'MIT' },
      'node_modules/vue': { version: '3.5.99', license: 'MIT' },
      'node_modules/vite': { version: '7.0.0', license: 'MIT', dev: true },
    },
  })
  writeJsonAt('workspace/node_modules/bootstrap/package.json', {
    name: 'bootstrap',
    version: '5.3.8',
    repository: 'twbs/bootstrap',
  })
}

/** Every row's name, for the "who is in the list" assertions. */
function names(): string[] {
  return collectLicenseEntries(frontendDir()).map((entry) => entry.name)
}

it('takes both lockfiles of the project as the whole graph', () => {
  writeTree()

  expect(names()).toContain('vue')
  expect(names()).toContain('anlide/hilos')
})

it('drops development dependencies from both lockfiles', () => {
  writeTree()

  expect(names()).not.toContain('eslint')
  expect(names()).not.toContain('vite')
  expect(names()).not.toContain('phpunit/phpunit')
})

it('makes no row of a path-linked SDK package, nor of its link target', () => {
  writeTree()

  expect(names()).not.toContain('@hilos/vue')
  expect(names()).not.toContain('')
})

it('takes the dependencies behind the link as ordinary rows', () => {
  writeTree()
  const entries = collectLicenseEntries(frontendDir())

  expect(entries).toContainEqual({
    name: 'bootstrap',
    version: '5.3.8',
    language: 'js',
    license: 'MIT',
    licenseText: null,
    repository: 'https://github.com/twbs/bootstrap',
  })
})

it('follows a link that stands behind another link', () => {
  writeTree()

  // zod is reached only through @hilos/vue -> @hilos/core -> zod.
  expect(names()).toContain('zod')
})

it('does not follow peer dependencies', () => {
  writeTree()
  const versions = collectLicenseEntries(frontendDir())
    .filter((entry) => entry.name === 'vue')
    .map((entry) => entry.version)

  expect(versions).toEqual(['3.5.35'])
})

it('keeps a composer path package as an ordinary row, text and all', () => {
  writeTree()
  const entries = collectLicenseEntries(frontendDir())

  expect(entries).toContainEqual({
    name: 'anlide/hilos',
    version: '2.0.0',
    language: 'php',
    license: 'MIT',
    licenseText: 'The MIT License (MIT)\n',
    repository: null,
  })
})

it('copies the version from the lockfile verbatim, prefix and all', () => {
  writeTree()
  const entries = collectLicenseEntries(frontendDir())

  expect(
    entries.find((entry) => entry.name === 'minishlink/web-push'),
  ).toMatchObject({
    version: 'v11.0.0',
    repository: 'https://github.com/web-push-libs/web-push-php',
  })
})

it('records an absent license file as no text, and says UNKNOWN when the manifest declares none', () => {
  writeTree()
  const entries = collectLicenseEntries(frontendDir())

  expect(entries.find((entry) => entry.name === 'zod')).toMatchObject({
    licenseText: null,
  })
  expect(
    entries.find((entry) => entry.name === 'vendor/unlicensed'),
  ).toMatchObject({ license: 'UNKNOWN', licenseText: null })
})

it('reads the license text out of the installed package', () => {
  writeTree()
  const entries = collectLicenseEntries(frontendDir())

  expect(entries.find((entry) => entry.name === 'vue')).toMatchObject({
    licenseText: 'MIT, from the package\n',
    repository: 'https://github.com/vuejs/core',
  })
})

it('orders the rows by name, so two runs over one tree agree byte for byte', () => {
  writeTree()

  const first = renderInventoryModule(collectLicenseEntries(frontendDir()))
  const second = renderInventoryModule(collectLicenseEntries(frontendDir()))

  expect(first).toBe(second)
  expect(names()).toEqual([...names()].sort((a, b) => a.localeCompare(b, 'en')))
})

it('fails when the project has no npm lockfile', () => {
  writeTree()
  rmSync(join(dir, 'project/frontend/package-lock.json'))

  expect(() => collectLicenseEntries(frontendDir())).toThrow(
    /package-lock\.json/,
  )
})

it('fails when the workspace behind a link has no lockfile', () => {
  writeTree()
  rmSync(join(dir, 'workspace/package-lock.json'))

  expect(() => collectLicenseEntries(frontendDir())).toThrow(/workspace/)
})

it('fails naming the install command when a project install root is missing', () => {
  writeTree()
  rmSync(join(dir, 'project/vendor'), { recursive: true })

  expect(() => collectLicenseEntries(frontendDir())).toThrow(/composer install/)

  writeTree()
  rmSync(join(dir, 'project/frontend/node_modules'), { recursive: true })

  expect(() => collectLicenseEntries(frontendDir())).toThrow(/npm install/)
})

it('goes on when the SDK workspace is not installed, since its rows stay truthful', () => {
  writeTree()
  rmSync(join(dir, 'workspace/node_modules'), { recursive: true })

  expect(names()).toContain('bootstrap')
})

it('renders a module the demo can import and typecheck', () => {
  writeTree()

  const module = renderInventoryModule(collectLicenseEntries(frontendDir()))

  expect(module).toContain(
    "import type { HilosLicenseInventory } from '@hilos/core'",
  )
  expect(module).toContain(
    'export const hilosLicenseInventory: HilosLicenseInventory = {',
  )
  expect(module).toContain("name: 'anlide/hilos',")
  expect(module).toContain('licenseText: null,')
})

it('escapes a license text that carries quotes and newlines', () => {
  writeTree()
  writeAt(
    'project/frontend/node_modules/vue/LICENSE',
    'a \'quoted\' line\nand a "double" one\n',
  )

  const module = renderInventoryModule(collectLicenseEntries(frontendDir()))

  expect(module).toContain(
    "licenseText: 'a \\'quoted\\' line\\nand a \"double\" one\\n',",
  )
})
