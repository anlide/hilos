// The @hilos/react test project: adapter logic over @hilos/core. Hook tests
// render through @testing-library/react, so the project runs in a DOM
// environment.
import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: {
    name: 'react',
    environment: 'jsdom',
  },
})
