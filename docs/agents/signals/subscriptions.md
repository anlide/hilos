# Signal Subscriptions

Subscriptions track which WebSocket connection is on which page or group.
The daemon updates subscriptions **before** routing each signal.

## Subscription types

### Page subscription
One connection subscribes to one page at a time.
```
Client sends: PAGE_SUBSCRIBE { page, acceptKey, params }
Daemon:       Hilos::$sr->subscribeToPage($page, $data)
Agent:        onSignalPageSubscribe() called
```

Page params carry route variables (e.g. `['id' => '42']` for `/users/42`).

### Group subscription
One connection can subscribe to multiple groups simultaneously. A join is judged by
the group's own class, and it is answered — with content, with nothing, or with a
refusal — never with silence.
```
Client sends: GROUP_SUBSCRIBE { group, acceptKey, params }
Worker:       GroupSubscriptionDispatcher resolves the class, checks the address
              AbstractGroup::assertSubscribable()   -> refuse, or let through
              membership recorded here and on the master (WorkerGroupJoinDTO)
              AbstractGroup::buildGroupPayload()    -> content, or null
Server sends: GROUP_RESPONSE { group, payload? }
        or:   SUBSCRIPTION_GROUP_ERROR { group, httpCode, errorCode, message }
Agent:        onSignalGroupSubscribe() called after, as a side channel
```

