import { expect, test } from '../test.js'

import { registerGuestThemeScenarios } from '../../../../../framework/frontend/e2e/themeGuestScenarios.js'

registerGuestThemeScenarios(test, expect, (themeTest, cases) => {
  themeTest(...cases.sync)
  themeTest(...cases.reset)
  themeTest(...cases.system)
  themeTest(...cases.menu)
})
