// The @hilos/prerender test project: Node build tooling, tested with no browser
// environment — the unit tests drive a fake renderRoute and a real tmp dir.
import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: {
    name: 'prerender',
  },
})
