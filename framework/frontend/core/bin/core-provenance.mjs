// Build-only receipts for tsc's one-output-per-source layout. The compiler maps
// carry source snapshots so a published dist/bin package needs no source checkout.
import { createHash } from 'node:crypto'
import { readFileSync, readdirSync, writeFileSync } from 'node:fs'
import { dirname, join, relative, resolve } from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'

export function sha256(content) {
  return createHash('sha256').update(content).digest('hex')
}

/** Record actual compiler outputs, never synthesize or compile implementation. */
export function writeCoreProvenance(packageDir) {
  const dist = join(packageDir, 'dist')
  const files = {}
  function visit(directory) {
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
      const file = join(directory, entry.name)
      if (entry.isDirectory()) visit(file)
      else if (entry.name.endsWith('.js')) {
        const name = relative(dist, file).replaceAll('\\', '/')
        const source = `src/${name.slice(0, -3)}.ts`
        const mapBytes = readFileSync(`${file}.map`)
        const map = JSON.parse(mapBytes)
        const content = readFileSync(join(packageDir, source), 'utf8')
        if (
          map.sources?.length !== 1 ||
          resolve(dirname(file), map.sources[0]) !==
            resolve(packageDir, source) ||
          map.sourcesContent?.length !== 1 ||
          map.sourcesContent[0] !== content
        )
          throw new Error(`inconsistent tsc source owner: ${name}`)
        files[name] = {
          source,
          sourceSha256: sha256(content),
          outputSha256: sha256(readFileSync(file)),
          mapSha256: sha256(mapBytes),
        }
      }
    }
  }
  visit(dist)
  if (!Object.keys(files).length) throw new Error('missing tsc outputs')
  writeFileSync(
    join(dist, 'core-provenance.json'),
    `${JSON.stringify({ version: 1, compiler: 'tsc', files }, null, 2)}\n`,
  )
}

if (
  process.argv[1] &&
  resolve(process.argv[1]) === fileURLToPath(import.meta.url)
)
  writeCoreProvenance(dirname(dirname(fileURLToPath(import.meta.url))))
