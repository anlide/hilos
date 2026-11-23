// The injection key carrying the modal context to child components. HilosModal
// provides it as true so that components such as HilosFormError know they are
// mounted within a modal dialog. Outside a modal the value defaults to false.
import type { InjectionKey } from 'vue'

/** Provide/inject key for indicating whether a component is mounted inside {@link HilosModal}. */
export const HILOS_MODAL_CONTEXT: InjectionKey<boolean> = Symbol('HilosModal')
