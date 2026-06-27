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
  configPrettier,
)
