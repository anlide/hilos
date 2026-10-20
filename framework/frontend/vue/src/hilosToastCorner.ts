// The corner a HilosToastHost sits in. It is view config — where the stack is
// drawn — never a property of a notice: a project picks the corner once, when
// it assembles the shell, and no push can move it (toasts.md). The corner holds
// on a wide screen only. A narrow screen puts the stack at the bottom across the
// width regardless of the choice: every form is a modal, and a narrow modal is a
// bottom sheet, so the bottom of a phone is taken only while that sheet is open
// — and for that time the stack moves to the top.
//
// The type lives in the Vue SDK rather than in @hilos/core: the core carries no
// view concepts, and each view layer names its own corner.

/** Which corner a {@link HilosToastHost} draws its stack in. */
export type HilosToastCorner =
  'bottom-end' | 'bottom-start' | 'top-end' | 'top-start'
