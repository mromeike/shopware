<?php

declare(strict_types=1);

namespace Shopware\Tests\Bench\Cases;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PhpBench\Attributes as Bench;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Promotion\Gateway\PromotionGateway;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Tests\Bench\AbstractBenchCase;
use Shopware\Tests\Bench\Fixtures;

/**
 * Measurements of promotion performance with large `promotion.orders_per_customer_count` payload.
 *
 * Available env-var parameters:
 *
 * - `PRINT_MEM_ALLOC`: Prints the allocated memory for loaded promotions.
 * - `PRINT_MEM_ALLOC_ERROR`: Prints the error caused by totals' JSON not meeting expected size (`1-10mb`).
 *
 * @internal - only for performance benchmarks
 */
class PromotionBench extends AbstractBenchCase
{
    /**
     * The number of random customer entries to generate for `promotion.orders_per_customer_count`.
     */
    private const SLOW_PROMOTION_ORDERS_PER_CUSTOMER_COUNT = 260_000;

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

    #[Bench\BeforeMethods(['setUp'])]
    #[Bench\AfterMethods(['tearDown'])]
    #[Bench\Assert('mode(variant.time.avg) < 10ms')]
    #[Bench\Assert('mode(variant.mem.peak) < 50mb')]
    public function bench_loading_a_simple_promotion(): void
    {
        $context    = Context::createDefaultContext();

        $criteria   = new Criteria(
            $this->ids->getList(['simple-promotion'])
        );
        $criteria->addAssociations(self::REQUIRED_DAL_ASSOCIATIONS);

        $repo       = static::getContainer()->get('promotion.repository');

        // Amount seems to be too small to even register in total memory usage...
        $allocBytes = \memory_get_usage();
        $data       = new CartDataCollection();
        $data->set('promotions', $repo->search($criteria, $context));
        $allocBytes = \memory_get_usage() - $allocBytes;
        $allocMb    = $allocBytes / (1024 ** 2);

        // ~25kb to ~0.8mb measured
        !\array_key_exists('PRINT_MEM_ALLOC', $_SERVER) ?: \printf("%02.6f mb\n", $allocMb);
    }

    /**
     * Set up method for single large promotion total.
     *
     * @throws \JsonException when the JSON serilaization fails
     */
    public function setUpTotalsSingle(): void
    {
        $this->setUpTotals(['large-promotion-0' => self::SLOW_PROMOTION_ORDERS_PER_CUSTOMER_COUNT]);
    }

    #[Bench\BeforeMethods(['setUp', 'setUpTotalsSingle'])]
    #[Bench\AfterMethods(['tearDown'])]
    #[Bench\Assert('mode(variant.time.avg) > 35ms +/- 10ms')]
    #[Bench\Assert('mode(variant.mem.peak) > 100mb +/- 5mb')]
    public function bench_loading_a_large_promotion(): void
    {
        $context    = Context::createDefaultContext();

        $criteria   = new Criteria(
            $this->ids->getList(['large-promotion-0'])
        );
        $criteria->addAssociations(self::REQUIRED_DAL_ASSOCIATIONS);

        $repo       = static::getContainer()->get('promotion.repository');

        // Simulate the cart processing impact for one promotion.
        //  Our user-land custom implementation of free-products loads `promotion` association to get the rules.
        // Keep in mind that there is no associated data being provisioned, as it's negligible in size anyway.

        $allocBytes = \memory_get_usage(true);
        $data       = new CartDataCollection();
        $data->set('promotions', $repo->search($criteria, $context));
        $allocBytes = \memory_get_usage(true) - $allocBytes;
        $allocMb    = $allocBytes / (1024 ** 2);

        // ~10mb measured
        !\array_key_exists('PRINT_MEM_ALLOC', $_SERVER) ?: \printf("%02.6f mb\n", $allocMb);
    }

    /**
     * Set up method for extended promotions' totals.
     *
     * @throws \JsonException when the JSON serilaization fails
     */
    public function setUpTotalsExtended(): void
    {
        $this->setUpTotals([
            'large-promotion-0' => self::SLOW_PROMOTION_ORDERS_PER_CUSTOMER_COUNT,
            'large-promotion-1' => (int)(self::SLOW_PROMOTION_ORDERS_PER_CUSTOMER_COUNT / 2),
            'large-promotion-2' => (int)(self::SLOW_PROMOTION_ORDERS_PER_CUSTOMER_COUNT / 3),
        ]);
    }

