// The injection token carrying whether a component is mounted inside HilosModal.
// HilosModal provides it for its projected children and custom outlets;
// HilosFormError injects it to style its empty refusal room.
import { InjectionToken } from '@angular/core'

/** DI token indicating whether a component is mounted inside {@link HilosModal}. */
export const HILOS_MODAL_TOKEN = new InjectionToken<boolean>(
  'HILOS_MODAL_TOKEN',
)
