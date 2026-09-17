<?php

declare(strict_types=1);

namespace Hilos\StandGateway;

/**
 * GatewayResident - one channel living in the stand gateway.
 *
 * A resident registers its routes on the routes of every connection the gateway accepts,
 * not once on a shared router: a behavior a spec declared is played out on the connection
 * that carries the call, so each connection routes through its own {@see GatewayRoutes}.
 */
interface GatewayResident
{
    /**
     * Registers the resident's provider and test routes on one connection.
     *
     * @param GatewayRoutes $routes Routes of the connection being accepted
     */
    public function register(GatewayRoutes $routes): void;
}
