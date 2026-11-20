import { defineConfig } from 'vitest/config'

export default defineConfig({
  server: {
    // The money cases table lives in demo/ecommerce-shop/tests, outside the frontend root.
    fs: { allow: ['..'] },
  },
  test: {
    name: 'ecommerce-shop',
    environment: 'node',
    include: ['test/**/*.test.ts'],
  },
})
