// ESLint flat config for the binance-btc-tracker demo's Playwright package. Mirrors the SDK
// baseline (framework/frontend/eslint.config.mjs): the recommended presets from
// ESLint and typescript-eslint, with eslint-config-prettier last so formatting
// defers to Prettier. The framework e2e block is not copied: it forbids a value
// import of @playwright/test in the shared SDK toolbox, and a demo spec's import
// of that package is normal.

import js from '@eslint/js'
import tseslint from 'typescript-eslint'
import configPrettier from 'eslint-config-prettier'

export default tseslint.config(
  // test-results and playwright-report are written by the html and list
  // reporters; they are not hand-authored code.
  { ignores: ['test-results/**', 'playwright-report/**'] },
  js.configs.recommended,
  // As of PhpStorm 2026.1, the IDE type inspection falsely flags the next line —
  // it rejects typescript-eslint's CompatibleConfigArray for config()'s parameter,
  // which tsc and eslint accept (typescript-eslint#11519, closed wontfix).
  ...tseslint.configs.recommended,
  configPrettier,
)
