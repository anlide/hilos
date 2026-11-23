<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\CLI;

use Demo\EcommerceShop\CLI\Commands\VoucherFixtureCreateCommand;
use Demo\EcommerceShop\CLI\Commands\VoucherFixtureGetCommand;
use Demo\EcommerceShop\CLI\Commands\VoucherFixtureUpdateCommand;
use Demo\EcommerceShop\CLI\Commands\VoucherFixtureSetActiveCommand;
use Demo\EcommerceShop\CLI\Commands\VoucherFixtureDeleteCommand;
use Hilos\Core\CLI\CliManager;

/** Adds the shop's voucher fixture commands to the framework command registry. */
final class EcommerceShopCliManager extends CliManager
{
    /** Registers the CLI names answered by the vouchers library. */
    protected function registerProjectCommands(): void
    {
        $this->addCommand(new VoucherFixtureCreateCommand());
        $this->addCommand(new VoucherFixtureGetCommand());
        $this->addCommand(new VoucherFixtureUpdateCommand());
        $this->addCommand(new VoucherFixtureSetActiveCommand());
        $this->addCommand(new VoucherFixtureDeleteCommand());
    }
}
