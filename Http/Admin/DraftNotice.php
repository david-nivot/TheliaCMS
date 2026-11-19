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

namespace TheliaCMS\Http\Admin;

use Symfony\Component\Clock\Clock;
use Symfony\Component\HttpFoundation\Request;
use Thelia\Core\Security\SecurityContext;
use Thelia\Model\AdminQuery;
use TheliaCMS\Content\Editing\DraftLock;
use TheliaCMS\Content\Editing\StaleDraftException;

/**
 * What the screens tell an editor about the other editors of a draft: the
 * banner of a screen opened on a draft someone else changed within the hour,
 * and the refusal of a write made on a version that moved since it was read.
 * Also reads the version a screen request posted.
 */
final readonly class DraftNotice
{
    /** The field every form that writes a draft posts, holding the version the screen read. */
    public const string FIELD = 'draft_version';

    /** How long a change by someone else is worth a banner when the screen opens. */
    public const int RECENT_SECONDS = 3600;

    public function __construct(
        private AdminControllerSupport $support,
        private SecurityContext $securityContext,
    ) {
    }

    /**
     * The version the request posted; `DraftLock::NOT_POSTED` when it posted
     * none, which no draft matches: a screen request without it is refused.
     */
    public static function posted(Request $request): int
    {
        $posted = $request->request->get(self::FIELD);

        return \is_string($posted) && ctype_digit($posted) ? (int) $posted : DraftLock::NOT_POSTED;
    }

    /** Said when a write is refused: who changed the draft, and when. */
    public function refused(StaleDraftException $stale): string
    {
        return $this->support->translate('Your change was not saved: %who% changed this content %when%. Reload the screen to see their version; what you typed is still in the form.', [
            '%who%' => $this->who($stale->updatedBy),
            '%when%' => $this->when($stale->updatedAt),
        ]);
    }

    /**
     * Said when a screen opens on a draft someone else changed within the
     * hour; null otherwise.
     */
    public function banner(?int $author, ?\DateTimeInterface $changedAt): ?string
    {
        $reader = $this->securityContext->getAdminUser()?->getId();

        if (null === $author || null === $changedAt || $author === $reader) {
            return null;
        }

        if (Clock::get()->now()->getTimestamp() - $changedAt->getTimestamp() > self::RECENT_SECONDS) {
            return null;
        }

        return $this->support->translate('Changed by %who% %when%: they may still be working on it. A save made after theirs is refused rather than written over their changes.', [
            '%who%' => $this->who($author),
            '%when%' => $this->when($changedAt),
        ]);
    }

    private function who(?int $adminId): string
    {
        $admin = null === $adminId ? null : AdminQuery::create()->findPk($adminId);

        if (null === $admin) {
            return $this->support->translate('someone else');
        }

        $name = trim($admin->getFirstname().' '.$admin->getLastname());

        return '' !== $name ? $name : (string) $admin->getLogin();
    }

    private function when(?\DateTimeInterface $at): string
    {
        if (null === $at) {
            return $this->support->translate('a moment ago');
        }

        $minutes = intdiv(max(0, Clock::get()->now()->getTimestamp() - $at->getTimestamp()), 60);

        if ($minutes < 1) {
            return $this->support->translate('less than a minute ago');
        }

        if ($minutes < 60) {
            return $this->support->translate('%count% minute(s) ago', ['%count%' => $minutes]);
        }

        return $this->support->translate('on %date%', ['%date%' => $at->format('Y-m-d H:i')]);
    }
}
