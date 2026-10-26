<?php

declare(strict_types=1);

namespace Hilos\Pages\Communications;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\Exception\MailLetterFrameInvalidException;
use Hilos\Mail\Exception\MailTemplateNotInCatalogException;
use Hilos\Mail\Exception\MailTemplateParamMissingException;
use Hilos\Mail\Frame\MailLetterFrame;
use Hilos\Mail\Frame\MailLetterFrameResolver;
use Hilos\Mail\Template\AbstractVerificationCodeMailTemplate;
use Hilos\Mail\Template\MailTemplateCatalogConstants;
use Hilos\Mail\Template\MailTemplateRegistry;
use Hilos\Pages\Communications\DTO\MailLetterFrameData;

/** Reads the effective frame and builds a synthetic preview without retaining either. */
class CommunicationsLetterFrameReader
{
    private const string PREVIEW_LANGUAGE = 'en';

    /**
     * @return MailLetterFrameData Current frame and framed framework password-reset sample
     * @throws DatabaseException When the available settings source cannot be read
     * @throws InvalidArgumentException When a settings object has the wrong collection type
     * @throws LogicException When the settings collection is misconfigured
     * @throws SettingException When the catalog or typed reader rejects the setting
     * @throws MailLetterFrameInvalidException When the effective frame is invalid
     * @throws MailTemplateNotInCatalogException When the framework sample is not cataloged
     * @throws MailTemplateParamMissingException When the sample refuses its parameters
     * @throws InvalidFormatException When the resolved frame is outside the data contract
     */
    public function read(): MailLetterFrameData
    {
        $frame = new MailLetterFrameResolver()->resolve();
        $sample = $this->createTemplateRegistry()->render(
            MailTemplateCatalogConstants::AUTH_PASSWORD_RESET,
            [AbstractVerificationCodeMailTemplate::PARAM_CODE => '482913'],
            self::PREVIEW_LANGUAGE,
        );

        return new MailLetterFrameData(
            channel: MailDeliveryChannel::NAME,
            html: $frame->html,
            source: $frame->source,
            previewHtml: MailLetterFrame::render(
                $frame->html,
                $sample->html ?? nl2br(htmlspecialchars($sample->text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
                self::PREVIEW_LANGUAGE,
            ),
        );
    }

    /** @return MailTemplateRegistry Framework sample catalog */
    protected function createTemplateRegistry(): MailTemplateRegistry
    {
        return new MailTemplateRegistry();
    }
}
