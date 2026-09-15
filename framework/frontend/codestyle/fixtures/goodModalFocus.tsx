// The look-alikes in JSX: a mark in mutually exclusive branches, a dialog with
// nothing to fill, a body drawn by another component, and a nested mount that
// names its own landing while only the outer one is marked. MODAL-FOCUS must
// stay silent on every one of them.

/** Stand-in for the SDK modal: the fixture depends on nothing. */
declare function HilosModal(props: {
  initialFocus?: string
  children?: JSX.Element | JSX.Element[]
}): JSX.Element

/** The four forms, one mount each. */
export function Row({
  step,
  body,
}: {
  step: number
  body: () => JSX.Element
}): JSX.Element {
  return (
    <>
      <HilosModal>
        {step === 1 ? <input data-autofocus /> : <input data-autofocus />}
      </HilosModal>
      <HilosModal initialFocus="dialog">
        <p>nothing to fill</p>
      </HilosModal>
      <HilosModal initialFocus="inner">{body()}</HilosModal>
      <HilosModal>
        <input data-autofocus />
        <HilosModal initialFocus="dialog">
          <p>inner layer</p>
        </HilosModal>
      </HilosModal>
    </>
  )
}
