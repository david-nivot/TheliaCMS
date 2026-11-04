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

namespace TheliaCMS\Form;

use Propel\Runtime\ActiveQuery\Criteria;
use TheliaCMS\Model\CmsFormQuery;
use TheliaCMS\Model\CmsFormSubmissionQuery;

/**
 * Deletes the submissions each form has kept longer than it said it would.
 *
 * Retention that depends on somebody remembering is retention that does not
 * happen, so this runs from `maintenance:purge` — the scheduled command a
 * Thelia site already has — as well as on its own.
 */
final readonly class SubmissionPurger
{
    /**
     * @return int how many submissions were deleted, or would be on a dry run
     */
    public function purge(?\DateTimeImmutable $now = null, bool $dryRun = false): int
    {
        $now ??= new \DateTimeImmutable();
        $deleted = 0;

        // Soft-deleted forms are included on purpose: a form somebody removed
        // from the back office keeps its answers, and those answers keep ageing
        // out. Deleting a form is not a way to keep personal data forever.
        foreach (CmsFormQuery::create()->find() as $form) {
            $cutoff = RetentionPolicy::cutoff((int) $form->getRetentionDays(), $now);

            if (null === $cutoff) {
                continue;
            }

            $due = CmsFormSubmissionQuery::create()
                ->filterByFormId($form->getId())
                ->filterByCreatedAt($cutoff, Criteria::LESS_THAN);

            $deleted += $dryRun ? $due->count() : $due->delete();
        }

        return $deleted;
    }
}
