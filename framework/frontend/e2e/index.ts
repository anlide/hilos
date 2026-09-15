// The e2e toolbox shared by the demo suites: helpers that drive the browser the
// same way whatever the demo, because what they drive is the framework's own
// surface. A demo keeps its own `tests/e2e/helpers/` for what belongs to that
// demo alone; anything that would read the same in another demo belongs here.
//
// The demos reach these by relative path, the way their Playwright configs
// already reach `framework/frontend/scripts/timeout-scale.mjs` — the runner
// mounts the whole repository, so no package boundary stands in between.
//
// ONE RULE holds this together: a file here imports from `@playwright/test` with
// `import type` and nothing else. The demo suite carries its own installed copy
// of Playwright, and loading a second one from this folder's node_modules is
// refused by the runner itself ("Requiring @playwright/test second time"). A
// type import is erased before any of that happens; everything a helper needs at
// runtime arrives through the `Page` it is handed.

export { dismissToasts } from './toasts.js'
export {
  clearCustomSetting,
  draftCustomSetting,
  openSettingEdit,
  setCustomSetting,
} from './settings.js'
