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
`add_authenticator_app` declares it (HIL-1138). `enabledByDefault` says where
the operation stands before an administrator touches the list: `true`, the
default, for one that asks until it is switched off; `false` for one that asks
only once it is switched on (HIL-1275, see *Administration*). `accountAccess`
says the operation touches the sign-in of the account, so a takeover allowed
to touch it may run it; it defaults to false (HIL-1170, see *Under a
takeover*).

The framework's own operations are the person's operations on their own
account: changing the password or the email, deleting the account, exporting
its data, connecting an authenticator app (`add_authenticator_app`) and adding
a way to sign in (`add_sign_in_method`, one operation for a password, a phone,
a device key and a provider link). After them come the administrator's
operations on another person's account: merging an account into the one on the
card (`merge_accounts`), granting and removing administrator rights
(`grant_admin`, `revoke_admin`), blocking an account (`block_account`),
scheduling another person's deletion (`delete_other_account`) and taking the
person over from their card (`impersonate`, HIL-1170) — see *Operations on
another person's account*.

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

Device trust belongs to the sign-in question and is deliberately not read here.

## Under a takeover

Inside a takeover an operation of the person's own account is closed
(`StepUpGate::VERDICT_IMPERSONATED`), unless it touches the sign-in — it
declares `accountAccess`: `change_password`, `change_email`,
`add_authenticator_app`, `add_sign_in_method` — and the administrator allowed
that with `auth.impersonation.account_access` (HIL-1170). Then it is the
ADMINISTRATOR of this browser who confirms it, by the method their own account
can prove: `StepUpGate::confirmer()` names them, `StepUpCommands` sends, checks
and records the proof on them, and nothing goes to the person whose account it
is. The operation's own address or app step proves the person, not the
administrator, so inside a takeover it suppresses nothing. Deleting the account
and accepting the terms stay closed to every takeover whatever the setting
says (`StepUpGate::isImpersonated()` at their own commands): what a person does
with their own account is never done with someone else's hands.

## A window's step lives in the session

The confirmation is not the only thing another tab continues: the step a
multi-step profile window reached is the session's too (HIL-1182). The email
change and the password change keep, per session and window, one row of
`hilosProfileFlows` — the step reached, what was proven on it (the current
address's code matched; the new address and its code sent), the account's
address the proof stands on, and the moment the code of that proof dies.

- **One writer.** The users library runs a step and spends the codes exactly
  where it did before; it reports the step on `hilos_profile_flow_step`, and the
  sessions library writes the row, sends the session's whole list on
  `hilos_profile_flows` to every tab of it, and answers the submitting tab LAST,
  so that tab moves on the same frame its neighbours move on.
- **The tab carries no code.** "The code matched" is the row; a later step
  stands on it only while it is this person's, on the right step, on the
  account's current address, and while the live code of that address dies at
  the moment the row copied. A newer code of the same address — another browser,
  a resend after the first died — dies at another moment, and the proof with it.
  The row has no clock of its own; the tick drops dead rows without a frame, and
  the handshake never sends one.
- **Discard ends the flow for the session.** `hilos_profile_flow_cancel` is the
  window's Discard, and it always answers the session with the list, even when
  nothing was left to drop: a tab can still hold a flow the tick reclaimed
  without a frame. Closing without the question, leaving the page and a reload
  leave the flow alone. A flow finished or discarded in one tab closes the open
  window of the others, and the tab that finished it shows the outcome.
- **A change of person ends it.** Signing out and a takeover starting or ending
  take the session's rows away, where its toast stack is forgotten; signing in
  moves the session onto a new token, which leaves them unreachable.

Another browser of the same person sees nothing. The account-deletion window and
"Add a way to sign in" join the same record in their own leaves (HIL-1183,
HIL-1184).

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

## Operations on another person's account

An administrator's action that takes something away from another person is a
step-up operation declared in the framework directory, and which of these
actions ask is the administrator's to decide with the list, not the code's
(decision of the owner, 27.09.2026, HIL-1275). The threat is the one the
person's own operations answer: the browser of a signed-in administrator, with
someone else at it.

- **Declared on** — when the real administrator, back at the browser, cannot
  undo it with one action. A merge cannot be undone at all. A new administrator
  acts on their own from then on, up to removing the real one's rights. A
  scheduled deletion runs to its end through the grace period, and a person
  confirms deleting their own account, so an administrator confirms deleting
  someone else's.
- **Declared off** — when one action gives it back: removing rights, blocking,
  and taking the person over (`impersonate`, HIL-1170), which "Stop" ends with
  everything returned. They stand in the list so that an administrator can
  switch them on.
- **Never an operation** — an action that gives back rather than takes: lifting
  a block, calling a deletion off, as calling off one's own deletion stands
  outside the gate (HIL-302).

A new action of this kind — the next one the card grows — is declared the same
way and takes its position by the same test.

The check stands in the library that owns the write, not in the page that
forwards it: after the library re-checks who pressed — an active administrator,
not under impersonation — `AskingAdministrator::confirmed()` asks the gate for
the action's operation, before any guard of the action itself. It confirms the
ADMINISTRATOR of this browser, by the method the administrator's own account can
prove, never the person on the card. None of these operations passes an account
with nothing to confirm with: such an administrator is refused. The operator's
console commands (`account:merge`, `admin:grant`, `admin:revoke`,
`impersonate:start`) stand outside the check: at the console there is nothing
to confirm with.

On the card the window of such an action opens on the step
(`createHilosUserCardStepUp`): the view asks the server by its window's
operation every time, and the list answers whether a step is needed.

## Administration

The security administration table lists every declared operation, labels each
row as framework- or project-owned and gives it a switch. A setting can move an
operation away from its declared position but can never invent one. Two
settings hold those departures, one per position:

- `auth.step_up.disabled` — operations switched off among those declared on;
- `auth.step_up.enabled` — operations switched on among those declared off.

An operation is protected when it is declared on and absent from the first
list, or declared off and present in the second
(`StepUpSettings::isEnabled()`). A switch writes the list of its operation's
side only (`StepUpSettings::listKeyFor()`), through the settings library:
departing from the declared position lists the operation, returning to it takes
the operation out, and the list keeps operations of its own side in directory
order. `StepUpOperationKeysRule` refuses a list that names an undeclared
operation, on both keys.

Two lists and not one, because a setting's value is its stored row when there is
one and the catalog default otherwise, and the row appears with the first switch.
A single list of switched-off operations could keep an operation declared off
only by naming it, and one that joins the directory after the first switch would
be missing from the stored list — on, whatever its declaration said. With a list
per side, an operation added later stands where it was declared, whatever was
switched before it.

Switching an operation off makes its gate pass immediately; switching it on
makes the next action require a live confirmation.
