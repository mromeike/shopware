<?php

declare(strict_types=1);

namespace Shopware\Core\Checkout\Order\SalesChannel;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * This route can be used to load all payment methods of the authenticated sales-channel for a given order.
 * The request works for customers as well as guest customers (context).
 */
#[Package('checkout')]
abstract class AbstractPaymentMethodOrderRoute
{
    abstract public function getDecorated(): self;

    abstract public function load(Request $request, SalesChannelContext $context): PaymentMethodOrderRouteResponse;
}
