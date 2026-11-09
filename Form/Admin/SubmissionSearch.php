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

namespace TheliaCMS\Form\Admin;

use TheliaCMS\Model\CmsForm;

/**
 * The answers of one form narrowed by the filters of the list, with the answers
 * asked for already matched: the answers are JSON, read in PHP, so they are read
 * once per request and the page, the count and the tabs share the result.
 */
final readonly class SubmissionSearch
{
    /**
     * @param list<int>|null $ids the submissions whose answers match, of every
     *                            status; null when no answer is asked for
     */
    public function __construct(
        public CmsForm $form,
        public SubmissionFilters $filters,
        public ?array $ids = null,
    ) {
    }
}
