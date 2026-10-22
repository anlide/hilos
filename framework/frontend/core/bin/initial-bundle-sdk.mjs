// Build-only evidence for compiled SDK inputs. This module never adds output
// assets: source attribution is separate from the client's emitted-byte graph.
import { existsSync, readFileSync, realpathSync } from 'node:fs'
import {
  basename,
  dirname,
  isAbsolute,
  join,
  relative,
  resolve,
} from 'node:path'
import { fileURLToPath, URL } from 'node:url'
import { sha256 } from './core-provenance.mjs'

const LAYERS = ['core', 'vue', 'react', 'angular']
const SDK =
  /(?:^|\/)(?:framework\/frontend\/|node_modules\/@hilos\/)(core|vue|react|angular)\/(.+)$/
const ALIAS = /^@hilos\/(core|vue|react|angular)(\/.*)?$/
const STYLE = /\.(?:css|scss|sass|less)$/
const UNKNOWN = 'unproven SDK output composition'
const ADMIN = 'admin implementation or section entrypoint'
const RESERVED_BINDINGS = new Set(
  'arguments await break case catch class const continue debugger default delete do else enum eval export extends false finally for function if implements import in instanceof interface let new null package private protected public return static super switch this throw true try typeof var void while with yield'.split(
    ' ',
  ),
)
// Reviewed runtime closure of core/base after the physical protocol split.
// A new filename or a moved admin implementation earns no permission by name.
// Update this content inventory only after reviewing the changed eager owners.
const CORE_INITIAL_SOURCES = json(
  fileURLToPath(new URL('./core-initial-sources.json', import.meta.url)),
)

function fail(message) {
  throw new Error(message)
}

function slash(value) {
  return value.replaceAll('\\', '/')
}

