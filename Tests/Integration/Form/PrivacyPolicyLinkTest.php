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

namespace TheliaCMS\Tests\Integration\Form;

use TheliaCMS\Form\FormCatalog;
use TheliaCMS\Model\CmsForm;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\TheliaCMS;

/**
 * The page the legal notice of a form links to.
 */
final class PrivacyPolicyLinkTest extends CmsIntegrationTestCase
{
    public function testThePageIsLinkedOnItsAddress(): void
    {
        $page = $this->createPage('Politique de confidentialite');

        self::assertMatchesRegularExpression('#^https?://[^/]+/politique-de-confidentialite[-0-9]*$#', (string) $this->linkTo((int) $page->getId()));
    }

    /**
     * The home page answers on the root only, its own address being a 301 to it.
     */
    public function testTheHomePageIsLinkedOnTheRoot(): void
    {
        $home = $this->createPage('Accueil et confidentialite');
        $previous = (string) TheliaCMS::getConfigValue('home_page_id', '0');
        TheliaCMS::setConfigValue('home_page_id', (string) $home->getId());

        try {
            $url = (string) $this->linkTo((int) $home->getId());
        } finally {
            TheliaCMS::setConfigValue('home_page_id', $previous);
        }

        self::assertMatchesRegularExpression('#^https?://[^/]+/$#', $url);
    }

    private function linkTo(int $pageId): ?string
    {
        $form = (new CmsForm())->setCode('privacy-'.bin2hex(random_bytes(4)))->setPrivacyPolicyPageId($pageId);
        $form->save();

        return $this->getService(FormCatalog::class)->definitionOf($form, $this->locale())->privacyPolicyUrl;
    }
}
