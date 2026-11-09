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

namespace TheliaCMS\Settings;

use Thelia\Core\Security\AccessManager;
use Thelia\Model\LangQuery;
use Thelia\Model\Profile;
use Thelia\Model\ProfileQuery;
use Thelia\Model\ProfileResource;
use Thelia\Model\ProfileResourceQuery;
use Thelia\Model\ResourceQuery;
use TheliaCMS\Security\CmsResources;

/**
 * Creates the profile a showcase site hands to the person who edits it.
 *
 * Without it, the only account that can reach the CMS screens is the super
 * administrator, because a resource with no row in `profile_resource` is denied
 * to every profile — and building that permission matrix by hand, resource by
 * resource, is exactly the step an agency skips.
 *
 * Deliberately not granted: the settings of this module, free HTML, and anything
 * belonging to the shop. Those are the integrator's, and this profile is handed
 * to the client.
 *
 * Replayable: an existing profile is left exactly as the administrator left it,
 * permissions included.
 */
final readonly class EditorProfileSeeder
{
    public const string CODE = 'CMS_EDITOR';

    /**
     * @var array<string, array{title: string, description: string}>
     */
    private const array LABELS = [
        'en_US' => [
            'title' => 'Editor',
            'description' => 'Writes the site: pages, menus, media, forms and news. No access to the shop or to the technical settings.',
        ],
        'fr_FR' => [
            'title' => 'Éditeur',
            'description' => 'Rédige le site : pages, menus, médias, formulaires et actualités. Aucun accès à la boutique ni aux réglages techniques.',
        ],
    ];

    /**
     * Everything this profile may do, on the resources it may do it on.
     *
     * @var list<string>
     */
    private const array FULL_ACCESS = [
        CmsResources::PAGE,
        CmsResources::MENU,
        CmsResources::MEDIA,
        CmsResources::FORM,
        // The answers too: the editor is the one who calls back.
        CmsResources::FORM_ANSWERS,
        CmsResources::FORM_ANSWERS_EXPORT,
        CmsResources::GENERATE,
        // News are core contents and folders (decision D11).
        'admin.content',
        'admin.folder',
    ];

    public function exists(): bool
    {
        return null !== ProfileQuery::create()->findOneByCode(self::CODE);
    }

    /**
     * @return bool whether the profile was created by this call
     */
    public function seed(): bool
    {
        if ($this->exists()) {
            return false;
        }

        $profile = new Profile();
        $profile->setCode(self::CODE);

        foreach (LangQuery::create()->filterByActive(1)->find() as $lang) {
            $labels = self::LABELS[$lang->getLocale()] ?? self::LABELS['en_US'];

            $profile->setLocale($lang->getLocale())
                ->setTitle($labels['title'])
                ->setDescription($labels['description']);
        }

        $profile->save();

        $granted = (new AccessManager(0));
        $granted->build([AccessManager::VIEW, AccessManager::CREATE, AccessManager::UPDATE, AccessManager::DELETE]);

        // A profile is denied a resource it has no row for, so every resource
        // gets one — the ones this profile has no business in get a row with no
        // access at all, which is also what makes them visible, and refusable,
        // on the profile screen.
        foreach (ResourceQuery::create()->find() as $resource) {
            $access = \in_array((string) $resource->getCode(), self::FULL_ACCESS, true)
                ? $granted->getAccessValue()
                : 0;

            $existing = ProfileResourceQuery::create()
                ->filterByProfileId($profile->getId())
                ->filterByResourceId($resource->getId())
                ->findOne();

            ($existing ?? (new ProfileResource())
                ->setProfileId($profile->getId())
                ->setResourceId($resource->getId()))
                ->setAccess($access)
                ->save();
        }

        return true;
    }
}
