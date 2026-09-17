// The @hilos/vue test project. The Vue plugin compiles the single-file
// components, and the components mount into a DOM (Modal, LoadingButton,
// ConflictActions), so the project runs in a happy-dom environment; the headless
// merge logic they call is tested in @hilos/core under the default node env.
//
// Templates are compiled with their comments stripped, the way the shipped
// build compiles them. The parser option has two defaults: the development
// bundle of @vue/compiler-core keeps comment nodes, the production bundle drops
// them, and vitest left alone compiles with the development one. Kept, a
// comment standing BETWEEN two branches of a v-if / v-else-if chain is hoisted
// by the compiler into the children of the branch that follows it, which turns
// that branch from a single element block into a fragment; the first patch that
// has to REMOVE such a branch reads the fragment's anchor, which is null, and
// throws inside Vue's own removeFragment. So a component test that swapped the
// step branch of a multi-step surface a second time died in the renderer, while
// the same transition passed on every browser run (HIL-994).
//
// Without the option nothing fails to compile. What fails is a component test
// that swaps a step branch twice and then asserts on a field that is no longer
// in the DOM — a failure that never names its cause
// (docs/agents/frontend/testing-strategy.md).
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

export default defineConfig({
  plugins: [vue({ template: { compilerOptions: { comments: false } } })],
  test: {
    name: 'vue',
    environment: 'happy-dom',
  },
})
