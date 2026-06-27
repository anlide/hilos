// The @hilos/vue test project. The Vue plugin compiles the single-file
// components, and the components mount into a DOM (Modal, LoadingButton,
// ConflictActions), so the project runs in a happy-dom environment; the headless
// merge logic they call is tested in @hilos/core under the default node env.
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

export default defineConfig({
  plugins: [vue()],
  test: {
    name: 'vue',
    environment: 'happy-dom',
  },
})
