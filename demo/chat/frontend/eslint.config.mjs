// ESLint flat config for the chat demo frontend — an end project consuming
// @hilos/vue. Mirrors the SDK baseline (framework/frontend/eslint.config.mjs):
// the recommended presets from ESLint, typescript-eslint, and eslint-plugin-vue,
// with eslint-config-prettier last so all formatting defers to Prettier.

import js from '@eslint/js'
import tseslint from 'typescript-eslint'
import pluginVue from 'eslint-plugin-vue'
import configPrettier from 'eslint-config-prettier'

export default tseslint.config(
  { ignores: ['dist/**', 'dist-prerender/**'] },
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
    // typescript-eslint disables core no-undef for the TS it parses (the type
    // checker reports undefined names); the *.vue script block is type-checked by
    // vue-tsc the same way, so disable it here too — otherwise DOM globals used as
    // types (e.g. MouseEvent) are false-flagged.
    rules: { 'no-undef': 'off' },
  },
  configPrettier,
)
