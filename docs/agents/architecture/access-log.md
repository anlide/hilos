# Access Log

Read this before touching the account access log (`hilos_access_log`, the
`accessLogEntries` collection) or the address a session keeps
(`hilos_session.ip_address`). Both answer the standard privacy clauses
`standard.access_log` and `standard.session_data` (HIL-1174).

## What Is Written

A row is an account, a moment, an address and an event, written at two moments
only, both by the sessions library (`AbstractSessionsLibraryAgent`), which owns
the table whole:

- `sign_in` - a session got its person, by any road of signing in: a password,
  a code, a passkey, a provider, a registration, a second factor, an operator's
  `admin:create`, a browser signed back in once its block was lifted, an
  administrator's return from a takeover. Every road binds the session in
  `authenticateSession()` or in the return at the handshake, and both call
  `logSignIn()`. The address is the session's at that moment - the handshake of
  the tab signing in left it there.
- `new_address` - a signed-in session connected from an address it did not have
  yet (`keepConnectionAddress()`, on every handshake). A session signed in before
  addresses were kept gives one on its first handshake. A session that stays on
  one address for weeks gives one row; when it was last used is its last-seen
  moment.

Never written: a takeover (the session is the administrator's, and the marker is
set before the bind), a refused sign-in, an anonymous session, and changes to the
account - a password, an email, a second factor, a block, rights. Those are the
change history (HIL-351), not the access log.

## The Session's Address

`ip_address` is the address of the session's last connection, as the transport
gave it (behind the trusted proxy, see [auth-throttle.md](auth-throttle.md)),
rewritten on every handshake beside the device label. A handshake whose transport
gave no address changes nothing. A guest session keeps its address too; it is
not logged. The browser projection of a session row does not carry the address:
whether a screen shows it is a redraw's decision (design debt D-166).

## The Text Is The Switch

`AccessLogPolicy` reads the current privacy revision - the one declared last:

- a deviation from `standard.access_log` - no row is written, and the sweep
  removes every row already written;
- a deviation from `standard.session_data` - no session keeps an address, and the
  export hands out none.

Which way the text deviates is not read. Without a legal catalog, or without a
privacy document, both are kept, as the standard says. There is no other switch,
so the text and the behavior cannot part. A project keeping the standard log and
deviating from `standard.session_data` does not start
(`IncompleteFeatureActivationException`): the log takes its addresses from
sessions. That check is also the first read of the catalog, so a faulty catalog
fails the start, not the first handshake. The answers are kept per catalog
provider for the life of the process.

## Life, Erasure, Merge, Export

- **Twelve months**, `AccessLogPolicy::RETENTION_MONTHS`, a constant and not a
  setting. The sessions library sweeps once an hour (`hilos_access_log_expire`,
  only where `HilosFeature::AUTH` is declared), at most 500 rows a tick; a full
  batch goes on at the next tick. With the log switched off by the text the
  boundary is now, so every row goes the same way.
- **Erasure** removes the person's rows in the erasure's transaction
  ([account-deletion.md](account-deletion.md)).
- **Merge** moves the loser's rows to the survivor in the merge's transaction,
  as its device keys move.
- **Export**: the `access_log` section lists the person's rows (`at`, `address`,
  `event`) in the order they occurred, and each session carries `address`
  ([data-export.md](data-export.md)).
- **Restore with anonymization** purges the table whole.

## What Is Not Here

No screen reads the log: neither the profile nor the user card draws it. Showing
a session's address on screen is design debt D-166. Analytics keeps addresses of
its own, as described in [analytics.md](analytics.md). The change history of an
account is HIL-351.
