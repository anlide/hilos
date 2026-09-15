// Deliberately broken sample: four mounts MODAL-FOCUS must report, in JSX —
// no declaration, an expression, an unknown value, and a self-closing mount
// with no prop. Each report sits on the opening tag.

/** Stand-in for the SDK modal: the fixture depends on nothing. */
declare function HilosModal(props: {
  initialFocus?: string
  children?: JSX.Element
}): JSX.Element

/** The four forms, one mount each. */
export function Row({ where }: { where: string }): JSX.Element {
  return (
    <>
      <HilosModal>
        <p>nothing to fill</p>
      </HilosModal>
      <HilosModal initialFocus={where}>
        <p>bound</p>
      </HilosModal>
      <HilosModal initialFocus="first">
        <p>unknown</p>
      </HilosModal>
      <HilosModal />
    </>
  )
}
