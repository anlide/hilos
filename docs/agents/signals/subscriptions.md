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
- PAGE signal → find agent registered for that page in `config['pages']`
- GROUP signal → find agent registered for that group in `config['groups']`

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

`page_subscription_routing` config lets different pages route subscription signals to different agents:
```php
'page_subscription_routing' => [
    'default' => AgentType::CHAT,
    'pages' => [
        PageConstants::BOT      => AgentType::BOT,
        PageConstants::MODERATOR => AgentType::MODERATOR,
    ],
],
```
