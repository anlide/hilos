<?php

declare(strict_types=1);

namespace Hilos\Database\Pages\DTO;

use Hilos\Database\Pages\PageCatalogConstants;

/** Immutable catalog head assembled for one subscription; arrays are wire boundary shapes. */
final readonly class PageHead
{
    /**
     * @param array{page: string, label: string, lead: string, icon?: string} $pageHeadOwner Owner's catalog identity
     * @param list<array{page: string, label: string}> $pageBreadcrumb Root-to-owner breadcrumb links
     * @param list<array{page: string, label: string}> $pageTabs Eligible tabs, owner first
     */
    public function __construct(
        public array $pageHeadOwner,
        public array $pageBreadcrumb,
        public array $pageTabs,
    ) {}

    /**
     * @return array{
     *     pageHeadOwner: array{page: string, label: string, lead: string, icon?: string},
     *     pageBreadcrumb: list<array{page: string, label: string}>,
     *     pageTabs: list<array{page: string, label: string}>,
     *     pageChildren: list<never>
     * } Shared head fields inside page_response data
     */
    public function toArray(): array
    {
        return [
            PageCatalogConstants::WIRE_PAGE_HEAD_OWNER => $this->pageHeadOwner,
            PageCatalogConstants::WIRE_PAGE_BREADCRUMB => $this->pageBreadcrumb,
            PageCatalogConstants::WIRE_PAGE_TABS => $this->pageTabs,
            PageCatalogConstants::WIRE_PAGE_CHILDREN => [],
        ];
    }
}
