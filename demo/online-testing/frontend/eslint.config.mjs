// ESLint flat config for the online-testing demo frontend — an end project consuming
// @hilos/angular. Mirrors the SDK baseline (framework/frontend/eslint.config.mjs):
// the recommended presets from ESLint and typescript-eslint, with
// eslint-config-prettier last so all formatting defers to Prettier.

import js from '@eslint/js'
import tseslint from 'typescript-eslint'
import configPrettier from 'eslint-config-prettier'

export default tseslint.config(
  // src/generated/** is the license inventory snapshot a framework generator
  // writes before every build, check and dev start. out-tsc/** and .angular/**
  // are compiler and CLI caches; the test stand mounts its own volume on .angular/.
  { ignores: ['dist/**', 'out-tsc/**', '.angular/**', 'src/generated/**'] },
  js.configs.recommended,
  // As of PhpStorm 2026.1, the IDE type inspection falsely flags the next line —
  // it rejects typescript-eslint's CompatibleConfigArray for config()'s parameter,
  // which tsc and eslint accept (typescript-eslint#11519, closed wontfix).
  ...tseslint.configs.recommended,
  configPrettier,
)
