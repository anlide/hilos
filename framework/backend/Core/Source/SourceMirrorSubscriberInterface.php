<?php

declare(strict_types=1);

namespace Hilos\Core\Source;

/**
 * Marks a subscriber that keeps this process's own memory in step with the store.
 *
 * Such a subscriber hears a change to a database row at the moment of the write, inside an
 * open transaction too: the memory it repairs answers reads made by the very code that is
 * still inside the transaction, and a view answering with a row the transaction has already
 * deleted would be wrong for that code whether the transaction commits or not.
 *
 * Every other subscriber is a reaction, and a reaction hears a change made under a transaction
 * only once that transaction commits - a rollback drops it unheard. A runtime change is no
 * exception: the transaction covers the runtime too (HIL-1165). A rollback tells a mirror what it
 * put back, as a fact of its own, so the memory the mirror built on the change goes back with it.
 */
interface SourceMirrorSubscriberInterface extends SourceChangeSubscriberInterface
{
}
