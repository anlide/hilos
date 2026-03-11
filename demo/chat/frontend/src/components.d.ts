/**
 * Type declarations for globally registered components (vite-ssg, etc.).
 * PhpStorm/IDE uses these to resolve unknown template tags.
 */
import type { DefineComponent } from 'vue'

declare module 'vue' {
  export interface GlobalComponents {
    ClientOnly: DefineComponent<{ placeholder?: string }, Record<string, unknown>, unknown>
  }
}
