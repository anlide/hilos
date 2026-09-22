// ESLint flat config for the Hilos frontend SDK workspace (@hilos/core + the
// @hilos/vue, @hilos/react, and @hilos/angular view adapters).
//
// Minimal baseline: the recommended presets from ESLint, typescript-eslint, and
// eslint-plugin-vue, with eslint-config-prettier last so all formatting defers to
// Prettier. Spec-specific rules (no-CSS, frontend-validation bans, ...) are added
// as the code that needs them lands.

import js from '@eslint/js'
import tseslint from 'typescript-eslint'
import pluginVue from 'eslint-plugin-vue'
import configPrettier from 'eslint-config-prettier'

export default tseslint.config(
  { ignores: ['**/dist/**', '**/dist-pack/**'] },
  js.configs.recommended,
  // As of PhpStorm 2026.1, the IDE type inspection falsely flags the next line —
  // it rejects typescript-eslint's CompatibleConfigArray for config()'s parameter,
  // which tsc, vue-tsc and eslint all accept (typescript-eslint#11519, closed wontfix).
  ...tseslint.configs.recommended,
  // eslint-plugin-vue ships some Vue-idiom rules unscoped (e.g.
  // prefer-import-from-vue, which is exactly wrong for the agnostic core — it
  // imports the standalone @vue/reactivity and must never import vue). Confine
  // those to the Vue adapter package; entries the plugin already scopes to
  // *.vue files override the default with their own pattern.
  ...pluginVue.configs['flat/recommended'].map((config) => ({
    files: ['vue/**'],
    ...config,
  })),
  {
    files: ['**/*.vue'],
    languageOptions: {
      parserOptions: { parser: tseslint.parser },
    },
    // typescript-eslint disables core no-undef for the TS it parses because the
    // type-checker already reports undefined names; the *.vue script block is
    // type-checked by vue-tsc the same way, so disable it here too — otherwise
    // DOM globals used as types (e.g. MouseEvent) are false-flagged.
    rules: { 'no-undef': 'off' },
  },
  // framework/frontend/e2e is loaded by every demo's Playwright runner, which
  // carries its own installed Playwright. A value import of @playwright/test
  // here resolves this workspace's copy instead, a second one, and the runner
  // refuses it for every demo at once. `import type` is erased before that and
  // stays allowed (docs/agents/frontend/testing-strategy.md, "The shared toolbox").
  {
    files: ['e2e/**'],
    rules: {
      '@typescript-eslint/no-restricted-imports': [
        'error',
        {
          paths: [
            {
              name: '@playwright/test',
              allowTypeImports: true,
              message:
                'Only `import type` here: each demo suite runs its own installed Playwright, a value import loads a second copy from the SDK workspace, and the runner refuses it (Requiring @playwright/test second time) for the e2e of every demo at once. Take runtime needs from the Page the helper is handed.',
            },
          ],
        },
      ],
    },
  },
  configPrettier,
)
