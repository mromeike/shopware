<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Checkout\Promotion\Cart;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\Exception as PDOException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\ParameterType;
use Ergebnis\PHPUnit\SlowTestDetector;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Promotion\Aggregate\PromotionDiscount\PromotionDiscountEntity;
use Shopware\Core\Checkout\Promotion\Cart\PromotionCartAddedInformationError;
use Shopware\Core\Checkout\Promotion\Cart\PromotionProcessor;
use Shopware\Core\Checkout\Promotion\PromotionCollection;
use Shopware\Core\Checkout\Promotion\PromotionDefinition;
use Shopware\Core\Checkout\Promotion\PromotionEntity;
use Shopware\Core\Content\Test\Product\ProductBuilder;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Test\Integration\Traits\TestShortHands;
use Shopware\Core\Test\Stub\Framework\IdsCollection;
use Shopware\Tests\Bench\Cases\Traits\PromotionBenchShortHands;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * @internal
 */
#[Package('checkout')]
class PromotionCollectorTest extends TestCase
{
    use IntegrationTestBehaviour;
    use TestShortHands;
    use PromotionBenchShortHands;

    /**
     * The number of random customer entries to generate for `promotion.orders_per_customer_count`.
     *
     * Note: The would-be number of orders and therefor customers (in a worst-case scenario),
     *  across 55 promotions in our system, if they were active at the same time and within the same valid-period,
     *   amounts to `637_725`.
     *
     * TODO: Distribute this number across multiple promotions, which are loaded during cart processing.
     *  The loading will only occur once (per request), but we're most interested in the memory footprint of them.
     *   This is because the availability requires the `orders_per_customer_count` field to be loaded in full,
     *  which will be several MB large, if not cleared to protect performance (the memory limit is recommended as `128mb`).
     *   So whenever the promotion is used over a long timeframe, it will accumulate customer entries in the JSON.
     *  From that follows, that in a long-running system with many customers, it would lead to unexpected degradation over time.
     */
    private const SLOW_PROMOTION_ORDERS_PER_CUSTOMER_COUNT = 100_000;

    /**
     * @see \Shopware\Core\Checkout\Promotion\Cart\PromotionCollector::REQUIRED_DAL_ASSOCIATIONS
     */
    private const REQUIRED_DAL_ASSOCIATIONS = [
        'personaRules',
        'personaCustomers',
        'cartRules',
        'orderRules',
        'discounts.discountRules',
        'discounts.promotionDiscountPrices',
        'setgroups.setGroupRules',
    ];

    private const TEST_TOKEN = 'promotion-collector-test-token';

    private IdsCollection $ids;

    private Stopwatch $profiler;

    /**
     * @var EntityRepository<PromotionCollection>
     */
    private EntityRepository $promotionRepository;

    protected function setUp(): void
    {
        $this->ids = new IdsCollection();
        $this->profiler = new Stopwatch(true);
        $this->promotionRepository = static::getContainer()->get(\sprintf('%s.repository', PromotionDefinition::ENTITY_NAME));

        self::setMaxAllowedPacket(16*(1024**2) /* = 16777216 */);
    }

    protected function tearDown(): void
    {
        dump(\array_map('strval', $this->profiler->getRootSectionEvents()));

        try {
            // Reset to original value `16777216` default (`16M`).
            self::setMaxAllowedPacket(16*(1024**2) /* = 16777216 */);
        } catch (ConnectionLost) {
            // Now you need to restart your database! It likely has perished and gone away...
        }
    }

