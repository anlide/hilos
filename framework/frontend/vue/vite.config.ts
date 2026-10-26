import vue from '@vitejs/plugin-vue'
import { fileURLToPath } from 'node:url'
import { defineConfig, type Plugin } from 'vite'
import { libInjectCss } from 'vite-plugin-lib-inject-css'

const entry = {
  index: './src/index.ts',
  base: './src/base/index.ts',
  status: './src/status/index.ts',
  'admin-views': './src/admin/hilosAdminViews.ts',
  'admin/analytics': './src/admin/analytics/index.ts',
  'admin/appearance': './src/admin/appearance/index.ts',
  'admin/backup': './src/admin/backup/index.ts',
  'admin/billing': './src/admin/billing/index.ts',
  'admin/change-log': './src/admin/changeLog/index.ts',
  'admin/communications': './src/admin/communications/index.ts',
  'admin/daemon': './src/admin/daemon/index.ts',
  'admin/dashboard': './src/admin/dashboard/index.ts',
  'admin/i18n': './src/admin/i18n/index.ts',
  'admin/legal': './src/admin/legal/index.ts',
  'admin/logs': './src/admin/logs/index.ts',
  'admin/maintenance': './src/admin/maintenance/index.ts',
  'admin/mcp-skills': './src/admin/mcpSkills/index.ts',
  'admin/moderation': './src/admin/moderation/index.ts',
  'admin/roles': './src/admin/roles/index.ts',
  'admin/security': './src/admin/security/index.ts',
  'admin/settings': './src/admin/settings/index.ts',
  'admin/sil': './src/admin/sil/index.ts',
  'admin/status': './src/admin/status/index.ts',
  'admin/users': './src/admin/users/index.ts',
}

// Retain the real input modules and edges beside the built artifact. A shared
// chunk's filename cannot prove that its composition is free of admin pages.
function moduleProvenance(): Plugin {
  const packageRoot = fileURLToPath(new URL('.', import.meta.url))
  return {
    name: 'hilos-vue-module-provenance',
    generateBundle(_options, bundle) {
      this.emitFile({
        type: 'asset',
        fileName: 'module-provenance.json',
        source: JSON.stringify(
          Object.fromEntries(
            Object.values(bundle)
              .filter((output) => output.type === 'chunk')
              .map((chunk) => [
                chunk.fileName,
                {
                  modules: Object.keys(chunk.modules).map((id) =>
                    id.startsWith(packageRoot)
                      ? id.slice(packageRoot.length)
                      : id,
                  ),
                  imports: chunk.imports,
                  dynamicImports: chunk.dynamicImports,
                  css: [...(chunk.viteMetadata?.importedCss ?? [])],
                },
              ]),
          ),
          null,
          2,
        ),
      })
    },
  }
}

// Library build for @hilos/vue: independent public boundaries share the same
// primitive modules. Declarations are emitted separately by vue-tsc. Vue and
// @hilos/core stay external so consumers resolve one instance of each.
//
// libInjectCss makes the bundle self-import its emitted stylesheet, so a
// consumer that imports the dist bundle gets Bootstrap with no extra step — the
// "the SDK ships Bootstrap" rule (styling-rules.md) holding in the built
// artifact, not only in dev where the consumer resolves the SDK to source.
export default defineConfig({
  plugins: [vue(), libInjectCss(), moduleProvenance()],
  // Self-references in lazy descriptors must build from source even on a fresh
  // clone, before any dist subpath exists. Consumers use the package exports.
  resolve: {
    alias: Object.fromEntries(
      Object.entries(entry)
        .filter(([name]) => name !== 'index')
        .map(([name, source]) => [
          `@hilos/vue/${name}`,
          fileURLToPath(new URL(source, import.meta.url)),
        ]),
    ),
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    lib: {
      entry,
      formats: ['es'],
      fileName: (_format, name) => `${name}.js`,
    },
    rollupOptions: {
      external: (id) =>
        id === 'vue' || id === '@hilos/core' || id.startsWith('@hilos/core/'),
    },
  },
})
