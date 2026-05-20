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
