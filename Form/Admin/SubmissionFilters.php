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

use Symfony\Component\HttpFoundation\Request;
use TheliaCMS\Form\SubmissionStatus;

/**
 * What the list of the answers is narrowed to, read from its address: the
 * screen, the export and the actions on a selection read the same one, so what
 * is exported or changed is what was shown.
 *
 * With no status asked, the list shows the answers still in use: new and
 * handled. The archived ones are one tab away, and "all" shows every answer,
 * which is what answering a request to see one's data needs.
 */
final readonly class SubmissionFilters
{
    public const string EVERY_STATUS = 'all';

    public function __construct(
        public string $email = '',
        public ?SubmissionStatus $status = null,
        public bool $everyStatus = false,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $asked = $request->isMethod('POST') ? $request->request : $request->query;
        $status = $asked->getString('status');

        return new self(
            email: trim($asked->getString('email')),
            status: SubmissionStatus::tryFrom($status),
            everyStatus: self::EVERY_STATUS === $status,
        );
    }

    /**
     * The statuses the list holds.
     *
     * @return list<SubmissionStatus>
     */
    public function statuses(): array
    {
        if ($this->everyStatus) {
            return SubmissionStatus::cases();
        }

        return null === $this->status ? [SubmissionStatus::New, SubmissionStatus::Handled] : [$this->status];
    }

    /** The value of the status in the address, empty for the default list. */
    public function statusParameter(): string
    {
        return $this->everyStatus ? self::EVERY_STATUS : ($this->status->value ?? '');
    }

    /**
     * The address of the list, without what is left at its default.
     *
     * @return array<string, string>
     */
    public function parameters(): array
    {
        return array_filter(['email' => $this->email, 'status' => $this->statusParameter()], static fn (string $value): bool => '' !== $value);
    }

    /**
     * The same list on another status tab.
     *
     * @return array<string, string>
     */
    public function onStatus(string $status): array
    {
        return array_filter(['email' => $this->email, 'status' => $status], static fn (string $value): bool => '' !== $value);
    }
}
