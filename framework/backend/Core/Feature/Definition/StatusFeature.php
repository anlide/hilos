<?php

declare(strict_types=1);

namespace Hilos\Core\Feature\Definition;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Entity\Item\StatusIncident;
use Hilos\Database\Entity\Item\StatusIncidentUpdate;
use Hilos\Database\Entity\Item\StatusSubscriber;
use Hilos\Database\Entity\Item\StatusWork;
use Hilos\Database\Entity\Item\StatusWorkComponent;
use Hilos\Database\Entity\Item\StatusMaintenancePeriod;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\State\Collection\HilosStatusComponents as StateHilosStatusComponents;
use Hilos\Runtime\State\Item\HilosStatusComponent as StateHilosStatusComponent;
use Hilos\Runtime\View\Actions\Collection\HilosStatusComponentsActions;
use Hilos\Runtime\View\Actions\Item\HilosStatusComponentActions;
use Hilos\Runtime\View\Collection\HilosStatusComponents;
use Hilos\Runtime\State\Collection\HilosStatusMaintenance as StateHilosStatusMaintenanceCollection;
use Hilos\Runtime\State\Item\HilosStatusMaintenance as StateHilosStatusMaintenance;
use Hilos\Runtime\View\Collection\HilosStatusMaintenance;
use Hilos\Runtime\View\Actions\Collection\HilosStatusMaintenanceActions as HilosStatusMaintenanceCollectionActions;
use Hilos\Runtime\View\Actions\Item\HilosStatusMaintenanceActions;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Status\StatusSettingsCatalog;
use Hilos\Pages\Status\AbstractHilosStatusPage;
use Hilos\Pages\Status\AbstractHilosStatusComponentsPage;
use Hilos\Pages\Status\AbstractHilosStatusSubscribersPage;
use Hilos\Pages\Status\AbstractHilosStatusWorksPage;
use Hilos\Tables\Status\HilosStatusComponentsTable;
use Hilos\Tables\Status\HilosStatusSubscribersTable;
use Hilos\Tables\Status\HilosStatusWorksTable;

/** Framework Status machine and its project-bound admin pages. */
final class StatusFeature extends FeatureDefinition
{
    /** @return HilosFeature Status feature */
    public function feature(): HilosFeature
    {
        return HilosFeature::STATUS;
    }

    /** @return FeatureRequirements Owner pair, catalog fragment, incident tables and existing observation/delivery features */
    public function requirements(): FeatureRequirements
    {
        return new FeatureRequirements(
            requiredPages: [AbstractHilosStatusPage::class, AbstractHilosStatusComponentsPage::class,
                AbstractHilosStatusSubscribersPage::class, AbstractHilosStatusWorksPage::class],
            requiredTables: [HilosStatusComponentsTable::class, HilosStatusSubscribersTable::class, HilosStatusWorksTable::class],
            requiredPageTables: [
                AbstractHilosStatusComponentsPage::class => HilosStatusComponentsTable::class,
                AbstractHilosStatusSubscribersPage::class => HilosStatusSubscribersTable::class,
                AbstractHilosStatusWorksPage::class => HilosStatusWorksTable::class,
            ],
            requiredAgents: [HilosAgentType::HILOS_STATUS],
            requiredSharedAgents: [MailDeliveryChannelAgent::AGENT_TYPE],
            requiredCatalogFragments: [StatusSettingsCatalog::class],
            requires: [HilosFeature::DAEMON, HilosFeature::NOTIFICATION_DELIVERY, HilosFeature::AUTH_THROTTLE],
            requiredDbTables: [
                StatusIncident::_table, StatusIncidentUpdate::_table, StatusSubscriber::_table,
                StatusWork::_table, StatusWorkComponent::_table, StatusMaintenancePeriod::_table,
            ],
        );
    }

    /** @return bool Status owns a current-count runtime collection */
    public function mountsRuntime(): bool
    {
        return true;
    }

    /**
     * @param RtContext $context Runtime context being built
     * @throws StateCollectionNotFoundException When the representation is mounted before its state
     */
    public function mount(RtContext $context): void
    {
        $context->mountFeatureCollection(StateHilosStatusComponent::RT_COLLECTION, StateHilosStatusComponents::init());
        $context->setRepresent(
            StateHilosStatusComponent::RT_COLLECTION,
            HilosStatusComponents::class,
            HilosStatusComponentsActions::class,
            HilosStatusComponentActions::class,
        );
        $context->mountFeatureCollection(StateHilosStatusMaintenance::RT_COLLECTION, StateHilosStatusMaintenanceCollection::init());
        $context->setRepresent(
            StateHilosStatusMaintenance::RT_COLLECTION,
            HilosStatusMaintenance::class,
            HilosStatusMaintenanceCollectionActions::class,
            HilosStatusMaintenanceActions::class,
        );
    }
}
