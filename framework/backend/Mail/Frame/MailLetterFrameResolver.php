<?php

declare(strict_types=1);

namespace Hilos\Mail\Frame;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\Exception\MailLetterFrameInvalidException;

/** Reads the current settings snapshot for each new HTML message, without retaining values. */
final class MailLetterFrameResolver
{
    /**
     * @return ResolvedMailLetterFrame Valid settings override, project frame or framework frame
     * @throws DatabaseException When the available settings source cannot be read
     * @throws InvalidArgumentException When a settings object has the wrong collection type
     * @throws LogicException When the settings collection is misconfigured
     * @throws SettingException When the catalog or typed reader rejects the setting
     * @throws MailLetterFrameInvalidException When the effective frame is invalid
     */
    public function resolve(): ResolvedMailLetterFrame
    {
        if (
            Hilos::$db instanceof HilosDbContext
            && Hilos::$setting !== null
            && isset(Hilos::$setting[MailLetterFrameSettings::SETTING_KEY])
            && isset(Hilos::$db->settings[MailLetterFrameSettings::SETTING_KEY])
            && Hilos::$db->settings[MailLetterFrameSettings::SETTING_KEY]->value !== null
        ) {
            return $this->resolved(
                Hilos::$setting[MailLetterFrameSettings::SETTING_KEY]->string(),
                ResolvedMailLetterFrame::SOURCE_SETTINGS,
            );
        }

        return $this->resolveDefault();
    }

    /**
     * Resolves code defaults without consulting settings or the database.
     *
     * @return ResolvedMailLetterFrame Project frame or framework frame
     * @throws MailLetterFrameInvalidException When the project frame is invalid
     */
    public function resolveDefault(): ResolvedMailLetterFrame
    {
        $descriptor = Hilos::notificationChannelRegistryClass()::get(MailDeliveryChannel::NAME);
        $projectFrame = $descriptor instanceof MailDeliveryChannel ? $descriptor->projectLetterFrame() : null;

        return $this->resolved(
            $projectFrame ?? MailLetterFrame::DEFAULT_HTML,
            $projectFrame === null ? ResolvedMailLetterFrame::SOURCE_DEFAULT : ResolvedMailLetterFrame::SOURCE_PROJECT,
        );
    }

    /**
     * @param string $html Frame selected from one layer
     * @param string $source Layer that supplied it
     * @return ResolvedMailLetterFrame Validated effective frame
     * @throws MailLetterFrameInvalidException When the selected frame is invalid
     */
    private function resolved(string $html, string $source): ResolvedMailLetterFrame
    {
        if (MailLetterFrame::validate($html) !== null) {
            throw new MailLetterFrameInvalidException();
        }

        return new ResolvedMailLetterFrame($html, $source);
    }
}
