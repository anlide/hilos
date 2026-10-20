import { error, log } from 'node:console'
import { Buffer } from 'node:buffer'
import { existsSync, readFileSync, realpathSync, writeFileSync } from 'node:fs'
import { dirname, join, posix, resolve } from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'
import { gzipSync } from 'node:zlib'
import { sdkEvidence } from './initial-bundle-sdk.mjs'
import { projectEvidence } from './initial-bundle-project.mjs'

const REPORT_FILE = 'initial-bundle-report.json'
const DEFAULT_BUDGET = { warningBytes: 1_500_000, errorBytes: 5_000_000 }
const SCRIPT = /\.[cm]?js$/i
const CLIENT_ASSET = /\.(?:[cm]?js|css)$/i

function fail(message) {
  throw new Error(`initial-bundle-budget: ${message}`)
}

function slash(value) {
  return value.replaceAll('\\', '/')
}

function strings(values, label) {
  if (
    !Array.isArray(values) ||
    values.some((value) => typeof value !== 'string')
  ) {
    fail(`${label} is missing or incomplete`)
  }
  return values
}

function bytes(source) {
  if (typeof source !== 'string' && !(source instanceof Uint8Array)) {
    fail('an output has no emitted bytes')
  }
  return Buffer.from(source)
}

/**
 * Measure one complete client graph without applying policy. Both adapters and
 * explicit BEFORE captures use this same closure. Unknown composition is reported
 * as a refusal; it is never evidence that the initial graph is admin-free.
 */
export function measureInitialBundle(graph) {
  if (!graph || !Array.isArray(graph.chunks))
    fail('client output graph is missing')
  const roots = strings(graph.roots, 'client HTML roots')
  if (!roots.length || !roots.some((root) => SCRIPT.test(root)))
    fail('client HTML has no script roots')
  const projectRoot = graph.projectRoot ?? process.cwd()
  const evidence = sdkEvidence(projectRoot)
  const chunks = new Map()
  for (const chunk of graph.chunks) {
    if (
      !chunk ||
      typeof chunk.fileName !== 'string' ||
      !CLIENT_ASSET.test(chunk.fileName)
    ) {
      fail('graph contains an invalid client output')
    }
    if (chunks.has(chunk.fileName)) fail(`duplicate output ${chunk.fileName}`)
    strings(chunk.imports, `${chunk.fileName} static edges`)
    strings(chunk.dynamicImports, `${chunk.fileName} dynamic edges`)
    strings(chunk.modules, `${chunk.fileName} input modules`)
    if (SCRIPT.test(chunk.fileName) && !chunk.modules.length)
      fail(`${chunk.fileName} has no input module provenance`)
    bytes(chunk.source)
    chunks.set(chunk.fileName, chunk)
  }
  const project = projectEvidence(
    projectRoot,
    graph.projectSources,
    graph.chunks.flatMap((chunk) => chunk.modules),
    evidence,
  )
  function closure(entries) {
    const found = new Set()
    function visit(name) {
      if (found.has(name)) return
      const chunk = chunks.get(name)
      if (!chunk) fail(`unresolved client graph edge or root: ${name}`)
      found.add(name)
      for (const imported of chunk.imports) visit(imported)
      for (const imported of chunk.dynamicImports) {
        if (!chunks.has(imported)) fail(`unresolved dynamic edge: ${imported}`)
      }
    }
    for (const entry of entries) visit(entry)
    return found
  }
  const initial = closure(roots)
  function summarize(names) {
    const files = [...names].sort().map((name) => {
      const chunk = chunks.get(name)
      const content = bytes(chunk.source)
      return {
        fileName: name,
        bytes: content.length,
        gzipBytes: gzipSync(content).length,
        imports: [...chunk.imports].sort(),
        dynamicImports: [...chunk.dynamicImports].sort(),
        modules: [
          ...new Set(chunk.modules.flatMap((id) => evidence.modules(id))),
        ].sort(),
      }
    })
    const modules = [...new Set(files.flatMap((file) => file.modules))].sort()
    return {
      totalBytes: files.reduce((sum, file) => sum + file.bytes, 0),
      jsBytes: files
        .filter((file) => SCRIPT.test(file.fileName))
        .reduce((sum, file) => sum + file.bytes, 0),
      cssBytes: files
        .filter((file) => file.fileName.endsWith('.css'))
        .reduce((sum, file) => sum + file.bytes, 0),
      gzipBytes: files.reduce((sum, file) => sum + file.gzipBytes, 0),
      chunks: files,
      modules,
      forbiddenModules: modules.flatMap((id) => {
        const reason = project.verdict(id) ?? evidence.verdict(id)
        return reason ? [{ id, reason }] : []
      }),
    }
  }
  const pending = [...initial]
  const visited = new Set()
  const lazy = new Map()
  while (pending.length) {
    const name = pending.pop()
    if (visited.has(name)) continue
    visited.add(name)
    const chunk = chunks.get(name)
    pending.push(...chunk.imports)
    for (const entry of chunk.dynamicImports) {
      const section = closure([entry])
      if (!lazy.has(entry)) {
        lazy.set(
          entry,
          summarize(new Set([...section].filter((file) => !initial.has(file)))),
        )
      }
      pending.push(...section)
    }
  }
  return {
    version: 1,
    projectProof: project.report,
    roots: [...new Set(roots)].sort(),
    initial: summarize(initial),
    lazy: [...lazy]
      .sort(([a], [b]) => a.localeCompare(b))
      .map(([entry, section]) => ({ entry, ...section })),
  }
}

