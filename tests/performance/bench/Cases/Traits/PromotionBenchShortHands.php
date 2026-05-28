<?php

declare(strict_types=1);

namespace Shopware\Tests\Bench\Cases\Traits;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\Stub\Framework\IdsCollection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Helper methods to provision large `promotion.orders_per_customer_count` payload for promotions.
 *
 * Available env-var parameters:
 *
 * - `PRINT_MEM_ALLOC_ERROR`: Prints the error caused by totals' JSON not meeting expected size (`2-10mb`).
 *
 * @property IdsCollection $ids
 *
 * @internal - only for performance benchmarks
 */
trait PromotionBenchShortHands
{
    /**
     * Set up method for given promotion's totals.
     *
     * @param non-empty-array<string, positive-int> $promotionIds
     * @param positive-int                          $maxUsesPerCustomer
     * @throws \JsonException when the JSON serilaization fails
     */
    private function setUpTotals(array $promotionIds, int $maxUsesPerCustomer = 10): void
    {
        if (empty($promotionIds)) {
            throw new \LogicException('The promotion IDs must not be empty!');
        }

        foreach ($promotionIds as $promotionId => $iterations)
        {
            $totals = \iterator_to_array($this->generateTotals(iterations: $iterations, maxUsesPerCustomer: $maxUsesPerCustomer));

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
                //TestCase::assertLessThan(16/*mb*/, $jsonSizeInMb);
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

    abstract protected static function getContainer(): ContainerInterface;
}