    #[SlowTestDetector\Attribute\MaximumDuration(5000)]
    public function testPromotionDiscountWithLargeNumberOfOrdersPerCustomerCount(
        int $ordersPerCustomerCount = self::SLOW_PROMOTION_ORDERS_PER_CUSTOMER_COUNT
    ): void
    {
        // Use the stopwatch as profiler (with more precise timing).
        $profiler = $this->profiler;
        $profiler->start('baseline')->stop();

        $productId = $this->createProduct('test-product-0');
        $context = $this->getContext(self::TEST_TOKEN);

        $profiler->start('create-promotion');
        $promotionId = $this->createPromotionWithLargeNumberOfOrdersPerCustomerCountData('large-promotion-0', $context, $ordersPerCustomerCount);
        $profiler->stop('create-promotion');

        // Take measurements on timing and also memory, using the symfony profiler (i.e. stopwatch).
        $profiler->start('add-product');
        $cart = $this->addProductToCart($productId, $context);
        $profiler->stop('add-product');

        $errors = $cart->getErrors()->getElements();
        static::assertCount(1, $errors, 'Cart should have exactly one error informational entry');
        $promotionError = array_values($errors)[0];
        static::assertInstanceOf(PromotionCartAddedInformationError::class, $promotionError);
        static::assertStringContainsString('has been added', $promotionError->getMessage());

        $criteria = new Criteria([$promotionId]);
        $criteria->addAssociations(self::REQUIRED_DAL_ASSOCIATIONS);

        $profiler->start('load-promotion');
        $promotion = $this->promotionRepository->search($criteria, $context->getContext())->first();
        $profiler->stop('load-promotion');

        static::assertInstanceOf(PromotionEntity::class, $promotion);
        $discounts = $promotion->getDiscounts();
        static::assertNotNull($discounts, 'Promotion should have discounts');
        $discount = $discounts->first();
        static::assertInstanceOf(PromotionDiscountEntity::class, $discount);

        $promotionItems = $cart->getLineItems()->filterType(PromotionProcessor::LINE_ITEM_TYPE);
        static::assertGreaterThan(0, $promotionItems->count(), 'Promotion should be applied to cart');

        // We only know the price if not called from another test-case.
        if (__METHOD__ === $this->name()) {
            static::assertSame(95.0, $cart->getPrice()->getTotalPrice(), 'Cart total should be 95€ (100€ - 5€ discount)');
        }

        return; // TODO: Complete assertions; add table output, if possible.

        // Make asserts on promotion fields' size/count, profiler min-loading speed and memory usage.
        $addProductEvent = $profiler->getEvent('add-product');
        static::assertGreaterThan(10.0, $addProductEvent->getDuration() / 1000); // divide ms by 1000 to get seconds value
        static::assertGreaterThan(10.0, $addProductEvent->getMemory() / (1024**2)); // divide by 1024^2 to get MB value
        $loadPromotionEvent = $profiler->getEvent('load-promotion');
        static::assertGreaterThan(10.0, $addProductEvent->getDuration() / 1000); // divide ms by 1000 to get seconds value
        static::assertGreaterThan(10.0, $addProductEvent->getMemory() / (1024**2)); // divide by 1024^2 to get MB value
    }

    /**
     * Note: Reproduce "Packet for query is too large" error: https://dev.mysql.com/doc/refman/9.7/en/packet-too-large.html.
     *  See also: https://mariadb.com/docs/server/reference/error-codes/mariadb-error-codes-1100-to-1199/e1153.
     *   Limit was set to `4,194,304/(1,024**2)` (`4M`), so a row larger than 4 MB would likely lead to this issue.
     *  See also: https://dev.mysql.com/doc/refman/9.7/en/server-system-variables.html#sysvar_max_allowed_packet
     *   > You must increase this value if you are using large BLOB columns or long strings.
     *   > It should be as big as the largest BLOB you want to use. The protocol limit for max_allowed_packet is 1GB.
     *   > The value should be a multiple of 1024; nonmultiples are rounded down to the nearest multiple.
     *  So it should be possible to actually set-up the limit for a single session to a lower value and provoke the error.
     *   Of course it could also be caused by the client's limit, which is likely harder to reproduce. With PHP PDO client:
     *  `SET GLOBAL @@session.max_allowed_packet = 4194304;`
     *
     * THIS TEST HAS TO RUN LAST, AS IT WILL BREAK THE `database` CONTAINER!
     */
    #[SlowTestDetector\Attribute\MaximumDuration(5000)]
    public function testPromotionDiscountSizeForDatabasePacketError(): void
    {
        $profiler = $this->profiler;
        $profiler->start('baseline')->stop();

        // Start from 1, because 0 is already taken.
        $productIds = [
            $this->createProduct('test-product-1', 200),
            $this->createProduct('test-product-2', 9.99),
        ];

        $context = $this->getContext(self::TEST_TOKEN);

        $this->setMaxAllowedPacket(20*(1024**2));
        \sleep(1);

        // Generate from 1..3, because 0 is already taken.
        $promotionCount = 3;
        foreach (\range(1, $promotionCount) as $i) {
            $profiler->start('create-promotion');
            $this->createPromotionWithLargeNumberOfOrdersPerCustomerCountData("large-promotion-{$i}", $context,
                ordersPerCustomerCount: (6-$i) * self::SLOW_PROMOTION_ORDERS_PER_CUSTOMER_COUNT, discount: $i);
            $profiler->stop('create-promotion');
        }

        // Take measurements on timing and also memory, using the symfony profiler (i.e. stopwatch).
        foreach ($productIds as $productId) {
            $profiler->start('add-product');
            $cart = $this->addProductToCart($productId, $context);
            $profiler->stop('add-product');
        }

        $errors = $cart->getErrors()->getElements();
        static::assertCount($promotionCount, $errors, 'Cart should have exactly 4 error informational entries');
        static::assertContainsOnlyInstancesOf(PromotionCartAddedInformationError::class, $errors);

        foreach (\array_column($errors, 'message') as $promotionErrorMessage) {
            static::assertStringContainsString('has been added', $promotionErrorMessage);
        }

        // Restart the kernel.
        KernelLifecycleManager::ensureKernelShutdown();
        KernelLifecycleManager::bootKernel(reuseConnection: true);

        // Update to custom value `4194304` (`4M`).
        $this->setMaxAllowedPacket(4*(1024**2) /* = 4194304 */);

        // TODO: To provoke the error by loading, a single field value has to be larger - use 500_000.
        $profiler->start('run-test');
        try {
            $this->testPromotionDiscountWithLargeNumberOfOrdersPerCustomerCount(
                5 * self::SLOW_PROMOTION_ORDERS_PER_CUSTOMER_COUNT,
            );
        } catch (PDOException|DriverException $exception) {
            $profiler->stop('run-test');

            self::assertInstanceOf(DriverException::class, $exception);

            $exception = $exception->getPrevious();
            self::assertInstanceOf(PDOException::class, $exception);

            $exception = $exception->getPrevious();
            self::assertInstanceOf(\PDOException::class, $exception);

            self::expectException(\PDOException::class);
            self::expectExceptionMessage("SQLSTATE[08S01]: Communication link failure: 1153 Got a packet bigger than 'max_allowed_packet' bytes");

            // TODO: Assert source line.

            throw $exception;
        }
        $profiler->stop('run-test');

        self::fail('Expected to fail due to packet error from MySQL server!');
    }

