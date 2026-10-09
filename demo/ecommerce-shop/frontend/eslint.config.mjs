// ESLint flat config for the ecommerce-shop demo frontend — an end project consuming
// @hilos/react. Mirrors the SDK baseline (framework/frontend/eslint.config.mjs):
// the recommended presets from ESLint and typescript-eslint, with
// eslint-config-prettier last so all formatting defers to Prettier.

import js from '@eslint/js'
import tseslint from 'typescript-eslint'
import configPrettier from 'eslint-config-prettier'

export default tseslint.config(
  // src/generated/** is the license inventory snapshot a framework generator
  // writes before every build, check and dev start — it is not hand-authored code.
  { ignores: ['dist/**', 'dist-prerender/**', '.vite/**', 'src/generated/**'] },
  js.configs.recommended,
  // As of PhpStorm 2026.1, the IDE type inspection falsely flags the next line —
  // it rejects typescript-eslint's CompatibleConfigArray for config()'s parameter,
  // which tsc and eslint accept (typescript-eslint#11519, closed wontfix).
  ...tseslint.configs.recommended,
  // An edit window of the project is the core row-edit session
  // (createHilosRowEdit), not a copy of its merge; a value import of the four
  // merge functions is the old shape coming back
  // (docs/agents/frontend/conflict-resolution.md, "The row-edit session").
  // `import type` of their types stays allowed.
  {
    files: ['src/**'],
    rules: {
      '@typescript-eslint/no-restricted-imports': [
        'error',
        {
          paths: [
            {
              name: '@hilos/core',
              importNames: [
                'openRowEdit',
                'resolveRowEdit',
                'keepMineRowEdit',
                'takeTheirsRowEdit',
              ],
              allowTypeImports: true,
              message:
                'An edit window takes the core row-edit session (createHilosRowEdit or its window factory) and never assembles one from the merge helper: the rules of an edit window live in one place (docs/agents/frontend/conflict-resolution.md, "The row-edit session").',
            },
          ],
        },
      ],
    },
  },
  configPrettier,
)
