<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Application\Port;

use EruoFood\Commerce\Application\DTO\Invoice;
use EruoFood\Commerce\Domain\Order\Order;

/** Produces a structured {@see Invoice} document from a placed order. */
interface InvoiceGenerator
{
    public function forOrder(Order $order): Invoice;
}
