// ESLint flat config for the Hilos frontend SDK workspace (@hilos/core + @hilos/vue).
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
  ...pluginVue.configs['flat/recommended'],
  {
    files: ['**/*.vue'],
    languageOptions: {
      parserOptions: { parser: tseslint.parser },
    },
  },
  configPrettier,
)
