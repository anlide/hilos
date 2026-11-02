// Plain flat config: each demo supplies its own ESLint and TypeScript plugin.
export const e2eImportRules = {
  files: ['**/*.{ts,tsx,mts,cts,js,mjs,cjs}'],
  ignores: ['test.ts'],
  rules: {
    '@typescript-eslint/no-restricted-imports': [
      'error',
      {
        paths: [
          {
            name: '@playwright/test',
            importNames: ['test', 'default'],
            allowTypeImports: true,
            message:
              'Import test from the local tests/e2e/test.js entry (using its relative path) so the adaptive-timeout fixture runs. Only that entry may bind the Playwright base test; expect, defineConfig, devices and type imports may still come from @playwright/test.',
          },
        ],
      },
    ],
  },
}
