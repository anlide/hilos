# Step-up Confirmation

Read this before declaring a protected operation, calling its server gate, or
changing how a fresh proof of identity is selected and remembered.

## Declaring an operation

The framework directory is `StepUpOperationDirectory`; a project extends it,
appends its operations to `parent::operations()`, and points its Hilos facade's
`STEP_UP_OPERATION_DIRECTORY` at that subclass. Each `StepUpOperation` carries a
stable key, an administration label, a phrase completing “To …”, and whether the
operation itself opens by proving the account's email or phone number.

Declaring an operation does not protect it by itself. Every server action that
belongs to the operation calls `requireStepUp($acceptKey, $operation)` before it
does any operation work. A multi-step operation repeats the gate at the start of
every step: confirmation can expire or be disabled while a dialog is open.

## Choosing the proof

The application chooses one method from what the account can prove, not from how
the current session signed in and not from a menu offered to the person:

1. a connected second-factor app or backup code;
2. the account password;
3. a code to a verified email address, when mail delivery is available;
4. a code to a verified phone number, when SMS delivery is available;
5. a registered device key.

A provider login is not a step-up method. When none of the methods is available,
the operation is refused before its own form begins.

If the chosen method is an address code and the operation declares that its own
first step proves that same account address, the separate confirmation step is
skipped. This prevents two consecutive codes from asking the same question.

## Scope and lifetime

A successful proof writes one `hilos_step_up` row for the tuple of session-token
hash, person, and operation. It lasts for the verification TTL (15 minutes), so
another tab in the same browser can continue the same operation while another
browser and another operation remain closed. A new sign-in rotates the session
token and therefore leaves earlier confirmations unreachable without a special
logout cleanup path.

The gate still refuses while the session impersonates another person. Device
trust belongs to the sign-in question and is deliberately not read here.

## Administration

Every declared operation is enabled by default. The
`auth.step_up.disabled` setting can only narrow that code-declared directory;
it cannot invent an operation. The security administration table labels each
row as framework- or project-owned and writes the disabled list through the
settings library. Disabling an operation makes its gate pass immediately;
enabling it makes the next action require a live confirmation.
