<?php

declare(strict_types=1);

namespace Hilos\Mail\Frame;

/** Effective HTML frame and the layer that supplied it. */
final readonly class ResolvedMailLetterFrame
{
    public const string SOURCE_SETTINGS = 'settings';
    public const string SOURCE_PROJECT = 'project';
    public const string SOURCE_DEFAULT = 'default';

    /**
     * @param string $html Validated frame HTML
     * @param string $source Settings, project or framework default
     */
    public function __construct(public string $html, public string $source)
    {
    }
}
