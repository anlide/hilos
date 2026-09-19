<?php

declare(strict_types=1);

namespace Demo\Cluster\Constants;

/**
 * ClusterResource - Consumable resource keys the cluster demo's nodes declare (HIL-448).
 *
 * A key=value token in CLUSTER_NODE_CAPABILITIES is a stock of the named resource, and the
 * leader subtracts the cost of every agent it places on the node from it. The stand's slaves
 * declare different amounts of {@see RAM}, so a scenario can show work landing in proportion to
 * what each node declares; its masters declare none and take no placed work at all.
 */
final class ClusterResource
{
    /** @var string Memory stock a slave of the stand declares, and the one resource the ballast costs */
    public const string RAM = 'ram';
}
