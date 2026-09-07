// The @hilos/angular test project: adapter logic over @hilos/core plus the
// component tests of the SDK's own screens. Components mount through TestBed
// (vitest.setup.ts), so the project runs in a DOM environment — jsdom, the same
// one the React project renders in, so both halves of the SDK render alike
// (docs/agents/frontend/testing-strategy.md).
//
// Tests run against source, so a declarable reaches the runtime as plain
// TypeScript and Angular compiles it JIT. JIT reads a directive's inputs by
// reflecting its decorators, and an initializer-based input — `input()`,
// `model()`, a signal query — leaves no decorator to reflect: recognizing one
// would mean instantiating the class, which cannot happen before it is created.
// So the plugin below runs Angular's own answer to that,
// `angularJitApplicationTransform` from @angular/compiler-cli, which adds the
// `@Input()` a signal input needs to be seen. It is the same transform the
// Angular CLI applies to its own JIT unit-test builds; it brings no third-party
// compiler plugin and no new dependency, since @angular/compiler-cli is already
// this package's devDependency.
//
// Without it a mount looks like it works — the template compiles and the view
// renders — and only the input is silently missing, which surfaces as NG0303 on
// `setInput` and NG0950 inside the component.
import { angularJitApplicationTransform } from '@angular/compiler-cli'
import { dirname, resolve, sep } from 'node:path'
import { fileURLToPath } from 'node:url'
import ts from 'typescript'
import { defineConfig } from 'vitest/config'

const packageDir = dirname(fileURLToPath(import.meta.url))
const sourceDir = resolve(packageDir, 'src')

// The transform needs a type checker, so it needs a whole program rather than
// one file. Building one costs a few seconds, so it is built once and reused —
// and rebuilt when the text it holds for a file is no longer what the file says,
// which is what keeps the plugin honest under `vitest --watch`.
let program: ts.Program | null = null

/**
 * The program covering this package, current for the given file.
 *
 * @param path The absolute path of the file about to be transformed.
 * @param code The file's current text, as the bundler read it.
 * @returns A program whose snapshot of that file matches its text.
 */
function currentProgram(path: string, code: string): ts.Program {
  if (program !== null && program.getSourceFile(path)?.text === code) {
    return program
  }
  const configPath = resolve(packageDir, 'tsconfig.json')
  const config = ts.readConfigFile(configPath, ts.sys.readFile)
  const parsed = ts.parseJsonConfigFileContent(
    config.config,
    ts.sys,
    packageDir,
  )
  // The path is added explicitly: a file created since the last build is not in
  // the parsed file list, and a program without it would send the plugin
  // rebuilding on every transform that followed.
  program = ts.createProgram(
    [...new Set([...parsed.fileNames, path])],
    parsed.options,
  )

  return program
}

/**
 * Make this package's declarables legible to Angular's JIT compiler.
 *
 * Two filters narrow what gets rewritten, and both cost something:
 *
 * - only `src/`, because re-printing a file costs the line numbers its stack
 *   traces are read by, and a test's own trace is the one worth keeping. A test
 *   file declaring its own host component is therefore NOT transformed — it
 *   fails loudly on `setInput` with NG0303 rather than quietly, but it fails,
 *   and the fix is to widen this filter rather than to work around it;
 * - only files declaring a component or a directive, since nothing else can
 *   carry an input at all.
 *
 * @returns The vite plugin, registered ahead of the bundler's own transform.
 */
function angularJitPlugin() {
  return {
    name: 'hilos-angular-jit',
    enforce: 'pre' as const,
    transform(code: string, id: string) {
      const [path] = id.split('?')
      if (path === undefined || !path.endsWith('.ts')) {
        return null
      }
      if (!path.startsWith(`${sourceDir}${sep}`)) {
        return null
      }
      if (!code.includes('@Component(') && !code.includes('@Directive(')) {
        return null
      }

      const built = currentProgram(path, code)
      const source = built.getSourceFile(path)
      if (source === undefined) {
        throw new Error(
          `hilos-angular-jit: ${path} is outside the package program`,
        )
      }
      const result = ts.transform(
        source,
        [angularJitApplicationTransform(built)],
        built.getCompilerOptions(),
      )
      const printed = ts.createPrinter().printFile(result.transformed[0]!)
      result.dispose()

      return { code: printed, map: null }
    },
  }
}

export default defineConfig({
  plugins: [angularJitPlugin()],
  test: {
    name: 'angular',
    environment: 'jsdom',
    // Not optional: the setup file loads @angular/compiler, stands TestBed's
    // platform up, and resets the test module between cases. Drop it and every
    // component test fails on something that names none of the three.
    setupFiles: ['./vitest.setup.ts'],
  },
})
