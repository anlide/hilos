import {
  readFileSync,
  readdirSync,
  realpathSync,
  statSync,
  writeFileSync,
} from 'node:fs'
import { dirname, join, relative, resolve, sep } from 'node:path'
import { fileURLToPath } from 'node:url'
import process from 'node:process'

/**
 * Validate a completed APF against its source secondary declarations. The same
 * proof gates postbuild and reuse: timestamps alone cannot prove completion.
 *
 * @param {string} packageDir The Angular source package directory.
 * @param {object} manifest The generated production manifest.
 */
export function validateApf(packageDir, manifest) {
  const dist = join(packageDir, 'dist')
  const expected = ['./package.json']
  function secondary(directory) {
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
      if (
        !entry.isDirectory() ||
        entry.name.startsWith('.') ||
        ['src', 'dist', 'node_modules', 'test', 'bin'].includes(entry.name)
      )
        continue
      const child = join(directory, entry.name)
      if (statSync(child).isDirectory()) {
        const declarations = readdirSync(child)
        if (declarations.includes('ng-package.json')) {
          const declaration = JSON.parse(
            readFileSync(join(child, 'ng-package.json'), 'utf8'),
          )
          if (typeof declaration.lib?.entryFile !== 'string')
            throw new Error(`invalid secondary declaration: ${child}`)
          expected.push(`./${relative(packageDir, child).split(sep).join('/')}`)
        }
        secondary(child)
      }
    }
  }
  secondary(packageDir)
  if (
    expected.length !== 24 ||
    JSON.stringify(Object.keys(manifest.exports ?? {}).sort()) !==
      JSON.stringify(expected.sort()) ||
    ['main', 'module', 'types', 'typings'].some((key) => key in manifest)
  )
    throw new Error('incomplete finalized Angular APF exports')

  function target(value, extension) {
    if (
      typeof value !== 'string' ||
      !value.startsWith('./') ||
      !value.endsWith(extension)
    )
      throw new Error(`invalid APF target: ${value}`)
    const file = realpathSync(join(dist, value))
    const path = relative(realpathSync(dist), file)
    if (
      path.startsWith(`..${sep}`) ||
      path === '..' ||
      !statSync(file).isFile()
    )
      throw new Error(`APF target escapes dist: ${value}`)
  }
  if (
    JSON.stringify(manifest.exports['./package.json']) !==
    JSON.stringify({ default: './package.json' })
  )
    throw new Error('invalid APF package.json export')
  for (const name of expected) {
    if (name === './package.json') continue
    const conditions = manifest.exports[name]
    if (
      JSON.stringify(Object.keys(conditions ?? {}).sort()) !==
      JSON.stringify(['default', 'types'])
    )
      throw new Error(`invalid APF conditions: ${name}`)
    target(conditions.types, '.d.ts')
    target(conditions.default, '.mjs')
  }
  target('./src/hilos-styles.scss', '.scss')
}

if (
  process.argv[1] &&
  resolve(process.argv[1]) === fileURLToPath(import.meta.url)
) {
  const packageDir = resolve(dirname(fileURLToPath(import.meta.url)), '..')
  const file = join(packageDir, 'dist/package.json')
  const manifest = JSON.parse(readFileSync(file, 'utf8'))
  delete manifest.exports?.['.']
  for (const key of ['main', 'module', 'types', 'typings']) delete manifest[key]
  validateApf(packageDir, manifest)
  writeFileSync(file, `${JSON.stringify(manifest, null, 2)}\n`)
}
