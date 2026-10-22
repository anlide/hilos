import { provideBrowserGlobalErrorListeners } from '@angular/core'
import { bootstrapApplication } from '@angular/platform-browser'

import { TableGeometryFixture } from './TableGeometryFixture.js'

bootstrapApplication(TableGeometryFixture, {
  providers: [provideBrowserGlobalErrorListeners()],
}).catch((error: unknown) => console.error(error))