function clean(id) {
  return slash(id).replace(/^\0/, '').split(/[?#]/, 1)[0]
}

function canonical(id) {
  const path = clean(id)
  return isAbsolute(path) && existsSync(path) ? slash(realpathSync(path)) : path
}

function json(path) {
  return JSON.parse(readFileSync(path, 'utf8'))
}

function list(value, label) {
  if (
    !Array.isArray(value) ||
    value.some((id) => typeof id !== 'string' || !id)
  )
    fail(`missing or incomplete ${label}`)
  return value
}

function inside(directory, path) {
  const name = slash(relative(directory, path))
  return name && !name.startsWith('../') && !isAbsolute(name)
}

function production(target) {
  while (target && typeof target === 'object' && !Array.isArray(target))
    target = target.import ?? target.node ?? target.default
  return target
}

function targetFile(directory, target, boundary = directory) {
  if (typeof target !== 'string' || !target || isAbsolute(target))
    fail('missing production export')
  const file = canonical(resolve(directory, target))
  if (!inside(boundary, file))
    fail(`production export escapes package: ${target}`)
  return file
}

function publicEntry(name) {
  return (
    ['.', './base', './admin-views'].includes(name) ||
    name.startsWith('./admin/')
  )
}

function boundaryVerdict(name) {
  return name === '.'
    ? 'compatibility root'
    : name.startsWith('./admin/')
      ? ADMIN
      : null
}

// Read compiled ESM tokens, including imports inside template interpolations.
// Strings, comments and regex bodies cannot supply edges. Unsupported import
// forms refuse evidence rather than silently dropping a possible dependency.
function tokens(code) {
  const result = []
  let index = 0
  let previous = ''
  const expression =
    /^(?:|[({[=,:;!?]|=>|return|throw|case|delete|void|typeof|yield|in|of|else|do|&&|\|\||\?\?)$/
  function push(kind, value) {
    result.push({ kind, value })
    previous = value
  }
  function scan(interpolation = false) {
    let depth = 0
    while (index < code.length) {
      const char = code[index]
      if (/\s/.test(char)) {
        index++
        continue
      }
      if (code.startsWith('//', index)) {
        const end = code.slice(index + 2).search(/[\n\r\u2028\u2029]/)
        index = end < 0 ? code.length : index + 3 + end
        continue
      }
      if (code.startsWith('/*', index)) {
        const end = code.indexOf('*/', index + 2)
        if (end < 0) fail('unterminated compiled comment')
        index = end + 2
        continue
      }
      if (char === '"' || char === "'") {
        const start = ++index
        while (index < code.length && code[index] !== char) {
          if (code[index] === '\\') index++
          index++
        }
        if (index >= code.length) fail('unterminated compiled string')
        push('string', code.slice(start, index++))
        continue
      }
      if (char === '`') {
        index++
        push('template', '`')
        for (;;) {
          if (index >= code.length) fail('unterminated compiled template')
          if (code[index] === '\\') {
            index += 2
            continue
          }
          if (code[index] === '`') {
            index++
            break
          }
          if (code.startsWith('${', index)) {
            index += 2
            previous = '{'
            scan(true)
          } else index++
        }
        push('template', '`')
        continue
      }
      if (char === '/' && expression.test(previous)) {
        index++
        let bracket = false
        let closed = false
        while (index < code.length) {
          const next = code[index++]
          if (next === '\\') {
            index++
            continue
          }
          if (next === '[') bracket = true
          if (next === ']') bracket = false
          if (next === '/' && !bracket) {
            closed = true
            break
          }
          if (next === '\n') fail('unproven compiled regex')
        }
        if (!closed) fail('unterminated compiled regex')
        push('regex', '/')
        continue
      }
      if (/[\w$]/.test(char)) {
        const start = index++
        while (index < code.length && /[\w$]/.test(code[index])) index++
        push('word', code.slice(start, index))
        continue
      }
      if (char === '{') depth++
      if (char === '}') {
        if (interpolation && depth === 0) {
          index++
          return
        }
        depth--
      }
      const pair = code.slice(index, index + 2)
      const value = ['=>', '&&', '||', '??', '?.'].includes(pair) ? pair : char
      push('punctuation', value)
      index += value.length
    }
    if (interpolation) fail('unterminated compiled interpolation')
  }
  scan()
  return result
}

// A deliberately small grammar over the same lexer as compiledEdges. Token
// kinds matter: text inside a string can never impersonate punctuation or code.
function namedClause(syntax, start) {
  let index = start
  function take(kind, value) {
    const token = syntax[index++]
    if (token?.kind !== kind || (value && token.value !== value))
      fail('unsupported forwarding syntax')
    return token.value
  }
  function name() {
    const value = take('word')
    if (!/^[a-zA-Z_$][\w$]*$/.test(value))
      fail('unsupported forwarding identifier')
    return value
  }
  const bindings = []
  take('punctuation', '{')
  while (
    syntax[index]?.value !== '}' ||
    syntax[index]?.kind !== 'punctuation'
  ) {
    const original = name()
    let alias = original
    if (syntax[index]?.kind === 'word' && syntax[index]?.value === 'as') {
      index++
      alias = name()
    }
    bindings.push({ original, alias })
    if (syntax[index]?.kind !== 'punctuation' || syntax[index]?.value !== ',')
      break
    index++
  }
  take('punctuation', '}')
  return { bindings, end: index }
}

function forwardingSyntax(code) {
  const syntax = tokens(code)
  const imports = []
  const locals = new Set()
  const exports = []
  const references = []
  let index = 0
  function take(kind, value) {
    const token = syntax[index++]
    if (token?.kind !== kind || (value && token.value !== value))
      fail('unsupported forwarding syntax')
    return token.value
  }
  function source(names) {
    const specifier = take('string')
    if (!/^\.{1,2}\/[^\\\s?#]+\.[cm]?js$/.test(specifier))
      fail('unsupported forwarding import target')
    imports.push({ specifier, names })
  }
  while (index < syntax.length) {
    const kind = take('word')
    if (kind === 'import' && syntax[index]?.kind === 'string') source([])
    else {
      if (!['import', 'export'].includes(kind))
        fail('executable or unsupported forwarding statement')
      const clause = namedClause(syntax, index)
      index = clause.end
      if (!clause.bindings.length) fail('empty forwarding clause')
      if (kind === 'import') {
        for (const { alias } of clause.bindings) {
          if (RESERVED_BINDINGS.has(alias))
            fail('invalid forwarding local binding')
          if (locals.has(alias)) fail('duplicate forwarding local binding')
          locals.add(alias)
        }
      } else {
        exports.push(...clause.bindings.map(({ alias }) => alias))
      }
      if (kind === 'import' || syntax[index]?.value === 'from') {
        take('word', 'from')
        source(clause.bindings.map(({ original }) => original))
      } else references.push(...clause.bindings.map(({ original }) => original))
    }
    take('punctuation', ';')
  }
  if (references.some((name) => !locals.has(name)))
    fail('unresolved forwarding local binding')
  if (!exports.length || new Set(exports).size !== exports.length)
    fail('missing or duplicate forwarding exports')
  return { imports, exports }
}

function compiledExportNames(code) {
  const syntax = tokens(code)
  const names = []
  for (let index = 0; index < syntax.length; index++) {
    if (syntax[index].kind !== 'word' || syntax[index].value !== 'export')
      continue
    if (['.', '?.'].includes(syntax[index - 1]?.value)) continue
    const clause = namedClause(syntax, index + 1)
    names.push(...clause.bindings.map(({ alias }) => alias))
    index = clause.end - 1
  }
  if (!names.length || new Set(names).size !== names.length)
    fail('missing or duplicate APF exports')
  return names
}

function compiledEdges(code) {
  const syntax = tokens(code)
  const imports = []
  const dynamicImports = []
  function specifier(token) {
    if (token?.kind !== 'string' || token.value.includes('\\'))
      fail('unproven compiled import target')
    return token.value
  }
  for (let index = 0; index < syntax.length; index++) {
    const token = syntax[index]
    if (
      token.kind !== 'word' ||
      !['import', 'export', 'require'].includes(token.value)
    )
      continue
    if (['.', '?.'].includes(syntax[index - 1]?.value)) continue
    const next = syntax[index + 1]
    if (token.value === 'require') {
      if (next?.value === '(') fail('unsupported compiled require')
      continue
    }
    if (token.value === 'import' && next?.value === '.') continue
    if (token.value === 'import' && next?.value === '(') {
      if (syntax[index + 3]?.value !== ')')
        fail('nonliteral compiled dynamic import')
      dynamicImports.push(specifier(syntax[index + 2]))
      continue
    }
    if (token.value === 'import' && next?.kind === 'string') {
      imports.push(specifier(next))
      continue
    }
    if (token.value === 'export' && !['*', '{'].includes(next?.value)) continue
    let cursor = index + 1
    let braces = 0
    for (; cursor < syntax.length; cursor++) {
      const current = syntax[cursor]
      if (current.value === '{') braces++
      if (current.value === '}') braces--
      if (!braces && current.value === 'from') {
        imports.push(specifier(syntax[cursor + 1]))
        break
      }
      if (!braces && current.value === ';') break
      // An export list without a source need not have a semicolon.
      if (
        !braces &&
        current.value === '}' &&
        syntax[cursor + 1]?.value !== 'from'
      )
        break
    }
    if (
      token.value === 'import' &&
      (cursor === syntax.length || syntax[cursor]?.value !== 'from')
    )
      fail('unproven compiled static import')
  }
  return { imports, dynamicImports }
}

function sameEdges(actual, claimed, label) {
  if (
    JSON.stringify([...new Set(actual)].sort()) !==
    JSON.stringify([...new Set(claimed)].sort())
  )
    fail(`inconsistent ${label}`)
}

// Validate the compiler's VLQ source references. Removing an entry from sources
// or sourcesContent must not turn an incomplete map into evidence of clean code.
function compilerModules(file, code, pkg) {
  const mapFile = `${file}.map`
  if (!code.trimEnd().endsWith(`//# sourceMappingURL=${basename(mapFile)}`))
    fail('missing compiler source map reference')
  const map = json(mapFile)
  const sources = list(map.sources, 'compiler sources')
  const contents = list(map.sourcesContent, 'compiler source snapshots')
  if (
    map.version !== 3 ||
    map.file !== basename(file) ||
    map.sourceRoot ||
    !sources.length ||
    sources.length !== contents.length ||
    typeof map.mappings !== 'string' ||
    !map.mappings
  )
    fail('missing or incomplete compiler map')
  const modules = sources.map((source, index) => {
    const match = /^(?:\.\.\/)*src\/(.+)$/.exec(slash(source))
    if (!match) fail(`unknown compiler source: ${source}`)
    const path = resolve(pkg.directory, 'src', match[1])
    if (!inside(join(pkg.directory, 'src'), path))
      fail('compiler source escapes src')
    if (existsSync(path) && readFileSync(path, 'utf8') !== contents[index])
      fail(`inconsistent compiler source snapshot: ${source}`)
    return slash(path)
  })
  if (new Set(modules).size !== modules.length)
    fail('duplicate compiler sources')
  const sourceLines = contents.map((content) => content.split('\n'))
  const generatedLines = code.split('\n')
  const alphabet =
    'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/'
  const used = new Set()
  let source = 0
  let originalLine = 0
  let originalColumn = 0
  for (const [lineIndex, line] of map.mappings.split(';').entries()) {
    let generatedColumn = 0
    for (const segment of line.split(',')) {
      if (!segment) continue
      const fields = []
      let value = 0
      let shift = 0
      for (const char of segment) {
        const digit = alphabet.indexOf(char)
        if (digit < 0 || shift > 30) fail('invalid compiler mappings')
        value += (digit & 31) * 2 ** shift
        if (digit & 32) shift += 5
        else {
          fields.push(value & 1 ? -Math.floor(value / 2) : value / 2)
          value = 0
          shift = 0
        }
      }
      if (shift || ![1, 4, 5].includes(fields.length))
        fail('invalid compiler mappings')
      generatedColumn += fields[0]
      if (
        generatedColumn < 0 ||
        generatedColumn > (generatedLines[lineIndex]?.length ?? -1)
      )
        fail('unresolved generated compiler mapping')
      if (fields.length === 1) continue
      source += fields[1]
      originalLine += fields[2]
      originalColumn += fields[3]
      if (
        source < 0 ||
        source >= sources.length ||
        originalLine < 0 ||
        originalColumn < 0 ||
        originalColumn > (sourceLines[source]?.[originalLine]?.length ?? -1)
      )
        fail('unresolved compiler source mapping')
      used.add(source)
    }
  }
  if (used.size !== sources.length) fail('incomplete compiler source mappings')
  return modules
}

/** One measurement owns its resolver/cache; none of this is runtime SDK state. */
export function sdkEvidence(projectRoot) {
  const packages = new Map()
  const entries = new Map()
  const proof = new Map()
  const refusals = new Map()
  const expanded = new Map()
  const coreSources = new Map()
  const coreOutputs = new Map()

  function findPackage(layer) {
    if (packages.has(layer)) return packages.get(layer)
    let directory = resolve(projectRoot)
    for (;;) {
      const candidate = join(directory, 'node_modules', '@hilos', layer)
      if (existsSync(join(candidate, 'package.json'))) {
        const pkg = { layer, directory: canonical(candidate) }
        pkg.manifest = json(join(pkg.directory, 'package.json'))
        pkg.exports = pkg.manifest.exports
        pkg.exportDirectory = pkg.directory
        // A local Angular wrapper declares main/module, while ng-packagr owns
        // exports on the generated APF manifest beside the actual output.
        if (layer === 'angular' && !pkg.exports) {
          const root = targetFile(
            pkg.directory,
            pkg.manifest.module ?? pkg.manifest.main,
          )
          let parent = dirname(root)
          while (inside(pkg.directory, parent)) {
            const manifest = join(parent, 'package.json')
            if (existsSync(manifest)) {
              const generated = json(manifest)
              if (generated.exports) {
                pkg.exports = generated.exports
                pkg.exportDirectory = parent
                break
              }
            }
            parent = dirname(parent)
          }
        }
        if (layer === 'angular' && !pkg.exports)
          fail('missing generated Angular APF exports')
        packages.set(layer, pkg)
        for (const [name, target] of Object.entries(
          pkg.exports ?? { '.': pkg.manifest.module ?? pkg.manifest.main },
        )) {
          if (!publicEntry(name)) continue
          const file = targetFile(
            pkg.exportDirectory,
            production(target),
            pkg.directory,
          )
          if (pkg.exportDirectory !== pkg.directory) {
            const manifestDirectory =
              name === '.' ? pkg.directory : join(pkg.directory, name.slice(2))
            const wrapper =
              name === '.'
                ? pkg.manifest
                : json(join(manifestDirectory, 'package.json'))
            const forwarded = targetFile(
              manifestDirectory,
              wrapper.module ?? wrapper.main,
              pkg.directory,
            )
            if (forwarded !== file)
              fail(`inconsistent Angular APF forwarder: ${name}`)
          }
          if (!entries.has(file) || boundaryVerdict(name))
            entries.set(file, boundaryVerdict(name))
        }
        return pkg
      }
      const parent = dirname(directory)
      if (parent === directory) break
      directory = parent
    }
    packages.set(layer, null)
    return null
  }

  function resolveModule(id) {
    const alias = ALIAS.exec(clean(id))
    if (!alias) {
      const file = canonical(id)
      if (file !== clean(id)) {
        const original = identify(clean(id))
        const resolved = identify(file)
        const originalPackage =
          original.pkg ?? (original.layer ? findPackage(original.layer) : null)
        if (original.layer && originalPackage !== resolved.pkg)
          refusals.set(
            file,
            `${UNKNOWN}: SDK input symlink escapes its package: ${clean(id)}`,
          )
      }
      return file
    }
    const pkg = findPackage(alias[1])
    if (!pkg) fail(`initial-bundle-budget: cannot resolve SDK alias ${id}`)
    const name = alias[2] ? `.${alias[2]}` : '.'
    let target = pkg.exports?.[name]
    let directory = pkg.exportDirectory
    if (!pkg.exports) {
      directory =
        name === '.' ? pkg.directory : join(pkg.directory, name.slice(2))
      const manifestFile = join(directory, 'package.json')
      const manifest = existsSync(manifestFile) ? json(manifestFile) : {}
      target = manifest.module ?? manifest.main
    }
    if (target === undefined)
      fail(`initial-bundle-budget: SDK alias ${id} has no production export`)
    const file = targetFile(directory, production(target), pkg.directory)
    if (!existsSync(file))
      fail(
        `initial-bundle-budget: SDK alias ${id} points at a missing output: ${file}`,
      )
    return file
  }

  function identify(id) {
    for (const layer of LAYERS) {
      const pkg = findPackage(layer)
      if (pkg && inside(pkg.directory, id))
        return { pkg, path: slash(relative(pkg.directory, id)), layer }
    }
    const match = SDK.exec(id)
    return match ? { layer: match[1], path: match[2] } : {}
  }

  function verdict(id) {
    if (refusals.has(id)) return refusals.get(id)
    if (STYLE.test(id)) return null
    const { pkg, layer, path } = identify(id)
    if (entries.get(id)) return entries.get(id)
    if (layer === 'core') {
      if (/^(?:src|dist)\/index\.[^/]+$/.test(path)) return 'compatibility root'
      if (
        /^(?:src|dist)\/admin\//.test(path) &&
        !/^(?:src|dist)\/admin\/(?:identity\/hilosPage(?:Identity|InstanceName)|viewMode)\.[^/]+$/.test(
          path,
        )
      )
        return ADMIN
      if (path.startsWith('src/')) {
        if (coreOutputs.get(id) === false) {
          const output = join(
            pkg.directory,
            'dist',
            path.slice(4).replace(/\.ts$/, '.js'),
          )
          return `${UNKNOWN}: unapproved core compiler output: ${output}`
        }
        const hash = existsSync(id)
          ? sha256(readFileSync(id))
          : coreSources.get(id)
        return pkg &&
          coreSources.has(id) &&
          hash === CORE_INITIAL_SOURCES[path]?.sourceSha256
          ? null
          : `${UNKNOWN}: unapproved core source snapshot: ${id}`
      }
      if (coreOutputs.has(id) && !coreOutputs.get(id))
        return `${UNKNOWN}: unapproved core compiler output: ${id}`
      return refusals.get(id) ?? (proof.has(id) ? null : UNKNOWN)
    }
    if (layer && layer !== 'core') {
      if (
        /^(?:src\/index\.[^/]+|dist\/index\.js|(?:dist\/)?fesm\d+\/hilos-(?:vue|react|angular)\.mjs)$/.test(
          path,
        )
      )
        return 'compatibility root'
      if (/^src\/admin\/hilosAdminViews\.[^/]+$/.test(path)) return null
      if (/^(?:src|dist)\/admin\//.test(path)) return ADMIN
      if (/^src\//.test(path)) return null
      return refusals.get(id) ?? (proof.has(id) ? null : UNKNOWN)
    }
    if (
      /(?:^|\/)(?:src|dist)\/admin\//.test(id) &&
      !/\/(?:hilosAdminViews|admin-views)\.[^/]+$/.test(id)
    )
      return ADMIN
    return null
  }

  function load(file, pkg) {
    if (proof.has(file)) return proof.get(file)
    if (pkg.layer === 'core' && inside(join(pkg.directory, 'src'), file)) {
      if (!existsSync(file)) fail(`missing SDK source input: ${file}`)
      const source = slash(relative(pkg.directory, file))
      const output = join(
        pkg.directory,
        'dist',
        source.slice(4).replace(/\.ts$/, '.js'),
      )
      const result = load(output, pkg)
      coreOutputs.set(file, coreOutputs.get(output))
      proof.set(file, result)
      return result
    }
    if (!/\.[cm]?js$/.test(file)) fail(`unknown compiled SDK output: ${file}`)
    if (!existsSync(file)) fail(`missing SDK output: ${file}`)
    const code = readFileSync(file, 'utf8')
    const edges = compiledEdges(code)
    let modules
    let directory
    let record
    if (pkg.layer === 'core') {
      directory = join(pkg.directory, 'dist')
      const name = slash(relative(directory, file))
      const provenance = json(join(directory, 'core-provenance.json'))
      const receipt = provenance.files?.[name]
      if (provenance.version !== 1 || provenance.compiler !== 'tsc' || !receipt)
        fail(`missing tsc provenance: ${file}`)
      modules = compilerModules(file, code, pkg)
      const source = `src/${name.replace(/\.js$/, '.ts')}`
      const mapBytes = readFileSync(`${file}.map`)
      const map = JSON.parse(mapBytes)
      if (
        receipt.source !== source ||
        modules.length !== 1 ||
        modules[0] !== slash(resolve(pkg.directory, source)) ||
        receipt.sourceSha256 !== sha256(map.sourcesContent[0]) ||
        receipt.outputSha256 !== sha256(code) ||
        receipt.mapSha256 !== sha256(mapBytes)
      )
        fail(`inconsistent tsc provenance: ${file}`)
      coreSources.set(modules[0], receipt.sourceSha256)
      coreOutputs.set(
        file,
        receipt.outputSha256 === CORE_INITIAL_SOURCES[source]?.outputSha256,
      )
    } else if (pkg.layer === 'angular') {
      modules = compilerModules(file, code, pkg)
      directory = pkg.exportDirectory
    } else {
      // Production entry declarations locate dist; no output basename is a
      // whitelist. Shared outputs use the same emitted provenance beside them.
      directory = dirname(file)
      while (
        inside(pkg.directory, directory) &&
        !existsSync(join(directory, 'module-provenance.json'))
      )
        directory = dirname(directory)
      const provenance = json(join(directory, 'module-provenance.json'))
      record = provenance[slash(relative(directory, file))]
      if (!record || typeof record !== 'object')
        fail('missing emitted module provenance')
      modules = list(record.modules, 'SDK source modules').map((id) => {
        if (isAbsolute(clean(id))) {
          if (!STYLE.test(clean(id))) fail(`unknown absolute SDK source: ${id}`)
          return canonical(id)
        }
        const path = resolve(pkg.directory, clean(id))
        if (
          !clean(id).startsWith('src/') ||
          !inside(join(pkg.directory, 'src'), path)
        )
          fail(`unknown SDK source: ${id}`)
        return slash(path)
      })
      if (!modules.length) fail('empty SDK source composition')
    }
    function edge(specifier) {
      if (specifier.startsWith('.')) {
        const target = canonical(resolve(dirname(file), specifier))
        if (!inside(directory, target) || !existsSync(target))
          fail(`unresolved SDK edge: ${specifier}`)
        return target
      }
      if (ALIAS.test(specifier)) return resolveModule(specifier)
      if (/^(?:\/|[a-z][\w+.-]*:)/i.test(specifier))
        fail(`unknown SDK edge: ${specifier}`)
      return specifier
    }
    const imports = edges.imports.map(edge)
    const dynamicImports = edges.dynamicImports.map(edge)
    if (record) {
      function claimed(specifier) {
        const path = resolve(directory, specifier)
        return existsSync(path) ? canonical(path) : edge(specifier)
      }
      sameEdges(
        imports.filter((id) => !STYLE.test(id)),
        list(record.imports, 'SDK static edges').map(claimed),
        'SDK static edges',
      )
      sameEdges(
        dynamicImports,
        list(record.dynamicImports, 'SDK dynamic edges').map(claimed),
        'SDK dynamic edges',
      )
      sameEdges(
        imports.filter((id) => STYLE.test(id)),
        list(record.css, 'SDK CSS edges').map(claimed),
        'SDK CSS edges',
      )
    }
    const result = { modules, imports, dynamicImports }
    proof.set(file, result)
    return result
  }

  function expandable(file) {
    const { pkg, layer, path } = identify(file)
    return (
      pkg &&
      !STYLE.test(file) &&
      (layer === 'core' || !path.startsWith('src/')) &&
      entries.get(file) !== 'compatibility root'
    )
  }

  function dependencies(edges) {
    return edges
      .filter((id) => isAbsolute(id) || ALIAS.test(id))
      .map(resolveModule)
  }

  function validate(file, includeRoots = false) {
    const pending = [file]
    const visited = new Set()
    while (pending.length) {
      const target = pending.pop()
      if (visited.has(target)) continue
      const { pkg } = identify(target)
      if (!expandable(target) && !(includeRoots && pkg && !STYLE.test(target)))
        continue
      visited.add(target)
      let entry
      try {
        entry = load(target, pkg)
      } catch (reason) {
        fail(`${target}: ${reason.message}`)
      }
      pending.push(...dependencies([...entry.imports, ...entry.dynamicImports]))
    }
    return visited
  }

  function expand(file) {
    if (expanded.has(file)) return expanded.get(file)
    if (!expandable(file)) return [file]
    // Validate both kinds of reachable edges before attributing only the static
    // closure. Cycles and shared inputs do not omit sources or conceal failures.
    try {
      validate(file)
    } catch (reason) {
      refusals.set(file, `${UNKNOWN}: ${reason.message}`)
    }
    const modules = new Set()
    const pending = [file]
    while (pending.length) {
      const target = pending.pop()
      if (modules.has(target)) continue
      modules.add(target)
      if (!expandable(target)) continue
      const entry = proof.get(target)
      // Keep the already proved static owners on refusal as well. The precise
      // failed compiled input remains in the refusal; dynamic owners never join
      // static attribution, even when their evidence caused that refusal.
      if (!entry) continue
      for (const source of entry.modules) modules.add(source)
      pending.push(...dependencies(entry.imports))
    }
    const result = [...modules]
    expanded.set(file, result)
    return result
  }

  // Internal build-adapter proof, never a content permission. Even an admin or
  // compatibility origin must have valid compiler evidence before attribution.
  function angularForwarder(origin, code, outputExports) {
    if (!isAbsolute(origin) || clean(origin) !== slash(origin))
      fail('invalid Angular compiler entryPoint')
    const pkg = findPackage('angular')
    const file = resolveModule(origin)
    const parent = canonical(dirname(origin))
    if (
      !pkg ||
      !inside(pkg.exportDirectory, file) ||
      (parent !== pkg.exportDirectory &&
        !inside(pkg.exportDirectory, parent)) ||
      inside(join(pkg.directory, 'src'), file) ||
      !entries.has(file)
    )
      fail(
        `Angular compiler entryPoint is not a generated APF public export: ${origin}`,
      )
    const names = list(outputExports, 'Angular output exports')
    if (!names.length || new Set(names).size !== names.length)
      fail('missing or duplicate Angular output exports')
    const syntax = forwardingSyntax(code)
    sameEdges(syntax.exports, names, 'forwarding output exports')
    sameEdges(
      syntax.exports,
      compiledExportNames(readFileSync(file, 'utf8')),
      'forwarding APF exports',
    )
    for (const target of validate(file, true)) {
      for (const id of expand(target)) {
        const reason = verdict(id)
        if (reason && reason !== ADMIN && reason !== 'compatibility root')
          fail(`${id}: ${reason}`)
      }
    }
    return syntax.imports
  }

  return {
    modules: (id) => expand(resolveModule(id)),
    verdict,
    angularForwarder,
  }
}
