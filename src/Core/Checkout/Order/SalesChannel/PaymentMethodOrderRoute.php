<?php

declare(strict_types=1);

namespace Shopware\Core\Checkout\Order\SalesChannel;

use Shopware\Core\Checkout\Cart\Order\OrderConverter;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Exception\PaymentMethodNotChangeableException;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractPaymentMethodRoute;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\EntityNotFoundException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Uuid\UuidException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['store-api']])]
#[Package('checkout')]
class PaymentMethodOrderRoute extends AbstractPaymentMethodOrderRoute
{
    /**
     * @param EntityRepository<OrderCollection> $orderRepository
     */
    public function __construct(
        private readonly OrderService $orderService,
        private readonly EntityRepository $orderRepository,
        private readonly AbstractPaymentMethodRoute $paymentMethodRoute,
        private readonly OrderConverter $orderConverter,
    ) {
    }

    public function getDecorated(): AbstractPaymentMethodOrderRoute
    {
        throw new DecorationPatternException(self::class);
    }

    /**
     * @see SetPaymentOrderRoute::setPayment()
     */
    #[Route(
        path: '/store-api/order/payment-method',
        name: 'store-api.order.payment-method',
        defaults: [
            '_entity'                  => 'payment_method',
            '_loginRequired'           => true,
            '_loginRequiredAllowGuest' => true,
        ],
        methods: ['GET', 'POST'],
    )]
    public function load(Request $request, SalesChannelContext $context): PaymentMethodOrderRouteResponse
    {
        $orderId = $request->get('orderId');

        if (!\is_string($orderId)) {
            throw new BadRequestException('Parameter "orderId" missing.');
        }
        if (!Uuid::isValid($orderId)) {
            throw UuidException::invalidUuid($orderId);
        }

        // Load the order by the given ID.
        $order   = $this->loadOrder($orderId, $context);

        // Check for the order transaction state here, to not expose paid or cancelled orders.
        $this->validatePaymentState($order);

        // Assemble a new sales-channel context from that order and our context.
        // The new context will contain our order's rule-ids only, so only the payment methods with matching rules are shown.
        $context = $this->orderConverter->assembleSalesChannelContext($order, $context->getContext());

        // Load the available payment methods for the sales-channel context.
        return new PaymentMethodOrderRouteResponse(
            $this->loadAvailablePaymentMethods($context)
        );
    }

    /**
     * Load the order by ID, including all required associations for processing.
     *
     * @see SetPaymentOrderRoute::loadOrder()
     *
     * @throws EntityNotFoundException when the order is not found
     */
    private function loadOrder(string $orderId, SalesChannelContext $context): OrderEntity
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('transactions.paymentMethod');
        $criteria->getAssociation('transactions')
            ->addSorting(new FieldSorting('createdAt'));

        /** @var CustomerEntity $customer */
        $customer = $context->getCustomer();

        $criteria->addFilter(
            new EqualsFilter(
                'order.orderCustomer.customerId',
                $customer->getId()
            )
        );
        $criteria->addAssociations([
            'lineItems',
            'deliveries.shippingOrderAddress',
            'deliveries.stateMachineState',
            'orderCustomer',
            'tags',
            'transactions.stateMachineState',
            'stateMachineState',
        ]);

        /** @var OrderEntity|null $order */
        $order = $this->orderRepository->search($criteria, $context->getContext())
            ->first();

        if ($order === null) {
            throw new EntityNotFoundException('order', $orderId);
        }

        return $order;
    }

    /**
     * Load the available payment methods for the given context.
     *
     * @see SetPaymentOrderRoute::validateRequest()
     *
     * @return EntitySearchResult<PaymentMethodCollection>
     */
    private function loadAvailablePaymentMethods(SalesChannelContext $context): EntitySearchResult
    {
        $request = new Request();
        $request->query->set('onlyAvailable', '1');

        $result = $this->paymentMethodRoute->load($request, $context, new Criteria())->getObject();
        \assert($result instanceof EntitySearchResult);

        return $result;
    }

    /**
     * Validate the payment state of the given order through the {@see OrderService}.
     *
     * @see SetPaymentOrderRoute::validatePaymentState()
     *
     * @throws PaymentMethodNotChangeableException when the payment method for the order is no longer changeable
     */
    private function validatePaymentState(OrderEntity $order): void
    {
        if ($this->orderService->isPaymentChangeableByTransactionState($order)) {
            return;
        }

        throw new PaymentMethodNotChangeableException($order->getId());
    }
}
