// The @hilos/vue test project. The Vue plugin compiles the single-file
// components the public surface now re-exports; a component test gains a DOM
// environment once one needs to mount, but the surface test only imports.
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

export default defineConfig({
  plugins: [vue()],
  test: {
    name: 'vue',
  },
})
