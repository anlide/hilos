import { expect, test } from '../test.js'

import { registerDateTimePickerCalendarScenarios } from '../../../../../framework/frontend/e2e/dateTimePickerCalendarScenarios.js'
import { registerDateTimePickerFieldScenarios } from '../../../../../framework/frontend/e2e/dateTimePickerFieldScenarios.js'

registerDateTimePickerFieldScenarios(test, expect, (fieldTest, cases) => {
  fieldTest(...cases.moment)
  fieldTest(...cases.gap)
  fieldTest(...cases.fold)
  fieldTest(...cases.period)
  fieldTest(...cases.factors)
})

registerDateTimePickerCalendarScenarios(test, expect, (calendarTest, cases) => {
  calendarTest(...cases.pick)
  calendarTest(...cases.keyboard)
  calendarTest(...cases.period)
  calendarTest(...cases.fold)
  calendarTest(...cases.gap)
  calendarTest(...cases.timeOnly)
})
