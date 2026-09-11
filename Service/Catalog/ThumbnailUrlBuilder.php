<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Catalog;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Action\Image;
use Thelia\Core\Event\Image\ImageEvent;
use Thelia\Core\Event\TheliaEvents;

/**
 * Turns a media source file into the public URL of a square thumbnail, through
 * Thelia's image cache (the same pipeline the API uses for fileUrl).
 *
 * A thumbnail is decorative: any processing failure (missing source, corrupt
 * image, GD/Imagick error) is logged and yields no URL rather than breaking the
 * chat tool result that lists the products.
 */
final readonly class ThumbnailUrlBuilder
{
    public const SIZE = 240;

    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function build(string $sourceFilePath, string $cacheSubdirectory): ?string
    {
        $event = (new ImageEvent())
            ->setSourceFilepath($sourceFilePath)
            ->setCacheSubdirectory($cacheSubdirectory)
            ->setWidth(self::SIZE)
            ->setHeight(self::SIZE)
            ->setResizeMode((string) Image::EXACT_RATIO_WITH_BORDERS)
            ->setBackgroundColor('ffffff');

        try {
            $this->eventDispatcher->dispatch($event, TheliaEvents::IMAGE_PROCESS);
        } catch (\Throwable $exception) {
            $this->logger->warning('CommerceAgents: thumbnail generation failed for {file}: {reason}', [
                'file' => $sourceFilePath,
                'reason' => $exception->getMessage(),
            ]);

            return null;
        }

        return $event->getFileUrl();
    }
}