    #[Bench\BeforeMethods(['setUp', 'setUpTotalsExtended'])]
    #[Bench\AfterMethods(['tearDown'])]
    #[Bench\Assert('mode(variant.time.avg) > 50ms +/- 10ms')]
    #[Bench\Assert('mode(variant.mem.peak) > 100mb +/- 5mb')]
    public function bench_loading_3_large_promotions(): void
    {
        $context    = Fixtures::context([
            SalesChannelContextService::CUSTOMER_ID => $this->ids->get('customer-0'),
        ]);

        $criteria   = new Criteria(
            $this->ids->getList(['large-promotion-0', 'large-promotion-1', 'large-promotion-2'])
        );
        $criteria->addAssociations(self::REQUIRED_DAL_ASSOCIATIONS);

        $gateway    = static::getContainer()->get(PromotionGateway::class);

        // Simulate the cart processing impact for 3 active promotions.
        //  Our user-land custom implementation of free-products loads `promotion` association to get the rules.
        // Keep in mind that there is no associated data being provisioned, as it's negligible in size anyway.

        $allocBytes = \memory_get_usage(true);
        $data       = new CartDataCollection();
        $data->set('promotions', $gateway->get($criteria, $context));
        $allocBytes = \memory_get_usage(true) - $allocBytes;
        $allocMb    = $allocBytes / (1024 ** 2);

        // ~20 - ~26mb measured
        !\array_key_exists('PRINT_MEM_ALLOC', $_SERVER) ?: \printf("%02.6f mb\n", $allocMb);
    }
    /**
     * Set up method for given promotion's totals.
     *
     * @param non-empty-array<string, positive-int> $promotionIds
     * @throws \JsonException when the JSON serilaization fails
     */
    private function setUpTotals(array $promotionIds): void
    {
        if (empty($promotionIds)) {
            throw new \LogicException('The promotion IDs must not be empty!');
        }

        foreach ($promotionIds as $promotionId => $iterations)
        {
            $totals = \iterator_to_array($this->generateTotals(iterations: $iterations, maxUsesPerCustomer: 10));

            static::getContainer()->get(Connection::class)
                ->executeStatement('UPDATE promotion SET order_count = :count, orders_per_customer_count = :customerCount WHERE id = :id', [
                    'id' => Uuid::fromHexToBytes($this->ids->get($promotionId)),
                    'count' => \array_sum($totals),
                    'customerCount' => $json = \json_encode($totals, \JSON_THROW_ON_ERROR),
                ], ['id' => ParameterType::BINARY]);

            // The measured memory usage is not representative, as data is first generated as array before encoding as JSON,
            //  which also takes up additional memory, so we ensure we are within the expected threshold of bytes.
            // This JSON should be between about 2mb and 10mb in size.
            $jsonSizeInMb = \strlen($json) / (1024 ** 2);
            try {
                TestCase::assertGreaterThan(2/*mb*/, $jsonSizeInMb);
                TestCase::assertLessThan(10/*mb*/, $jsonSizeInMb);
            } catch (\Throwable $e) {
                \array_key_exists('PRINT_MEM_ALLOC_ERROR', $_SERVER) ? \printf("%s\n", $e->getMessage()) : throw $e;
            }
        }
    }

    /**
     * Generate totals for the `promotion.orders_per_customer_count` column.
     *
     * @see \Shopware\Core\Checkout\Promotion\DataAbstractionLayer\PromotionRedemptionUpdater::update()
     *
     * @param positive-int $iterations
     * @param positive-int $minUsesPerCustomer
     * @param positive-int $maxUsesPerCustomer
     * @return \Generator<string, positive-int>
     */
    private function generateTotals(int $iterations = 1, int $minUsesPerCustomer = 1, int $maxUsesPerCustomer = 2): \Generator
    {
        while (0 < $iterations--) {
            yield Uuid::randomHex() => \rand($minUsesPerCustomer, $maxUsesPerCustomer);
        }
    }
}
