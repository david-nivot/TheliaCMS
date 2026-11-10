<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace TheliaCMS\Media\Video;

use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use TheliaCMS\Model\CmsVideoConversion;
use TheliaCMS\Model\CmsVideoConversionQuery;
use TheliaLibrary\Model\LibraryImage;

/**
 * The conversion of the videos of the media library: the row that says where a
 * video stands, and the job that converts it.
 */
final class VideoConversions
{
    /**
     * The queue of the heavy jobs of the core (exports, imports): a conversion
     * runs for minutes and must not hold up the mails of the shop. Without a
     * queue configured (`MESSENGER_TRANSPORT_DSN` empty) the core runs it at
     * once, in the request that sent the video.
     */
    public const string TRANSPORT = 'async_heavy';

    /** @var array<int, CmsVideoConversion|null> read rows by library image id, null for an id known to have none */
    private array $known = [];

    public function __construct(private readonly MessageBusInterface $bus)
    {
    }

    /**
     * Records that the video waits to be converted and queues the job. An
     * earlier row of the same video (a failed conversion replaced by a new
     * file) starts again.
     */
    public function queue(LibraryImage $video): CmsVideoConversion
    {
        $row = CmsVideoConversionQuery::create()->filterByLibraryImageId($video->getId())->findOne() ?? new CmsVideoConversion();
        $row
            ->setLibraryImageId($video->getId())
            ->setStatus(VideoConversionStatus::Processing->value)
            ->setFailure(null)
            ->setDetail(null)
            ->save();

        $this->known[(int) $video->getId()] = $row;

        $this->bus->dispatch(new Envelope(new ConvertVideo((int) $row->getId()), [new TransportNamesStamp([self::TRANSPORT])]));

        return $row;
    }

    /**
     * Reads the rows of these videos in one statement, for `of()` to answer
     * from: a page of the library costs one query whatever it holds.
     *
     * @param list<int> $videoIds
     */
    public function load(array $videoIds): void
    {
        $missing = array_values(array_filter($videoIds, fn (int $id): bool => !\array_key_exists($id, $this->known)));

        if ([] === $missing) {
            return;
        }

        foreach ($missing as $id) {
            $this->known[$id] = null;
        }

        foreach (CmsVideoConversionQuery::create()->filterByLibraryImageId($missing, Criteria::IN)->find() as $row) {
            $this->known[(int) $row->getLibraryImageId()] = $row;
        }
    }

    public function of(int $videoId): ?CmsVideoConversion
    {
        $this->load([$videoId]);

        return $this->known[$videoId];
    }

    /**
     * Where a video stands: ready when it has no row.
     */
    public function statusOf(int $videoId): VideoConversionStatus
    {
        $row = $this->of($videoId);

        return null === $row ? VideoConversionStatus::Ready : (VideoConversionStatus::tryFrom((string) $row->getStatus()) ?? VideoConversionStatus::Failed);
    }

    /**
     * The row goes with its video. The poster stays: it is an image of the
     * library that a page may have picked by itself.
     */
    public function forget(int $videoId): void
    {
        CmsVideoConversionQuery::create()->filterByLibraryImageId($videoId)->delete();
        unset($this->known[$videoId]);
    }
}
