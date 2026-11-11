// ESLint flat config for the Roblox Game Server demo's Playwright package.
// Mirrors the SDK baseline (framework/frontend/eslint.config.mjs): the
// recommended presets from ESLint and typescript-eslint, with
// eslint-config-prettier last so formatting defers to Prettier. Runtime test
// bindings go through the local test.js entry so every spec and helper inherits
// the shared adaptive-timeout fixture.

import js from '@eslint/js'
import tseslint from 'typescript-eslint'
import configPrettier from 'eslint-config-prettier'

import { e2eImportRules } from '../../../../framework/frontend/scripts/e2e-import-rules.mjs'

export default tseslint.config(
  // test-results and playwright-report are written by the html and list
  // reporters; they are not hand-authored code.
  { ignores: ['test-results/**', 'playwright-report/**'] },
  js.configs.recommended,
  // As of PhpStorm 2026.1, the IDE type inspection falsely flags the next line —
  // it rejects typescript-eslint's CompatibleConfigArray for config()'s parameter,
  // which tsc and eslint accept (typescript-eslint#11519, closed wontfix).
  ...tseslint.configs.recommended,
  e2eImportRules,
  configPrettier,
)
