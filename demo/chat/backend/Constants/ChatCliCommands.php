<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

/**
 * ChatCliCommands - chat-specific CLI command names.
 *
 * Currently only the test-only state helpers used to demonstrate the TestOnlyCommand
 * mechanism. Names are namespaced under `test:` so they read as test-only at a glance.
 */
final class ChatCliCommands
{
    /** @var string Test-only: create the example orphan settings row */
    public const string CREATE_ORPHAN_SETTING = 'test:orphan-setting:create';

    /** @var string Test-only: delete the example orphan settings row */
    public const string DELETE_ORPHAN_SETTING = 'test:orphan-setting:delete';
}
