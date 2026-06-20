<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Browser;

use Hilos\Core\Browser\Context\BrowserContext;

/**
 * TodoBrowserContext - Browser-facing context ($browser layer) for simple-todo.
 *
 * The demo declares no computed browser fields of its own; it exists so the
 * project ships a browser context at all (the framework default is null). The
 * base context delivers the framework settings table's snapshot and reactive
 * updates through the self-snapshot path, so the settings admin feature needs no
 * project browser code — an empty subclass is the whole activation.
 */
final class TodoBrowserContext extends BrowserContext
{
}
