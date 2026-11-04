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

namespace TheliaCMS\ImportExport\Step;

use TheliaCMS\ImportExport\ImportReport;
use TheliaCMS\ImportExport\SiteDocument;
use TheliaLibrary\Model\LibraryImageQuery;

/**
 * Matches the images of the file with the ones already here, by file name.
 */
final readonly class MediaStep
{
    /**
     * Matches the images of the file with the ones already here, by file name.
     *
     * @return array<int, int>
     */
    public function map(SiteDocument $document, ImportReport $report): array
    {
        $map = [];
        $missing = [];

        foreach ($document->media() as $id => $fileName) {
            $image = LibraryImageQuery::create()->findOneByFileName($fileName);

            if (null === $image) {
                $missing[] = $fileName;

                continue;
            }

            $map[$id] = (int) $image->getId();
        }

        if ([] !== $missing) {
            $report->warn(\sprintf(
                'These images are not in the media library of this site, and the content pointing at them will show a broken image until they are uploaded: %s',
                implode(', ', $missing),
            ));
        }

        return $map;
    }
}
