<?php

declare(strict_types=1);

namespace Shopware\Tests\Bench\Cases;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PhpBench\Attributes as Bench;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Tests\Bench\AbstractBenchCase;

/**
 * @internal - only for performance benchmarks
 */
class PromotionBench extends AbstractBenchCase
{
    /**
     * The number of random customer entries to generate for `promotion.orders_per_customer_count`.
     */
    private const SLOW_PROMOTION_ORDERS_PER_CUSTOMER_COUNT = 260_000;

    #[Bench\BeforeMethods(['setUp'])]
    #[Bench\AfterMethods(['tearDown'])]
    #[Bench\Assert('mode(variant.time.avg) < 10ms')]
    #[Bench\Assert('mode(variant.mem.peak) < 50mb')]
    public function bench_loading_a_simple_promotion(): void
    {
        $criteria = new Criteria(
            $this->ids->getList(['simple-promotion'])
        );

        static::getContainer()->get('promotion.repository')
            ->search($criteria, Context::createDefaultContext());
    }

    public function setUpTotals(): void
    {
        $totals = \iterator_to_array($this->generateTotals(iterations: self::SLOW_PROMOTION_ORDERS_PER_CUSTOMER_COUNT, maxUsesPerCustomer: 10));
        static::getContainer()->get(Connection::class)
            ->executeStatement('UPDATE promotion SET order_count = :count, orders_per_customer_count = :customerCount WHERE id = :id', [
                'id' => Uuid::fromHexToBytes($this->ids->get('simple-promotion')),
                'count' => \array_sum($totals),
                'customerCount' => $json = \json_encode($totals, \JSON_THROW_ON_ERROR),
            ], ['id' => ParameterType::BINARY]);

        // The measured memory usage is not representative, as data is first generated as array before encoding as JSON,
        //  which also takes up additional memory, so we ensure we are within the expected threshold of bytes.
        // This JSON will be about ~9mb.
        $jsonSizeInMb = \strlen($json) / (1024 ** 2);
        TestCase::assertGreaterThan(5/*mb*/, $jsonSizeInMb);
        TestCase::assertLessThan(10/*mb*/, $jsonSizeInMb);
    }

    #[Bench\BeforeMethods(['setUp', 'setUpTotals'])]
    #[Bench\AfterMethods(['tearDown'])]
    #[Bench\Assert('mode(variant.time.avg) > 35ms +/- 10ms')]
    #[Bench\Assert('mode(variant.mem.peak) > 100mb +/- 5mb')]
    public function bench_loading_a_promotion_with_large_orders_per_customer_count(): void
    {
        $criteria   = new Criteria(
            $this->ids->getList(['simple-promotion'])
        );

        // Simulate the cart processing impact for 2 active promotions, with 2 cart iterations.
        //  Our user-land custom implementation of free-products loads `promotion` association to get the rules.
        $data       = new CartDataCollection();
        $data->set('promotions', static::getContainer()->get('promotion.repository')
            ->search($criteria, Context::createDefaultContext()));
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
