<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

/** Immutable public wording for one page refusal. */
final readonly class PageErrorText
{
    /**
     * @param string $title Public refusal title
     * @param string $message Public refusal description
     */
    public function __construct(
        public string $title,
        public string $message,
    ) {
    }
}
