import { provideBrowserGlobalErrorListeners } from '@angular/core'
import { bootstrapApplication } from '@angular/platform-browser'

import { DateTimePickerFixture } from './DateTimePickerFixture.js'

bootstrapApplication(DateTimePickerFixture, {
  providers: [provideBrowserGlobalErrorListeners()],
}).catch((error: unknown) => console.error(error))
