// The @hilos/vue test project. Component tests get a DOM environment once the
// first components land; until then the default Node environment suffices.
import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: {
    name: 'vue',
  },
})
