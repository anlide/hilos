/** Build-only client output. Sources are the final emitted bytes, never input sizes. */
export interface InitialBundleChunk {
  fileName: string
  source: string | Uint8Array
  imports: string[]
  dynamicImports: string[]
  /** Actual contributing input IDs; required and nonempty for every JS chunk. */
  modules: string[]
}

export interface InitialBundleGraph {
  roots: string[]
  chunks: InitialBundleChunk[]
  /** Consumer root used to resolve public SDK aliases to installed production outputs. */
  projectRoot?: string
  /** Opt into source-derived project attribution; malformed or unresolved declarations refuse. */
  projectSources?: InitialBundleProjectSources
}

/** Existing declaration files, relative to the consumer root; never a second page catalog. */
export interface InitialBundleProjectSources {
  routes: string
  views: string
}

export interface InitialBundleOptions {
  warningBytes?: number
  errorBytes?: number
  projectSources?: InitialBundleProjectSources
}

export interface InitialBundleVerdict {
  warningBytes: number
  errorBytes: number
  warnings: string[]
  errors: string[]
}

export interface InitialBundleSection {
  totalBytes: number
  jsBytes: number
  cssBytes: number
  /** Sum of independently gzipped emitted assets; informational only. */
  gzipBytes: number
  chunks: {
    fileName: string
    bytes: number
    gzipBytes: number
    imports: string[]
    dynamicImports: string[]
    /** Client input IDs plus original sources in each compiled SDK input's static closure. */
    modules: string[]
  }[]
  /** Unique input/source attribution; SDK evidence never adds to emitted bytes. */
  modules: string[]
  forbiddenModules: { id: string; reason: string }[]
}

export interface InitialBundleReport {
  version: 1
  /** Legacy measurement callers may omit source proof; configured builds must verify it. */
  projectProof: {
    status: 'not-configured' | 'verified'
    routes?: string
    views?: string
    sources?: { id: string; sha256: string }[]
    bindings?: {
      page: string
      admin: boolean
      source: string
      line: number
      id: string
      export: string
      mode: 'eager' | 'lazy'
      binding: { source: string; line: number }
    }[]
  }
  roots: string[]
  initial: InitialBundleSection
  /** Each dynamic entry's static closure, excluding files already in initial. */
  lazy: (InitialBundleSection & { entry: string })[]
}

export type BudgetedInitialBundleReport = InitialBundleReport & {
  budget: InitialBundleVerdict
}

/** Minimal Rollup/Vite boundary; importing the tooling types needs no Vite dependency. */
export type ViteBundleOutput =
  | {
      type: 'chunk'
      fileName: string
      code: string
      imports: string[]
      dynamicImports: string[]
      modules: Record<string, unknown>
      viteMetadata?: { importedCss: Set<string> }
    }
  | {
      type: 'asset'
      fileName: string
      source: string | Uint8Array
    }

interface BuildContext {
  emitFile(asset: { type: 'asset'; fileName: string; source: string }): string
  warn(message: string): void
  error(message: string): never
}

/** Structural plugin contract, assignable to Vite's Plugin without a runtime import. */
export interface InitialBundlePlugin {
  name: string
  apply: 'build'
  enforce: 'post'
  configResolved(config: {
    root: string
    base: string
    build: { ssr?: boolean | string }
  }): void
  generateBundle: {
    order: 'post'
    handler(
      this: BuildContext,
      options: unknown,
      bundle: Record<string, ViteBundleOutput>,
    ): void
  }
  writeBundle(this: BuildContext): void
}

/** Measure a complete graph, recording forbidden/unproven modules without weakening the strict adapters. */
export function measureInitialBundle(
  graph: InitialBundleGraph,
): InitialBundleReport
export function checkInitialBundleBudget(
  report: InitialBundleReport,
  options?: InitialBundleOptions,
): InitialBundleVerdict
export function viteBundleGraph(
  bundle: Record<string, ViteBundleOutput>,
  options?: { projectRoot?: string; base?: string },
): InitialBundleGraph
export function angularBundleGraph(directory: string): InitialBundleGraph
export function initialBundleBudget(
  options?: InitialBundleOptions,
): InitialBundlePlugin
export function runInitialBundleBudget(
  argv: string[],
): BudgetedInitialBundleReport
