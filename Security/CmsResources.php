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

namespace TheliaCMS\Security;

/**
 * ACL resources owned by the module.
 *
 * They are both declared to the container (so `is_granted` knows them) and
 * seeded into `resource`/`profile_resource` on activation and on every update
 * of the module (a site active before a right existed only gets the update):
 * without the rows, `SecurityContext::isUserGranted()` finds the resource
 * missing from the profile permissions and denies everyone but the super
 * administrator — the "Editor" profile could never be given access.
 */
final class CmsResources
{
    public const string PAGE = 'admin.cms.page';

    /**
     * Putting pages and catalogue contents online, taking them offline, and
     * what else decides whether a page shows on the site (visibility, the
     * publication window, the bin, the home page). Apart from changing them:
     * an editor may write a page without being allowed to put it online.
     * Checked on UPDATE: split from the pages, it starts with the access a
     * profile had there, and a profile that only read the pages had VIEW.
     */
    public const string PAGE_PUBLISH = 'admin.cms.page.publish';
    public const string MENU = 'admin.cms.menu';
    public const string FORM = 'admin.cms.form';

    /**
     * The answers a form received: personal data, so a right of their own,
     * apart from building the forms (read, change their status, delete).
     */
    public const string FORM_ANSWERS = 'admin.cms.form.answers';

    /** Exporting the answers hands over every one of them at once. */
    public const string FORM_ANSWERS_EXPORT = 'admin.cms.form.answers.export';
    public const string MEDIA = 'admin.cms.media';
    public const string SETTINGS = 'admin.cms.settings';
    public const string CUSTOM_CODE = 'admin.cms.custom-code';

    /** Asking the generator to write: it costs money, so it is a right of its own. */
    public const string GENERATE = 'admin.cms.generate';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::PAGE,
            self::PAGE_PUBLISH,
            self::MENU,
            self::FORM,
            self::FORM_ANSWERS,
            self::FORM_ANSWERS_EXPORT,
            self::MEDIA,
            self::SETTINGS,
            self::CUSTOM_CODE,
            self::GENERATE,
        ];
    }

    /**
     * The rights split off an older one, each with the right it came from.
     *
     * Whoever held the older right did what the new one now guards: a profile
     * is given the same access when the new right is created, or the update
     * of the module would take away, without a word, what it could do.
     *
     * @return array<string, string>
     */
    public static function splitFrom(): array
    {
        return [
            self::PAGE_PUBLISH => self::PAGE,
            self::FORM_ANSWERS => self::FORM,
            self::FORM_ANSWERS_EXPORT => self::FORM,
        ];
    }
}
