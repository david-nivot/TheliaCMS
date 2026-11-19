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

use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Lets another module turn a message away, a captcha for one.
 *
 * Dispatched once the trap and the stamp have let the message through and its
 * fields are valid, before it counts against the rate limit, spends its stamp
 * and is kept. A listener reads what it needs from the request, a token its
 * own field carried for instance, and refuses the message with the sentence
 * the visitor is shown above the form, which keeps what they typed.
 *
 * The field such a check needs is added to the form by the `cms.form.fields`
 * theme hook (FormPartial::FIELDS_HOOK), rendered inside every form.
 */
final class SubmissionCheckEvent extends Event
{
    private ?string $refusal = null;

    public function __construct(
        public readonly string $formCode,
        public readonly Request $request,
    ) {
    }

    /**
     * Turns the message away. The first refusal is the one shown: the
     * listeners after it are not called.
     */
    public function refuse(string $message): void
    {
        $this->refusal = $message;
        $this->stopPropagation();
    }

    public function refusal(): ?string
    {
        return $this->refusal;
    }
}
