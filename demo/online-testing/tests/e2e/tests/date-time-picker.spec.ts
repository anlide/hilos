import { expect, test } from '../test.js'

import { registerDateTimePickerFieldScenarios } from '../../../../../framework/frontend/e2e/dateTimePickerFieldScenarios.js'

registerDateTimePickerFieldScenarios(test, expect, (fieldTest, cases) => {
  fieldTest(...cases.moment)
  fieldTest(...cases.gap)
  fieldTest(...cases.fold)
  fieldTest(...cases.period)
  fieldTest(...cases.factors)
})
