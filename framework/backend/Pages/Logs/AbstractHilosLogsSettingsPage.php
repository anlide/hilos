<?php

declare(strict_types=1);

namespace Hilos\Pages\Logs;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\PageReach;
use Hilos\Database\Settings\Library\DTO\SettingWriteDoneSignalData;
use Hilos\Log\LogSettingsPresets;
use Hilos\Pages\AbstractHilosSettingPresetsPage;

/**
 * Abstract Hilos admin page: the logging modes of the Logs section (HIL-762).
 *
 * Declarations and nothing else, and that emptiness is the point of the leaf: everything the
 * screen does lives in {@see AbstractHilosSettingPresetsPage}, so the next section that wants
 * modes costs a recipe and a class of this size. A line of behavior here would have meant the
 * mechanism was never general.
 *
 * Projects register a concrete empty subclass in the page factory (wiring only).
 */
abstract class AbstractHilosLogsSettingsPage extends AbstractHilosSettingPresetsPage
{
    public const string PAGE = HilosPageConstants::HILOS_LOGS_SETTINGS;

    public const PageReach REACH = PageReach::ROUTE;

    public const string GROUP_PROVIDER = LogSettingsPresets::class;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_SETTINGS,
    ];

    /**
     * The name the settings library answers this section's applies under (HIL-946).
     *
     * The fourth declaration a section owes, and its own rather than the base class's: the map
     * of page-owned signals holds one entry per name, so the next section offering modes brings
     * a name of its own here.
     */
    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            HilosSignalConstants::HILOS_LOGS_SETTINGS_PRESET_APPLY_DONE => SettingWriteDoneSignalData::class,
        ],
    ];
}
