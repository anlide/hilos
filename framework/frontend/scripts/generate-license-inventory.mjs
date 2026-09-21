// Write one project's license inventory snapshot, the module the /license page
// receives as a prop (HIL-838). Called from each demo's `prebuild`, `predev` and
// `precheck` hooks: the page is rendered at build time, so the list has to exist
// before anything reads it, and the import fails loudly if it does not.
//
// Usage: node generate-license-inventory.mjs [--frontend <dir>] [--out <file>]
//   --frontend DIR  the project's frontend directory (default: the working directory)
//   --out FILE      the snapshot to write (default: <frontend>/src/generated/hilosLicenseInventory.ts)
//
// The rules live next door in license-inventory.mjs and are unit-tested there;
// this file only reads arguments and writes the result — the same split as
// staleness.mjs / npm-install-if-stale.mjs. Nothing is written until every read
// has succeeded, so a failed run never leaves half a snapshot behind.

import console from 'node:console'
import { mkdirSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import process from 'node:process'

import {
  collectLicenseEntries,
  readProjectName,
  renderInventoryModule,
} from './license-inventory.mjs'

/**
 * @param {string[]} argv The arguments after the script name.
 * @returns {{ frontend: string, out: string | null }}
 */
function parseArgs(argv) {
  let frontend = process.cwd()
  let out = null
  for (let index = 0; index < argv.length; index++) {
    if (argv[index] === '--frontend') {
      frontend = argv[++index]
      if (frontend === undefined)
        throw new Error('--frontend needs a directory')
    } else if (argv[index] === '--out') {
      out = argv[++index]
      if (out === undefined) throw new Error('--out needs a file')
    } else {
      throw new Error(`unknown argument: ${argv[index]}`)
    }
  }

  return { frontend, out }
}

const { frontend, out } = parseArgs(process.argv.slice(2))
const frontendDir = resolve(frontend)
const outFile =
  out === null
    ? join(frontendDir, 'src/generated/hilosLicenseInventory.ts')
    : resolve(out)

const entries = collectLicenseEntries(frontendDir)
const project = readProjectName(frontendDir)

mkdirSync(dirname(outFile), { recursive: true })
writeFileSync(outFile, renderInventoryModule(entries, project))

console.log(`license inventory: ${entries.length} packages -> ${outFile}`)