The answer carries the FULL group name — the address the connection was actually let
into, which for a group the server addresses itself is not the name the client sent.
The refusal carries the name the client sent, because that is what it is waiting on.
Its `errorCode` is one of four: `group_not_served` (no registered class answers this
name), `group_forbidden` (the group's admission said no), `group_address_mismatch`
(the name was addressed the wrong way), `group_unauthenticated` (the group belongs to
whoever is behind the connection, and nobody is).

**Admission defaults to DENY.** `AbstractGroup::assertSubscribable()` throws unless a
group overrides it. A group is a fan-out channel, and one that admits by default leaks
the moment somebody registers it and forgets the method.

**A group is addressed by an entity, not by an arbitrary string**, and the kind of
entity settles who may name it (`GroupAddressSource`):

| `ADDRESS` | Who names the entity | Full name |
|---|---|---|
| `SINGLETON` | nobody, the group belongs to no entity | the declared name |
| `SESSION_USER` | the SERVER, out of the identity behind the connection | `<name>:<userId>` |
| `SESSION` | the SERVER, out of the session behind the connection | HIL-111 |
| `PARAM` | the CLIENT, and the group class judges admission | `<name>:<id>` |

A class declares its name WITHOUT a param; the param travels after a colon on the
wire, and resolution is exact name first, then the head up to the first colon. So a
name with a param sent to a group the server addresses itself is refused with
`group_address_mismatch` rather than checked against the identity: someone else's
"my" group cannot be NAMED, which is a stronger thing than "can be named, but we
check".

### Update subscription
Used when page params change without leaving the page (e.g. SPA navigation within same route).
```
PAGE_UPDATE_SUBSCRIPTION / GROUP_UPDATE_SUBSCRIPTION
```

### Unsubscribe
Sent on page leave or WS close.
```
PAGE_UNSUBSCRIBE / GROUP_UNSUBSCRIBE
```

## One subscription answers everything the page renders

One page subscription answers in **one** `page_response` frame (PHP
`SignalTypeConstants::PAGE_RESPONSE`, TypeScript `SIGNAL_TYPE_PAGE_RESPONSE`)
with everything that page needs for its first render. A client does not make a
second request in order to render the page it just subscribed to: the reply to
`PAGE_SUBSCRIBE` is the whole answer, and until it lands the view shows a
placeholder — see [core-and-connection.md](../frontend/core-and-connection.md),
"A page shows a placeholder until its subscription answers".

The rule is absolute rather than a preference because the cost of breaking it is
not one extra request. A page that needs `N` answers has `2^N` intermediate
states: someone draws a placeholder for each, someone writes a test for each, and
a cold load pays `N` round trips instead of one. The neighboring hleb codebase
answers a page in one reply and its pages carry no assembly state at all — there
is nothing to assemble, because the answer arrives whole.

### The three forbidden shapes

Each is named, because each gets invented by an author who is not thinking about
the protocol at all — only that this page also needs one more thing:

- **A second client-to-server action sent to obtain data for the first render.**
  An action fired on mount because the payload lacked something is a violation;
  the data belongs in the payload.
- **A companion server signal the client waits for in addition to
  `page_response`.** If a view cannot render until a second frame arrives, that
  second frame's content belongs in the first.
- **A page-independent request/response used to render the page** — a catalog, a
  reference list, a lookup asked for on its own. This is the shape HIL-624's
  interview proposed for admin page identity, and rejecting it is why this rule
  is written down.

### Where the data goes instead

Into the page's own answer, assembled on the backend:

- `AbstractPage::buildPagePayload()` — the page builds its payload and reads
  whatever foreign source it needs while building it. It receives the accept key
  of the subscriber, so a payload may be personal: the `page_response` frame it
  rides is addressed to the one connection that asked;
- `AbstractPage::onSubscribeBeforeResponse()` — when the assembly is a side
  effect rather than payload, or has to refuse the subscription outright.

`AbstractPage::withPageIdentity()` is the worked example: the label, lead, and
breadcrumb that a project's admin page catalog owns are folded into the payload
the page built, so the client receives them in the same `page_response` instead
of asking the catalog for them. The client side needs nothing new for any of
this — `bindPageScope` ingests the one frame that arrives.

### A page declares whether anybody navigates to it

A page answers with `AbstractPage::REACH`, a `PageReach` constant, and the answer is
one of two: `ROUTE` when the browser navigates here, `ACTION_HOST` when nobody does
and the page only hosts actions that arrive while the person is on another page. The
constant is inherited, so a base answers for its whole branch and a thin subclass
writes nothing; `UNDECLARED` belongs to `AbstractPage` alone, because an answer on a
common root would declare every page in the repository at once.

The answer matters because `READS_DB` is taken up on a page subscription and let go on
unsubscribe (`WorkerManager::takeUpPageSources()`, reached from the `page_subscribe`
route). A page nobody subscribes to is never the subject of a take-up, so a list it
writes there sits unread and its reads are refused at the moment the person presses the
button. Those reads belong in `DbContext::processWideReadCollections()` instead, which
holds interest for the life of the process — the framework's own entries are in
`HilosDbContext`, and a project overrides the method and calls the parent.

Nothing reads the declaration at runtime. It is judged by the `PAGE-REACH` guard, which
reports a page nothing in its chain answers for, an `ACTION_HOST` that still fills
`READS_DB`, and a common root that answers at all. Exactly one `ACTION_HOST` exists:
`AbstractHilosNotificationsPage`, which is a page by mistake — the bell lives in the
application shell and its live channel is the group above — and HIL-860 retires it.

Checked automatically: `PAGE-REACH`, see
[automated-checks.md](../code-style/automated-checks.md).

### Exceptions, and the test that finds them

Ask one question about the data in dispute: **would the page render complete
without it?** If yes, the rule does not apply. Three standing cases answer yes:

- the contents of a modal the user opens later;
- the window of a table the user has scrolled to;
- a file the user downloads.

The **first** window of a table is not one of them: a page is not complete
without the first screen of rows of the table it shows.

### Known deviation: the first table window

Today a viewport table does not flow through the `page_response` fan-out (see
[table-subscription.md](../frontend/table-subscription.md)). The controller asks
for its first window itself when the table mounts, and the server sends the
`table_window` snapshot only in reply to a `table_viewport` request — a second
round trip for a first render, which the rule above says it should not be. This
is recorded as a known deviation with an owner, HIL-642, not as an exception: a
rule that stays silent about a place where the code disagrees with it reads as a
mistake in the rule.

### The one legal neighbor: a group subscription

A group subscription may answer alongside a page subscription, because it does
not belong to the page: it is subscribed independently and survives navigation,
and it carries data shared across pages — editable catalogs, the current
language (see [data-model.md](../frontend/data-model.md), "The four scopes").
The notification center is the live one: `bindNotificationsScope` joins the
per-user group `hilos_notifications:<userId>`, and the bell it feeds sits in the
application shell, not on any page. Moving data only one page renders into a
group does not make it legal. The session handshake is outside the rule for the
same kind of reason: it happens before any subscription exists.

**The same rule holds inside a group** (HIL-721). One `GROUP_SUBSCRIBE` answers with
everything its components need, in one `group_response`: the join IS the group's first
render, so a follow-up action asking for content is the first forbidden shape one scope
over. The notification center is the worked example — it used to join and then send a
`notification_sync` action for its snapshot, and now the join carries it.

What a group's content is, is built in `AbstractGroup::buildGroupPayload()`, the twin
of `AbstractPage::buildPagePayload()`. A group that legitimately carries none returns
null and the frame goes out empty; that is an answer, not a violation.

Being addressed by an entity is what makes the rule affordable here. For a group of
"my" entity the server builds the name out of the identity behind the connection, so
the content can be read at the join without the client having said whose it is.

## Routing by subscription

From daemon's `dispatchSignals()`, `getDestinations()` resolves:
- PAGE signal → find agent declared by page `SUBSCRIPTION_AGENT_TYPE` through
  the project topology registry
- GROUP_SUBSCRIBE / GROUP_UNSUBSCRIBE / GROUP_UPDATE_SUBSCRIPTION → find agent
  declared by group `SUBSCRIPTION_AGENT_TYPE` through `Hilos::getGroupRoutes()`,
  matching the name on the wire exactly and then by its head, so a name that carries
  a param still reaches the owner that has to refuse it. A join that resolves to no
  owner is refused by the master itself with `group_not_served` rather than dropped.

## Sending to subscribers

From an agent:
```php
// To one connection
$this->sendToUser($signalName, $acceptKey, $data);

// To every tab of one session
$this->sendToSession($signalName, $sessionTokenHash, $data);

// To all (broadcast)
$this->sendToAllUsers($signalName, $data, excludeKey: $acceptKey);

// To all in group
$this->sendToGroup($signalName, $groupName, $data);
```

What the frontend shows as a toast is addressed to a connection
(`sendToUser()` — one socket, despite the name) or to a session
(`sendToSession()` — every tab of one browser). Anything addressed to the user
as a person goes out as a durable notification-center record, not a toast —
the surface rule is [../frontend/toasts.md](../frontend/toasts.md).

## Per-page agent override

At project level, declare per-page subscription ownership in
each page class `SUBSCRIPTION_AGENT_TYPE`; see [app-topology.md](../app-topology.md).
`SignalRouter` reads the computed `Hilos::getPageRoutes()` registry through the
project facade hook, so different pages route subscription signals to different
agents without project router config:
```php
final class BotPage extends AbstractPage
{
    public const string PAGE = PageConstants::BOT;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::BOT;
}
```

Use `SignalRouter::getDefaultPageSubscriptionAgentType()` only as a project
fallback for subscriptions to unregistered pages.
