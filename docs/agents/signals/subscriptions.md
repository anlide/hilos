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
One connection can subscribe to multiple groups simultaneously.
```
Client sends: GROUP_SUBSCRIBE { group, acceptKey }
Daemon:       Hilos::$sr->subscribeToGroup($group, $data)
Agent:        onSignalGroupSubscribe() called
```

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
  whatever foreign source it needs while building it;
- `AbstractPage::onSubscribeBeforeResponse()` — when the assembly needs the
  accept key or the subscription itself.

`AbstractPage::withPageIdentity()` is the worked example: the label, lead, and
breadcrumb that a project's admin page catalog owns are folded into the payload
the page built, so the client receives them in the same `page_response` instead
of asking the catalog for them. The client side needs nothing new for any of
this — `bindPageScope` ingests the one frame that arrives.

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

**Open question, not a rule.** Whether the same completeness requirement holds
*inside* a group — one `GROUP_SUBSCRIBE` answering with everything its
components need, and what "first render" even means for a channel with no
surface of its own — is undecided, and the live group already answers in two
steps: the join is followed by a separate `notification_sync` action asking for
the snapshot. Whether that is a group's legitimate shape or the same defect one
scope over is for the leaf that next builds on a group to settle. This
paragraph holds the place until then, and its silence is not permission.

## Routing by subscription

From daemon's `dispatchSignals()`, `getDestinations()` resolves:
- PAGE signal → find agent declared by page `SUBSCRIPTION_AGENT_TYPE` through
  the project topology registry
- GROUP_SUBSCRIBE / GROUP_UNSUBSCRIBE / GROUP_UPDATE_SUBSCRIPTION → find agent
  declared by group `SUBSCRIPTION_AGENT_TYPE` through `Hilos::getGroupRoutes()`

## Sending to subscribers

From an agent:
```php
// To one connection
$this->sendToUser($signalName, $acceptKey, $data);

// To all (broadcast)
$this->sendToAllUsers($signalName, $data, excludeKey: $acceptKey);

// To all in group
$this->sendToGroup($signalName, $groupName, $data);
```

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