    /**
     * Creates a promotion with a large number of random customer redemptions.
     * The customers do not exist, but that's not relevant for the scope of this test,
     * which only concerns with the performance impact caused by a long-term, multi-use promotion.
     */
    private function createPromotionWithLargeNumberOfOrdersPerCustomerCountData(
        string $promotionKey,
        SalesChannelContext $context,
        int $ordersPerCustomerCount,
        float $discount = 5.0,
    ): string
    {
        $promotionId = $this->ids->create($promotionKey);
        $validFrom = new \DateTime();
        $validFrom->sub(new \DateInterval('PT1H'));
        $validUntil = new \DateTime();
        $validUntil->add(new \DateInterval('P1D'));
        $promotionData = [
            'id' => $promotionId,
            'active' => true,
            'exclusive' => false,
            'priority' => \rand(1, 10),
            'useCodes' => false,
            'useIndividualCodes' => false,
            'useSetGroups' => false,
            'name' => 'Test Auto Promotion with large data',
            'preventCombination' => false,
            'validFrom' => $validFrom,
            'validUntil' => $validUntil,
            'maxRedemptionsGlobal' => null,
            'maxRedemptionsPerCustomer' => 5,
            'salesChannels' => [
                [
                    'salesChannelId' => $context->getSalesChannel()->getId(),
                    'priority' => 1,
                ],
            ],
            'discounts' => [
                [
                    'id' => Uuid::randomHex(),
                    'scope' => PromotionDiscountEntity::SCOPE_CART,
                    'type' => PromotionDiscountEntity::TYPE_ABSOLUTE,
                    'value' => $discount,
                    'considerAdvancedRules' => false,
                    'usageKey' => 'cart-usage',
                    'promotionDiscountPrices' => [],
                ],
            ],
        ];

        $this->promotionRepository->create(
            [$promotionData],
            $context->getContext()
        );

        // Generate large number of random customer entries in the promotion's `orders_per_customer_count` field.
        $this->setUpTotals([$promotionKey => $ordersPerCustomerCount], maxUsesPerCustomer: 5);

        return $promotionId;
    }

    private function createProduct(string $productNumber, float $price = 100, int $stock = 10): string
    {
        $profiler = $this->profiler;
        $profiler->start('create-product');
        $taxId = static::getContainer()->get(Connection::class)
            ->fetchOne('SELECT LOWER(HEX(id)) FROM tax LIMIT 1');

        $product = (new ProductBuilder($this->ids, $productNumber))
            ->price($price)
            ->stock($stock)
            ->visibility();

        $productData = $product->build();
        $productData['taxId'] = $taxId;

        static::getContainer()->get('product.repository')
            ->create([$productData], Context::createDefaultContext());
        $profiler->stop('create-product');

        return $product->id;
    }

    private static function setMaxAllowedPacket(int $value): void
    {
        $connection = static::getContainer()->get(Connection::class);

        $connection->executeStatement('SET GLOBAL max_allowed_packet = :value;', [
            'value' => $value,
        ], ['value' => ParameterType::INTEGER]);
    }
}
