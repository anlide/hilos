// The corner a HilosToastHost sits in. It is view config — where the stack is
// drawn — never a property of a notice: a project picks the corner once, when
// it assembles the shell, and no push can move it (toasts.md). On a narrow
// screen the top is forced regardless of the choice, because the bottom of a
// phone belongs to the form's buttons and the keyboard.
//
// The type lives in the React SDK rather than in @hilos/core: the core
// carries no view concepts, and each view layer names its own corner.

/** Which corner a {@link HilosToastHost} draws its stack in. */
export type HilosToastCorner =
  | 'bottom-end'
  | 'bottom-start'
  | 'top-end'
  | 'top-start'
