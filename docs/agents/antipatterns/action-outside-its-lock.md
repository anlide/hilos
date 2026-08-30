# Anti-pattern: An Action Moved Past Its Lock

The client sends the action name itself. Whoever holds that name is what closes
it — so the moment an admin page's action name moves to the agent that owns the
row, the page and the `ADMIN` level it carries are no longer part of the check,
and any signed-in user reaches the write.

## What breaks

Nothing that shows. The admin who always ran the action still can, the tests
that exercised it stay green, and the log shows the write going through. What
changed is who else can send the frame: an agent action is closed by
`AbstractAgent::AUTH_ACTIONS` and by nothing else — there is no `ADMIN` level
for an agent action, and none is being added — so the check the page made,
`isAdmin()` or a right over one object, is simply gone.

```
before                                    after
browser ─retry─> AdminPage (ADMIN)        browser ─retry─> OwnerAgent (AUTH_ACTIONS)
                 checks the right,                         checks "signed in",
                 writes the row                            writes the row
```

The write itself may have had every reason to move: the row belongs to the
owner, and a page writing it was a write with no claim behind it. What must not
move is the name. The rule, the criterion and the shape of the split are in
[../architecture/entity-libraries.md](../architecture/entity-libraries.md#the-lock-does-not-travel-with-the-name-hil-771);
this sheet is what the mistake looks like.

## Wrong

An admin action name sitting in the owner agent's `AGENT_ACTIONS`, closed only
by `AUTH_ACTIONS`:

```php
// Wrong: the retry's name moved onto the journal's owner along with the write.
// The page carried ADMIN; the agent carries "signed in".
abstract class AbstractNotificationsLibraryAgent extends AbstractAgent
{
    public const array AGENT_ACTIONS = [
        HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY => HilosDeliveryRetryActionDTO::class,
    ];

    public const array AUTH_ACTIONS = [
        HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY,
    ];
}
```

## Right

The name stays on the page, which checks the right and forwards the write; the
owner writes and reports back; the page answers the client after that. The
skeleton is below — the full text is
`framework/backend/Pages/Communications/AbstractHilosCommunicationsDeliveriesPage.php`,
`handleRetry()` at `:147` and `answerRetry()` at `:172`, and it is the copy
that stays alive while one pasted here would rot:

```php
// Right: the gatekeeper. ADMIN stays on this page; the write leaves as a
// signal, and the ack is deferred until the owner has spoken.
private function handleRetry(string $acceptKey, HilosDeliveryRetryActionDTO $dto): void
{
    $requestId = $this->currentActionRequestId();
    $this->agent->sendToAgent(
        HilosSignalConstants::HILOS_DELIVERY_RETRY,
        new DeliveryRetrySignalData($dto->deliveryId, $acceptKey, $requestId),
    );
    if ($requestId !== null) {
        $this->deferActionReply();
    }
}

// Right: the answer, on the owner's done signal — one branch per way the
// client asked, and the refusal carries the owner's reason as text.
private function answerRetry(DeliveryRetryDoneSignalData $done): void
{
    // tracked, accepted:  sendActionSuccess($done->acceptKey, ACTION, $done->requestId)
    // tracked, refused:   sendActionFail($done->acceptKey, ACTION, $done->requestId, $done->error)
    // untracked, refused: sendToUser(SignalConstants::ACTION_ERROR, $done->acceptKey,
    //                         new PageActionErrorSignalData(ACTION, $done->error))
}
```

`DeliveryRetryDoneSignalData::$error` is a string: without it the gatekeeper
would have nothing to tell the person.

## How to spot it

- An agent action that writes what an `ADMIN` page used to write.
- `sendActionSuccess()` in the same call that sent the signal to the owner —
  an answer before the confirmation, while the owner may still refuse.
- The owner's refusal thrown as an exception outside a page. Only a page
  handler has `PageSignalRouter::dispatchAgentSignalActionException()` behind
  it to turn a throw into an action-error frame; a throw from an agent's
  `onSignalAgent()` is a failed handler in the worker log, and the client hears
  nothing.

## Related

- [../architecture/entity-libraries.md](../architecture/entity-libraries.md#the-lock-does-not-travel-with-the-name-hil-771) —
  the rule: the criterion table, the gatekeeper/writer form, and the worked
  example.
- [../architecture/page-access-control.md](../architecture/page-access-control.md) —
  the page level that closes the actions a page holds.
