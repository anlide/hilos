import { ApplicationConfig, provideBrowserGlobalErrorListeners } from '@angular/core'

// Application-wide providers. The app is zoneless — the Angular 22 default for
// new applications — so zone.js is not a dependency. The Hilos providers
// (connection, stores) land here from rewrite step 7.
export const appConfig: ApplicationConfig = {
  providers: [provideBrowserGlobalErrorListeners()],
}
