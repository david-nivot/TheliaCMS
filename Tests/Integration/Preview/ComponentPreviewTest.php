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

namespace TheliaCMS\Tests\Integration\Preview;

use Symfony\Component\DomCrawler\Crawler;
use TheliaCMS\Content\ImageSection;
use TheliaCMS\Content\RichTextSection;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Content\Theme\ThemeComponentSection;
use TheliaCMS\Generation\Admin\SectionFormBuilder;
use TheliaCMS\Preview\ComponentPreviewLink;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\Tests\Unit\Content\Site\PriceTable;

/**
 * The frame that draws any component of the catalogue in the theme, with its
 * sample or with the props typed in the controls of its page.
 *
 * The signature opens the frame; typed props also need the token of the back
 * office and an administrator, as every write of the back office does.
 */
final class ComponentPreviewTest extends AdminScreenTestCase
{
    private const string FORM = SectionFormBuilder::COMPONENT_FORM;

    public function testAComponentOfTheModuleIsDrawnWithItsSample(): void
    {
        $this->client->request('GET', $this->frame(RichTextSection::NAME));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('A paragraph of text', (string) $this->client->getResponse()->getContent());
        self::assertSame('noindex, nofollow', $this->client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertSame('no-referrer', $this->client->getResponse()->headers->get('Referrer-Policy'));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testAComponentOfTheThemeAndAPartialAreDrawnWithTheirSample(): void
    {
        $names = ['Partial:cms-form'];

        foreach ($this->catalog()->all() as $name => $definition) {
            if ($definition instanceof ThemeComponentSection) {
                $names[] = $name;
            }
        }

        foreach ($names as $name) {
            $this->client->request('GET', $this->frame($name));

            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $name.' is drawn without an error.');
        }
    }

    /**
     * Sabotage: in ComponentPreviewController::definition(), find nothing for
     * a `section-` subject: the 404 comes back.
     */
    public function testAnUnknownNameIsNotFound(): void
    {
        $this->client->request('GET', $this->frame('cms:nothing'));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Sabotage: in ComponentPreviewController, skip the typed props (always
     * `normalizeProps(sample())`): the typed text is not in the frame.
     */
    public function testTheTypedPropsAreDrawnInTheFrame(): void
    {
        $token = $this->tokenOfTheControls(RichTextSection::NAME);

        $status = $this->send('POST', $this->frame(RichTextSection::NAME), ['_token' => $token, self::FORM => ['html' => '<p>Typed in the controls</p>']]);
        $page = (string) $this->client->getResponse()->getContent();

        self::assertSame(200, $status);
        self::assertStringContainsString('Typed in the controls', $page);
        self::assertStringNotContainsString('A paragraph of text', $page, 'The sample gives way to what is typed.');
        self::assertSame('no-referrer', $this->client->getResponse()->headers->get('Referrer-Policy'));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        self::assertSame(200, $this->send('POST', $this->frame(RichTextSection::NAME).'?content=1', ['_token' => $token, self::FORM => ['html' => '<p>Alone</p>']]));
        self::assertStringContainsString('Alone', (string) $this->client->getResponse()->getContent());
    }

    /**
     * Sabotage: drop the call of denyUnlessTheBackOfficeSent(): the post
     * without a token is drawn.
     */
    public function testAPostWithoutTheTokenOfTheBackOfficeIsRefused(): void
    {
        $token = $this->tokenOfTheControls(RichTextSection::NAME);
        $typed = [self::FORM => ['html' => '<p>Typed</p>']];

        self::assertSame(403, $this->send('POST', $this->frame(RichTextSection::NAME), $typed), 'No token.');
        self::assertSame(403, $this->send('POST', $this->frame(RichTextSection::NAME), ['_token' => 'not-the-token'] + $typed), 'Another token.');
        self::assertSame(200, $this->send('POST', $this->frame(RichTextSection::NAME), ['_token' => $token] + $typed), 'The token of the controls.');
    }

    /**
     * A session that holds the token but no administrator (a visitor whom a
     * page of the shop gave one, an administrator signed out) draws nothing.
     *
     * Sabotage: drop the hasAdminUser() check of denyUnlessTheBackOfficeSent().
     */
    public function testAPostWithTheTokenButNoAdministratorIsRefused(): void
    {
        $token = $this->tokenOfTheControls(RichTextSection::NAME);
        $address = $this->frame(RichTextSection::NAME);
        $this->logOut();

        self::assertSame(403, $this->send('POST', $address, ['_token' => $token, self::FORM => ['html' => '<p>Typed</p>']]));
    }

    /**
     * Sabotage: let the signature alone open a POST, or drop the signature
     * check: the address altered answers something else than 404.
     */
    public function testAPostNeedsTheSignatureToo(): void
    {
        $token = $this->tokenOfTheControls(RichTextSection::NAME);
        $address = $this->frame(RichTextSection::NAME);
        $forged = substr($address, 0, -1).('0' === substr($address, -1) ? '1' : '0');

        self::assertSame(404, $this->send('POST', $forged, ['_token' => $token, self::FORM => ['html' => '<p>Typed</p>']]));
    }

    /**
     * Sabotage: let the exception of the component through (no catch of
     * InvalidDocumentException): a 500 instead of the 422.
     */
    public function testPropsTheComponentRefusesAreSaidInTheFrameWithA422(): void
    {
        $token = $this->tokenOfTheControls(ImageSection::NAME);

        $status = $this->send('POST', $this->frame(ImageSection::NAME), ['_token' => $token, self::FORM => ['image' => '', 'image__url' => 'http://example.com/a.png', 'width' => 'narrow']]);
        $page = new Crawler((string) $this->client->getResponse()->getContent());

        self::assertSame(422, $status);
        self::assertSame(1, $page->filter('[data-testid="cms-component-preview-refusal"]')->count());
        self::assertGreaterThan(0, $page->filter('li')->count(), 'The reasons are listed.');
        self::assertStringContainsString('https', $page->filter('li')->first()->text(), 'The reason is the one of the content screen, readable.');
        self::assertSame('noindex, nofollow', $this->client->getResponse()->headers->get('X-Robots-Tag'));
    }

    /**
     * Sabotage: refuse nothing in the form (`allow_extra_fields`, choices):
     * a choice outside the list is drawn.
     */
    public function testAChoiceOutsideTheListIsRefusedWithA422(): void
    {
        $token = $this->tokenOfTheControls(ImageSection::NAME);

        self::assertSame(422, $this->send('POST', $this->frame(ImageSection::NAME), ['_token' => $token, self::FORM => ['image' => '', 'width' => 'enormous']]));
        self::assertSame(1, (new Crawler((string) $this->client->getResponse()->getContent()))->filter('[data-testid="cms-component-preview-refusal"]')->count());
    }

    /**
     * Sabotage: print no stylesheet for a `SiteComponentSection` found in the
     * catalogue (`stylesheet()` returns ''): the scoped rule is gone.
     */
    public function testAComponentOfTheSiteKeepsItsScopedStylesInTheTypedPreview(): void
    {
        $this->getService(SiteComponentRepository::class)->save(PriceTable::component(css: '.plan--featured { border: 2px solid var(--cms-color-accent); }'), SiteComponentRepository::STATUS_READY);
        $this->catalog()->reset();

        $get = $this->frame('Site:PriceTable');
        $this->client->request('GET', $get);
        self::assertStringContainsString('.cms-site--price-table .plan--featured', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('Our plans', (string) $this->client->getResponse()->getContent());

        $token = $this->tokenOfTheControls('Site:PriceTable');
        $status = $this->send('POST', $get, ['_token' => $token, self::FORM => ['title' => 'Typed plans', 'tone' => 'dark', 'plans' => [['name' => 'Solo', 'price' => '5 €', 'details' => '<p>One</p>', 'featured' => '1']]]]);
        $page = (string) $this->client->getResponse()->getContent();

        self::assertSame(200, $status);
        self::assertStringContainsString('Typed plans', $page);
        self::assertStringContainsString('Solo', $page);
        self::assertStringNotContainsString('>Pro<', $page, 'The rows typed replace the ones of the sample.');
        self::assertStringContainsString('.cms-site--price-table .plan--featured', $page);
        self::assertStringContainsString('plan plan--featured', $page, 'A ticked box is a true.');
    }

    /**
     * Sabotage: show the frame and the controls for the site components only
     * (`preview_url` null for the others): the other origins go red.
     */
    public function testThePageOfAComponentOfEveryOriginShowsTheFrameAndTheControls(): void
    {
        $this->getService(SiteComponentRepository::class)->save(PriceTable::component(), SiteComponentRepository::STATUS_READY);
        $this->catalog()->reset();

        $names = [RichTextSection::NAME, 'Partial:cms-form', 'Site:PriceTable'];

        foreach ($this->catalog()->all() as $name => $definition) {
            if ($definition instanceof ThemeComponentSection) {
                $names[] = $name;

                break;
            }
        }

        foreach ($names as $name) {
            $screen = $this->screen('/admin/cms/components/'.$name);

            $frame = $screen->filter('[data-testid="cms-site-component-frame"]');
            self::assertSame(1, $frame->count(), $name.' shows its frame.');
            // The component alone first when its props can be typed: what changes is in view at once.
            self::assertMatchesRegularExpression('#^/cms-component-preview/section-'.preg_quote($name, '#').'/[a-z]{2}_[A-Z]{2}/\d+/[a-f0-9]{64}\?content=1$#', (string) $frame->attr('src'));
            self::assertSame('content', $screen->filter('[data-testid="cms-site-component-preview-modes"] input:checked')->attr('value'));
            self::assertGreaterThan(1, $screen->filter('[data-testid="cms-site-component-preview-width"] option')->count(), 'Responsive, then the breakpoints of the theme, as on the content screen.');
            self::assertSame($frame->attr('name'), $screen->filter('form[data-cms-preview-form]')->attr('target'), 'The form is posted into the frame.');
            self::assertSame(1, $screen->filter('form[data-cms-preview-form] input[name="_token"]')->count(), $name);
            self::assertSame(1, $screen->filter('form[data-cms-preview-form] button[data-cms-preview-show]')->count(), 'Without the script, a button draws the frame.');
            self::assertSame(0, $screen->filter('form[data-cms-preview-form] input[type="file"]')->count(), $name.' sends no file.');
            self::assertSame(0, $screen->filter('form[data-cms-preview-form] [name*="__file"], form[data-cms-preview-form] [data-cms-image-upload]')->count(), $name);
            self::assertSame(0, $screen->filter('form[data-cms-preview-form] [name*="cms_appearance"]')->count(), $name.' has no appearance.');
            self::assertSame(1, $screen->filter('[data-cms-preview-reset]')->count());
            self::assertGreaterThan(0, $screen->filter('[data-testid="cms-site-component-props"] tbody tr')->count(), 'Beside the fields, the table of the props documents them.');
        }
    }

    public function testTheOtherSourceOfAPictureNamesNoFileTheControlsDoNotSend(): void
    {
        $summary = $this->screen('/admin/cms/components/'.ImageSection::NAME)->filter('form[data-cms-preview-form] details.cms-content__more summary');

        self::assertGreaterThan(0, $summary->count(), 'The address of a picture is folded under its choice.');
        self::assertDoesNotMatchRegularExpression('/file|fichier/i', $summary->first()->text(), 'Only an address is offered besides the library.');
    }

    /**
     * Sabotage: pass `files: true` in forComponent(): the field of the file
     * of a picture comes back for the administrator, who may upload.
     */
    public function testAnAdministratorWhoMayUploadIsNotOfferedAFileHere(): void
    {
        // The administrator of the tests has every right, the one to add to the library too.
        $screen = $this->screen('/admin/cms/components/'.ImageSection::NAME);
        self::assertSame(1, $screen->filter('form[data-cms-preview-form] [name="'.self::FORM.'[image__url]"]')->count(), 'A picture is given as an address.');
        self::assertSame(0, $screen->filter('form[data-cms-preview-form] [name="'.self::FORM.'[image__file]"]')->count());
    }

    private function frame(string $name): string
    {
        return (string) parse_url($this->getService(ComponentPreviewLink::class)->section($name, 'en_US'), \PHP_URL_PATH);
    }

    private function tokenOfTheControls(string $name): string
    {
        $screen = $this->screen('/admin/cms/components/'.$name);

        return (string) $screen->filter('form[data-cms-preview-form] input[name="_token"]')->attr('value');
    }

    private function catalog(): SectionCatalog
    {
        return $this->getService(SectionCatalog::class);
    }
}
