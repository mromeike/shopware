<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Content\Media\Event;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Flow\Dispatching\StorableFlow;
use Shopware\Core\Content\Flow\Dispatching\Storer\ScalarValuesStorer;
use Shopware\Core\Content\Media\Event\MediaUploadedEvent;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\EventData\EventDataCollection;
use Shopware\Core\Framework\Event\EventData\ScalarValueType;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * @internal
 *
 * @package content
 *
 * @covers \Shopware\Core\Content\Media\Event\MediaUploadedEvent
 */
class MediaUploadedEventTest extends TestCase
{
    public function testInstance(): void
    {
        $mediaId = Uuid::randomHex();
        $context = Context::createDefaultContext();
        $mediaUploadEvent = new MediaUploadedEvent(
            $mediaId,
            $context
        );

        static::assertEquals('media.uploaded', $mediaUploadEvent->getName());
        static::assertEquals($mediaId, $mediaUploadEvent->getMediaId());
        static::assertEquals(
            $context,
            $mediaUploadEvent->getContext()
        );
    }

    public function testGetAvailableData(): void
    {
        $eventDataCollection = MediaUploadedEvent::getAvailableData();

        static::assertInstanceOf(EventDataCollection::class, $eventDataCollection);
        static::assertCount(1, $eventDataCollection->toArray());
        static::assertEquals(
            (new EventDataCollection())->add('mediaId', new ScalarValueType(ScalarValueType::TYPE_STRING)),
            $eventDataCollection
        );
    }

    public function testRestoreScalarValuesCorrectly(): void
    {
        $event = new MediaUploadedEvent('media-id', Context::createDefaultContext());

        $storer = new ScalarValuesStorer();

        $stored = $storer->store($event, []);

        $flow = new StorableFlow('foo', Context::createDefaultContext(), $stored);

        $storer->restore($flow);

        static::assertArrayHasKey('mediaId', $flow->data());
        static::assertEquals('media-id', $flow->data()['mediaId']);
    }
}
