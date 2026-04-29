<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Checkout\Promotion\Cart;

use Doctrine\DBAL\Connection;
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
     */
    private const SLOW_PROMOTION_ORDERS_PER_CUSTOMER_COUNT = 60_000;

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

    /**
     * @var EntityRepository<PromotionCollection>
     */
    private EntityRepository $promotionRepository;

    private IdsCollection $ids;

    protected function setUp(): void
    {
        $this->ids = new IdsCollection();
        $this->promotionRepository = static::getContainer()->get(\sprintf('%s.repository', PromotionDefinition::ENTITY_NAME));
    }

    #[SlowTestDetector\Attribute\MaximumDuration(5000)]
    public function testPromotionDiscountWithLargeNumberOfOrdersPerCustomerCount(): void
    {
        // Use the stopwatch as profiler (with more precise timing).
        $profiler = new Stopwatch(true);
        $profiler->start('baseline')->stop();

        $profiler->start('create-product');
        $taxId = static::getContainer()->get(Connection::class)
            ->fetchOne('SELECT LOWER(HEX(id)) FROM tax LIMIT 1');

        $product = (new ProductBuilder($this->ids, 'test-product'))
            ->price(100)
            ->stock(10)
            ->visibility();

        $productData = $product->build();
        $productData['taxId'] = $taxId;
        
        static::getContainer()->get('product.repository')
            ->create([$productData], Context::createDefaultContext());
        $profiler->stop('create-product');

        $context = $this->getContext();
        $profiler->start('create-promotion');
        $promotionId = $this->createPromotionWithLargeNumberOfOrdersPerCustomerCountData($context);
        $profiler->stop('create-promotion');
        // Take measurements on timing and also memory, using the symfony profiler (i.e. stopwatch).
        $profiler->start('add-product');
        $cart = $this->addProductToCart($product->id, $context);
        $profiler->stop('add-product');

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
        static::assertSame(95.0, $cart->getPrice()->getTotalPrice(), 'Cart total should be 95€ (100€ - 5€ discount)');

        $errors = $cart->getErrors()->getElements();
        static::assertCount(1, $errors, 'Cart should have exactly one error informational entry');
        $promotionError = array_values($errors)[0];
        static::assertInstanceOf(PromotionCartAddedInformationError::class, $promotionError);
        static::assertStringContainsString('has been added', $promotionError->getMessage());

        dump(\array_map('strval', $profiler->getRootSectionEvents()));
        return; // TODO: Complete assertions; add table output, if possible.

        // Make asserts on promotion fields' size/count, profiler min-loding speed and memory usage.
        $addProductEvent = $profiler->getEvent('add-product');
        static::assertGreaterThan(10.0, $addProductEvent->getDuration() / 1000); // divide ms by 1000 to get seconds value
        static::assertGreaterThan(10.0, $addProductEvent->getMemory() / (1024**2)); // divide by 1024^2 to get MB value
        $loadPromotionEvent = $profiler->getEvent('load-promotion');
        static::assertGreaterThan(10.0, $addProductEvent->getDuration() / 1000); // divide ms by 1000 to get seconds value
        static::assertGreaterThan(10.0, $addProductEvent->getMemory() / (1024**2)); // divide by 1024^2 to get MB value
    }

    /**
     * Creates a promotion with a large number of random customer redemptions.
     * The customers do not exist, but that's not relevant for the scope of this test,
     * which only concerns with the performance impact caused by a long-term, multi-use promotion.
     */
    private function createPromotionWithLargeNumberOfOrdersPerCustomerCountData(SalesChannelContext $context): string
    {
        $promotionId = $this->ids->create('large-promotion-0');
        $validFrom = new \DateTime();
        $validFrom->sub(new \DateInterval('PT1H'));
        $validUntil = new \DateTime();
        $validUntil->add(new \DateInterval('P1D'));
        $promotionData = [
            'id' => $promotionId,
            'active' => true,
            'exclusive' => false,
            'priority' => 1,
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
                    'value' => 5.0,
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

        if (\file_exists(__DIR__.'/_fixtures/promotion__orders_per_customer_count.json')) {
            // If available, use the fixture, to speed things up. TODO: Remove json_encode/json_decode, and use constant count value.
            $json = \file_get_contents(__DIR__.'/_fixtures/promotion__orders_per_customer_count.json');

            static::getContainer()->get(Connection::class)
                ->executeStatement('UPDATE promotion SET order_count = :count, orders_per_customer_count = :customerCount WHERE id = :id', [
                    'id' => Uuid::fromHexToBytes($promotionId),
                    'count' => self::SLOW_PROMOTION_ORDERS_PER_CUSTOMER_COUNT,
                    'customerCount' => $json,
                ], ['id' => ParameterType::BINARY]);
        } else {
            // Generate large number of random customer entries in the promotion's `orders_per_customer_count` field.
            $this->setUpTotals(['large-promotion-0' => self::SLOW_PROMOTION_ORDERS_PER_CUSTOMER_COUNT]);
        }

        return $promotionId;
    }
}