/** Return the strict verdict; no plugin or CLI option can disable either gate. */
export function checkInitialBundleBudget(report, options = {}) {
  const { warningBytes, errorBytes } = { ...DEFAULT_BUDGET, ...options }
  const budget = { warningBytes, errorBytes }
  if (
    !Number.isSafeInteger(budget.warningBytes) ||
    !Number.isSafeInteger(budget.errorBytes) ||
    budget.warningBytes < 0 ||
    budget.errorBytes < budget.warningBytes
  )
    fail('invalid byte budgets')
  const errors = report.initial.forbiddenModules.map(
    ({ id, reason }) => `${reason} in initial: ${id}`,
  )
  const warnings = []
  if (report.initial.totalBytes > budget.errorBytes) {
    errors.push(
      `initial JS+CSS ${report.initial.totalBytes} bytes exceeds error budget ${budget.errorBytes}`,
    )
  } else if (report.initial.totalBytes > budget.warningBytes) {
    warnings.push(
      `initial JS+CSS ${report.initial.totalBytes} bytes exceeds warning budget ${budget.warningBytes}`,
    )
  }
  return { ...budget, warnings, errors }
}

function htmlRoots(html, base = '/', htmlFile = 'index.html') {
  const roots = []
  for (const tag of html.matchAll(/<(?:script|link)\b[^>]*>/gi)) {
    const attrs = new Map(
      [
        ...tag[0].matchAll(/([\w-]+)\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))/g),
      ].map((match) => [
        match[1].toLowerCase(),
        match[2] ?? match[3] ?? match[4],
      ]),
    )
    const script = /^<script\b/i.test(tag[0])
    if (
      !script &&
      !/^(?:stylesheet|modulepreload)$/i.test(attrs.get('rel') ?? '')
    )
      continue
    const url = attrs.get(script ? 'src' : 'href')
    if (!url) continue
    let path = url.split(/[?#]/, 1)[0]
    const rooted =
      path.startsWith('/') || (base !== './' && path.startsWith(base))
    if (base !== '/' && base !== './' && path.startsWith(base))
      path = path.slice(base.length)
    if (/^(?:[a-z][\w+.-]*:|\/\/)/i.test(path))
      fail(`external client HTML root: ${url}`)
    path = posix.normalize(
      rooted
        ? path.replace(/^\//, '')
        : posix.join(posix.dirname(htmlFile), path),
    )
    if (!CLIENT_ASSET.test(path)) fail(`unsupported client HTML root: ${url}`)
    roots.push(path)
  }
  return roots
}

function cssImports(source, name, outputs) {
  const imports = []
  const css = bytes(source)
    .toString('utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '')
  const matches = [
    ...css.matchAll(
      /@import\s+(?:url\(\s*(?:"([^"]*)"|'([^']*)'|([^\s)]+))\s*\)|"([^"]*)"|'([^']*)')/gi,
    ),
  ]
  if (matches.length !== [...css.matchAll(/@import\b/gi)].length)
    fail(`unproven CSS imports in ${name}`)
  for (const match of matches) {
    const path = match
      .slice(1)
      .find((value) => value !== undefined)
      .split(/[?#]/, 1)[0]
    const target = posix.normalize(posix.join(posix.dirname(name), path))
    if (!outputs.has(target)) fail(`unresolved CSS import in ${name}: ${path}`)
    imports.push(target)
  }
  return imports
}

/** Adapt the actual Rollup outputs, including Vite's extracted CSS associations. */
export function viteBundleGraph(
  bundle,
  { projectRoot = process.cwd(), base = '/' } = {},
) {
  if (!bundle || typeof bundle !== 'object')
    fail('Vite output graph is missing')
  const outputs = new Set(Object.keys(bundle))
  const roots = Object.values(bundle)
    .filter(
      (output) => output.type === 'asset' && output.fileName.endsWith('.html'),
    )
    .flatMap((output) =>
      htmlRoots(bytes(output.source).toString('utf8'), base, output.fileName),
    )
  const chunks = Object.values(bundle)
    .filter((output) => CLIENT_ASSET.test(output.fileName))
    .map((output) => {
      if (output.type === 'chunk') {
        if (!output.modules || typeof output.modules !== 'object')
          fail(`${output.fileName} has no module graph`)
        if (!(output.viteMetadata?.importedCss instanceof Set))
          fail(`${output.fileName} has no Vite CSS dependency graph`)
        return {
          fileName: output.fileName,
          source: output.code,
          imports: [
            ...strings(output.imports, `${output.fileName} imports`),
            ...output.viteMetadata.importedCss,
          ],
          dynamicImports: strings(
            output.dynamicImports,
            `${output.fileName} dynamic imports`,
          ),
          modules: Object.keys(output.modules),
        }
      }
      if (!output.fileName.endsWith('.css'))
        fail(`${output.fileName} is JavaScript without an input module graph`)
      return {
        fileName: output.fileName,
        source: output.source,
        imports: cssImports(output.source, output.fileName, outputs),
        dynamicImports: [],
        modules: [],
      }
    })
  return { roots, chunks, projectRoot }
}

/**
 * Build-only Vite plugin, activated by the Vue and React demos for client builds.
 */
export function initialBundleBudget(options = {}) {
  let client = false
  let config
  let report
  return {
    name: 'hilos-initial-bundle-budget',
    apply: 'build',
    enforce: 'post',
    configResolved(resolved) {
      config = resolved
      client = !resolved.build.ssr
      report = undefined
    },
    generateBundle: {
      order: 'post',
      handler(_outputOptions, bundle) {
        if (!client) return
        report = measureInitialBundle({
          ...viteBundleGraph(bundle, {
            projectRoot: config.root,
            base: config.base,
          }),
          projectSources: options.projectSources,
        })
        report.budget = checkInitialBundleBudget(report, options)
        this.emitFile({
          type: 'asset',
          fileName: REPORT_FILE,
          source: `${JSON.stringify(report, null, 2)}\n`,
        })
      },
    },
    writeBundle() {
      if (!client) return
      if (!report)
        this.error('initial-bundle-budget: client graph report is missing')
      for (const warning of report.budget.warnings) this.warn(warning)
      if (report.budget.errors.length)
        this.error(report.budget.errors.join('\n'))
    },
  }
}

/** Read Angular's esbuild stats and the final client HTML, never server/prerender entrypoints. */
export function angularBundleGraph(directory) {
  const dist = resolve(directory)
  const projectRoot = dirname(dist)
  const stats = JSON.parse(readFileSync(join(dist, 'stats.json'), 'utf8'))
  if (
    !stats.inputs ||
    typeof stats.inputs !== 'object' ||
    Array.isArray(stats.inputs) ||
    !stats.outputs ||
    typeof stats.outputs !== 'object' ||
    Array.isArray(stats.outputs)
  )
    fail('Angular stats has no complete input/output graph')
  const htmlFile = ['index.csr.html', 'index.html'].find((name) =>
    existsSync(join(dist, name)),
  )
  if (!htmlFile) fail('Angular client HTML is missing')
  const roots = htmlRoots(readFileSync(join(dist, htmlFile), 'utf8'))
  const outputs = new Map(
    Object.entries(stats.outputs).map(([name, output]) => [
      slash(name),
      output,
    ]),
  )
  const pending = [...roots]
  const visited = new Set()
  const chunks = []
  const evidence = sdkEvidence(projectRoot)
  while (pending.length) {
    const fileName = pending.pop()
    if (visited.has(fileName)) continue
    visited.add(fileName)
    if (/(?:^|\/)server\/|\.server\.[cm]?js$/.test(fileName))
      fail(`server output reached by client: ${fileName}`)
    const output = outputs.get(fileName)
    if (!output) fail(`unresolved Angular client output: ${fileName}`)
    const source = readFileSync(join(dist, fileName))
    if (source.length !== output.bytes)
      fail(`Angular stats bytes do not match emitted ${fileName}`)
    if (
      !output.inputs ||
      typeof output.inputs !== 'object' ||
      Array.isArray(output.inputs)
    )
      fail(`${fileName} input graph is missing`)
    const modules = Object.keys(output.inputs).map((id) => {
      if (!Object.hasOwn(stats.inputs, id))
        fail(`${fileName} has an unknown input: ${id}`)
      return id.startsWith('@hilos/') || id.startsWith('\0')
        ? id
        : slash(resolve(projectRoot, id))
    })
    if (!Array.isArray(output.imports))
      fail(`${fileName} import graph is missing`)
    const imports = []
    const dynamicImports = []
    for (const edge of output.imports) {
      if (edge.kind === 'url-token' || !CLIENT_ASSET.test(edge.path)) {
        if (edge.kind === 'file-loader' || edge.kind === 'url-token') continue
        fail(`unsupported Angular edge from ${fileName}: ${edge.path}`)
      }
      if (edge.external)
        fail(`external Angular client edge from ${fileName}: ${edge.path}`)
      const target = outputs.has(edge.path)
        ? edge.path
        : posix.normalize(posix.join(posix.dirname(fileName), edge.path))
      if (edge.kind === 'dynamic-import') dynamicImports.push(target)
      else if (
        ['import-statement', 'import-rule', 'require-call'].includes(edge.kind)
      )
        imports.push(target)
      else fail(`unsupported Angular import kind: ${edge.kind}`)
    }
    if (output.cssBundle) imports.push(output.cssBundle)
    if (SCRIPT.test(fileName) && !modules.length) {
      const entry = output.entryPoint
      try {
        if (
          typeof entry !== 'string' ||
          !entry ||
          entry.includes('\\') ||
          !Object.hasOwn(stats.inputs, entry) ||
          !stats.inputs[entry] ||
          typeof stats.inputs[entry] !== 'object' ||
          Array.isArray(stats.inputs[entry])
        )
          fail('missing or invalid compiler entryPoint input')
        const origin = slash(resolve(projectRoot, entry))
        const forwarding = evidence.angularForwarder(
          origin,
          source.toString('utf8'),
          output.exports,
        )
        if (
          dynamicImports.length ||
          output.cssBundle ||
          output.imports.some(
            (edge) => edge.kind !== 'import-statement' || edge.external,
          )
        )
          fail('non-static forwarding edge')
        const targets = forwarding.map(({ specifier, names }) => {
          const target = posix.normalize(
            posix.join(posix.dirname(fileName), specifier),
          )
          const claimed = outputs.get(target)
          if (!claimed) fail(`unresolved forwarding output: ${target}`)
          if (names.length) {
            const exported = strings(claimed.exports, `${target} exports`)
            if (
              new Set(exported).size !== exported.length ||
              names.some((name) => !exported.includes(name))
            )
              fail(`unresolved forwarding imported name in ${target}`)
          }
          return target
        })
        if (
          new Set(targets).size !== targets.length ||
          JSON.stringify([...targets].sort()) !==
            JSON.stringify([...imports].sort())
        )
          fail('inconsistent forwarding static edges')
        modules.push(origin)
      } catch (reason) {
        fail(
          `unproven Angular forwarding output ${fileName} (${String(entry)}): ${reason.message}`,
        )
      }
    }
    pending.push(...imports, ...dynamicImports)
    chunks.push({ fileName, source, imports, dynamicImports, modules })
  }
  // Outputs not reachable from the client shell can include SSR; the closure
  // calculation deliberately never treats stats entryPoint as a client root.
  return { roots, chunks, projectRoot }
}

/** Strict Angular CLI entry, exposed separately so tests need no child process. */
export function runInitialBundleBudget(argv) {
  if (
    argv[0] !== 'angular' ||
    (argv.length !== 2 &&
      (argv.length !== 5 || argv[2] !== '--project-sources'))
  )
    fail(
      'usage: initial-bundle-budget.mjs angular <dist> [--project-sources <routes> <views>]',
    )
  const report = measureInitialBundle({
    ...angularBundleGraph(argv[1]),
    ...(argv.length === 5
      ? { projectSources: { routes: argv[3], views: argv[4] } }
      : {}),
  })
  report.budget = checkInitialBundleBudget(report)
  writeFileSync(
    join(argv[1], REPORT_FILE),
    `${JSON.stringify(report, null, 2)}\n`,
  )
  return report
}

if (
  process.argv[1] &&
  realpathSync(process.argv[1]) === fileURLToPath(import.meta.url)
) {
  try {
    const report = runInitialBundleBudget(process.argv.slice(2))
    log(
      `initial JS+CSS: ${report.initial.totalBytes} bytes (${report.initial.gzipBytes} gzip); ${report.lazy.length} lazy entries`,
    )
    for (const warning of report.budget.warnings) error(warning)
    for (const refusal of report.budget.errors) error(refusal)
    if (report.budget.errors.length) process.exitCode = 1
  } catch (reason) {
    error(String(reason))
    process.exitCode = 1
  }
}
