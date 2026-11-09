<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

use Hilos\Users\AccountMergeCommandConstants;

/**
 * ChatCommandConstants - what chat still names on the command channel.
 *
 * Names the project's test-only commands and the family of rows this project moves when two accounts are merged,
 * and deletes when one is erased (HIL-302). The row
 * family goes back as a key inside {@see AccountMergeCommandConstants::FIELD_ROWS_MOVED}, where the framework counts its
 * own identities and the project counts whatever it keeps for a person.
 */
final class ChatCommandConstants
{
    public const string SUBMISSION_TEST_OBSERVE = 'test:chat:submission-observe';
    public const string SEND_PAUSE_TEST_END = 'test:chat:end-send-pause';
    public const string BOT_TEST_ANSWER_NOW = 'test:chat:bot-answer-now';
    public const string BOT_TEST_EXTRAS_CREATE = 'test:chat:bot-extras-create';
    public const string BOT_TEST_EXTRAS_DELETE = 'test:chat:bot-extras-delete';
    public const string BOT_EXTRA_NAME_PREFIX = 'ZZ 1 extra';
    public const string BOT_EXTRA_MARKER_PATTERN = '/^[a-z0-9]{8,32}$/D';
    public const string phase = 'phase';
    public const string activeCheckRequestIds = 'activeCheckRequestIds';
    public const string userId = 'userId';
    public const string botId = 'botId';
    public const string marker = 'marker';
    public const string count = 'count';
    public const string botIds = 'botIds';
    public const string deleted = 'deleted';

    /** @var string Row family chat reports in an account merge: the messages it re-pointed */
    public const string ROWS_MOVED_MESSAGES = 'messages';

    /** @var string Row family chat reports in an account erasure: the person's messages (HIL-302) */
    public const string ROWS_ERASED_MESSAGES = 'messages';

    /** @var string Row family chat reports in an account erasure: the attachments of those messages */
    public const string ROWS_ERASED_ATTACHMENTS = 'attachments';

    /** @var string Row family chat reports in an account erasure: the events of the messages and about the person */
    public const string ROWS_ERASED_EVENTS = 'events';
}
