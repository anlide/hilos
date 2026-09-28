# Step-up Confirmation

Read this before declaring a protected operation, calling its server gate, or
changing how a fresh proof of identity is selected and remembered.

## Declaring an operation

The framework directory is `StepUpOperationDirectory`; a project extends it,
appends its operations to `parent::operations()`, and points its Hilos facade's
`STEP_UP_OPERATION_DIRECTORY` at that subclass. Each `StepUpOperation` carries a
stable key, an administration label, a phrase completing “To …”, and whether the
operation itself opens by proving the account's email or phone number.
`opensOnBlockedCard` also admits the blocked person held by the browser's
session; `passesWithNothingToConfirm` passes an account for which no proof is
available. Both default to false. `export_data` declares both (see
[data-export.md](data-export.md)). `opensWithSecondFactorProof` says the
operation's own first step asks a code from a connected authenticator app, so
an account holding one is not asked twice; it defaults to false, and
`add_authenticator_app` declares it (HIL-1138).

The framework's own operations are the person's operations on their own
account: changing the password or the email, deleting the account, exporting
its data, connecting an authenticator app (`add_authenticator_app`) and adding
a way to sign in (`add_sign_in_method`, one operation for a password, a phone,
a device key and a provider link).

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
the operation is refused before its own form begins unless it declares
`passesWithNothingToConfirm`.

If the chosen method is an address code and the operation declares that its own
first step proves that same account address, the separate confirmation step is
skipped. This prevents two consecutive codes from asking the same question.

Likewise, if the chosen method is a connected authenticator app and the
operation declares that its own first step asks that app's code, the separate
step is skipped: connecting a second app already begins with a code from the
first, and asking it twice would prove nothing more. That step of the
operation's own stays whether or not an administrator switched the operation
off.

## Scope and lifetime

A successful proof writes one `hilos_step_up` row for the tuple of session-token
hash, person, and operation. It lasts for the verification TTL (15 minutes), so
another tab in the same browser can continue the same operation while another
browser and another operation remain closed. A new sign-in rotates the session
token and therefore leaves earlier confirmations unreachable without a special
logout cleanup path.

The gate still refuses while the session impersonates another person. Device
trust belongs to the sign-in question and is deliberately not read here.

## Adding a way in

Every action that adds the signed-in person a way into their OWN account, or
connects an authenticator app to it, belongs to `add_sign_in_method` or
`add_authenticator_app` and takes the person through
`AbstractLibraryCommands::confirmedUser()` before a line of its own work
(decision of the owner, 25.09.2026, P-410; HIL-1138). The reason is a browser
left open: whoever sits down at it must not be able to give themselves a way in
that outlives the session, and every add is such a way.

- A NEW way to sign in the framework grows joins `add_sign_in_method`; it does
  not declare an operation of its own. One operation is what gives an
  administrator one switch, and one confirmation is what lets a person add
  several ways in a row within its lifetime.
- A multi-step add asks the gate at the start of every step, the return from a
  provider in link mode included: a confirmation runs out while a dialog stands
  open or while the person is away at the provider. A return in login mode
  asks nothing - there is no account yet to add to.
- An account with nothing to confirm with passes both operations: refusing it
  would leave it unable to ever gain a proof, since gaining one is the add.
- Taking a way off is never one of these operations: once every add is
  confirmed, whatever is left to remove is the owner's own; removing the second
  factor asks a code of its own, and the last way in is never removed.
- The moments of a SIGN-IN stand outside: linking a provider after a
  re-authentication on a matching address, and connecting an app the
  administrator requires on the way in, both happen where the person has just
  proved who they are.

## Administration

Every declared operation is enabled by default. The
`auth.step_up.disabled` setting can only narrow that code-declared directory;
it cannot invent an operation. The security administration table labels each
row as framework- or project-owned and writes the disabled list through the
settings library. Disabling an operation makes its gate pass immediately;
enabling it makes the next action require a live confirmation.
