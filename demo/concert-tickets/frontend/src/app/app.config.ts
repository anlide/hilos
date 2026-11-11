import {
  ApplicationConfig,
  provideBrowserGlobalErrorListeners,
} from '@angular/core'

// Application-wide providers. The app is zoneless — the Angular 22 default for
// new applications — so zone.js is not a dependency. The Hilos router and auth
// providers are supplied by bootstrap/main.ts.
export const appConfig: ApplicationConfig = {
  providers: [provideBrowserGlobalErrorListeners()],
}
