<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Catalog;

use CommerceAgents\Service\Catalog\ThumbnailUrlBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Action\Image;
use Thelia\Core\Event\Image\ImageEvent;
use Thelia\Core\Event\TheliaEvents;

class FakeImageDispatcher implements EventDispatcherInterface
{
    public ?ImageEvent $event = null;
    public ?string $eventName = null;

    public function __construct(private readonly ?\Throwable $failure = null)
    {
    }

    public function dispatch(object $event, ?string $eventName = null): object
    {
        $this->event = $event instanceof ImageEvent ? $event : null;
        $this->eventName = $eventName;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($event instanceof ImageEvent) {
            $event->setFileUrl('https://shop.test/cache/images/'.$event->getCacheSubdirectory().'/thumb.jpg');
        }

        return $event;
    }
}

class ThumbnailUrlBuilderTest extends TestCase
{
    public function testDispatchesAResizeRequestAndReturnsTheCachedUrl(): void
    {
        $dispatcher = new FakeImageDispatcher();

        $url = (new ThumbnailUrlBuilder($dispatcher, new NullLogger()))->build('/local/media/images/product/PROD001-1.jpg', 'product');

        $this->assertSame('https://shop.test/cache/images/product/thumb.jpg', $url);
        $this->assertSame(TheliaEvents::IMAGE_PROCESS, $dispatcher->eventName);
        $this->assertSame('/local/media/images/product/PROD001-1.jpg', $dispatcher->event->getSourceFilepath());
        $this->assertSame('product', $dispatcher->event->getCacheSubdirectory());
        $this->assertSame(ThumbnailUrlBuilder::SIZE, $dispatcher->event->getWidth());
        $this->assertSame(ThumbnailUrlBuilder::SIZE, $dispatcher->event->getHeight());
        $this->assertSame((string) Image::EXACT_RATIO_WITH_BORDERS, $dispatcher->event->getResizeMode());
        $this->assertFalse($dispatcher->event->isOriginalImage());
    }

    public function testImageProcessingFailureYieldsNoUrl(): void
    {
        // A real failure surfaces as Thelia\Exception\ImageException, but that
        // class writes to the Tlog singleton on construction, which is not
        // booted in a pure unit test; a plain exception exercises the same
        // catch-and-degrade path.
        $dispatcher = new FakeImageDispatcher(new \RuntimeException('Source file cannot be opened.'));

        $url = (new ThumbnailUrlBuilder($dispatcher, new NullLogger()))->build('/local/media/images/product/missing.jpg', 'product');

        $this->assertNull($url);
    }
}
