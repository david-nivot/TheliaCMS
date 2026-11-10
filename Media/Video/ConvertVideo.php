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

use Thelia\Messenger\Message\DescribedJob;

/**
 * Converts the video of one `cms_video_conversion` row. Only the id travels:
 * the file and its state are read when the job runs.
 */
final readonly class ConvertVideo implements DescribedJob
{
    public function __construct(public int $conversionId)
    {
    }

    public function describe(): string
    {
        return \sprintf('Conversion of the video of the media library, row %d.', $this->conversionId);
    }
}
