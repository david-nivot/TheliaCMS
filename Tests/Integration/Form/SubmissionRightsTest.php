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

use Symfony\Component\DomCrawler\Crawler;
use Thelia\Core\Security\AccessManager;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Form\Admin\CmsFormWriter;
use TheliaCMS\Form\Admin\FormSettings;
use TheliaCMS\Model\CmsForm;
use TheliaCMS\Model\CmsFormSubmission;
use TheliaCMS\Model\CmsFormSubmissionQuery;
use TheliaCMS\Model\Map\CmsFormSubmissionTableMap;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;

/**
 * The answers of a form are personal data: building the forms does not open
 * them, and reading them does not hand them all over in an export.
 */
final class SubmissionRightsTest extends AdminScreenTestCase
{
    private const array EVERY_ACCESS = [AccessManager::VIEW, AccessManager::CREATE, AccessManager::UPDATE, AccessManager::DELETE];

    public function testBuildingTheFormsDoesNotOpenTheAnswers(): void
    {
        $form = $this->createForm('rights-builder');
        $this->submission($form);
        $this->logInWith([CmsResources::FORM => self::EVERY_ACCESS]);

        self::assertSame(403, $this->send('GET', $this->answers($form)));
        self::assertSame(403, $this->send('GET', $this->answers($form).'/export.csv'));
        self::assertSame(403, $this->send('GET', $this->answers($form).'/export.json'));

        self::assertSame(0, $this->linksToTheAnswers($this->screen('/admin/cms/forms'), $form), 'The list offers no way to the answers.');
        self::assertSame(0, $this->linksToTheAnswers($this->screen('/admin/cms/forms/'.$form->getId()), $form), 'The form offers no way to its answers.');
    }

    public function testReadingTheAnswersDoesNotExportThem(): void
    {
        $form = $this->createForm('rights-reader');
        $this->submission($form);
        $this->logInWith([
            CmsResources::FORM => [AccessManager::VIEW],
            CmsResources::FORM_ANSWERS => [AccessManager::VIEW],
        ]);

        self::assertGreaterThan(0, $this->linksToTheAnswers($this->screen('/admin/cms/forms'), $form));

        $screen = $this->screen($this->answers($form));
        self::assertSame(0, $screen->filter('a[href*="/export."]')->count(), 'No export is offered.');
        self::assertSame(403, $this->send('GET', $this->answers($form).'/export.csv'));
        self::assertSame(403, $this->send('GET', $this->answers($form).'/export.json'));
    }

    public function testTheExportIsARightOfItsOwn(): void
    {
        $form = $this->createForm('rights-exporter');
        $this->submission($form);
        $this->logInWith([
            CmsResources::FORM_ANSWERS => [AccessManager::VIEW],
            CmsResources::FORM_ANSWERS_EXPORT => [AccessManager::VIEW],
        ]);

        self::assertSame(2, $this->screen($this->answers($form))->filter('a[href*="/export."]')->count());
        self::assertSame(200, $this->send('GET', $this->answers($form).'/export.csv'));
        self::assertSame(200, $this->send('GET', $this->answers($form).'/export.json'));
    }

    public function testDeletingAnAnswerTakesTheRightToDeleteAnswers(): void
    {
        $form = $this->createForm('rights-delete');
        $kept = $this->submission($form);
        $this->logInWith([
            CmsResources::FORM => self::EVERY_ACCESS,
            CmsResources::FORM_ANSWERS => [AccessManager::VIEW],
        ]);

        $screen = $this->screen($this->answers($form));
        self::assertSame(0, $screen->filter('form[action$="/delete"]')->count(), 'No delete button without the right.');
        self::assertSame(403, $this->send('POST', $this->answers($form).'/'.$kept.'/delete', ['_token' => $this->tokenOfTheScripts($screen)]));
        self::assertTrue($this->exists($kept));

        $this->logInWith([CmsResources::FORM_ANSWERS => [AccessManager::VIEW, AccessManager::DELETE]]);
        $screen = $this->screen($this->answers($form));
        $delete = $this->answers($form).'/'.$kept.'/delete';

        self::assertSame(302, $this->send('POST', $delete, ['_token' => $this->tokenOfTheForm($screen, $delete)]));
        self::assertFalse($this->exists($kept));
    }

    /**
     * @param array<string, list<string>> $grants
     */
    private function logInWith(array $grants): void
    {
        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin($grants));
    }

    private function answers(CmsForm $form): string
    {
        return '/admin/cms/forms/'.$form->getId().'/submissions';
    }

    private function linksToTheAnswers(Crawler $screen, CmsForm $form): int
    {
        return $screen->filter(\sprintf('a[href*="%s"]', $this->answers($form)))->count();
    }

    private function createForm(string $code): CmsForm
    {
        $form = new CmsForm();

        $this->getService(CmsFormWriter::class)->save($form, $this->locale(), new FormSettings(
            code: $code,
            active: true,
            recipients: '',
            storeSubmissions: true,
            retentionDays: 0,
            sendReceipt: false,
            privacyPolicyPageId: null,
            leadEvent: false,
            title: $code,
            description: '',
            submitLabel: '',
            successMessage: '',
            legalNotice: '',
            receiptSubject: '',
            receiptBody: '',
        ));

        return $form;
    }

    private function submission(CmsForm $form): int
    {
        $submission = (new CmsFormSubmission())
            ->setFormId($form->getId())
            ->setLocale($this->locale())
            ->setEmail('someone@example.test')
            ->setData('[]');
        $submission->save();

        return (int) $submission->getId();
    }

    private function exists(int $id): bool
    {
        CmsFormSubmissionTableMap::clearInstancePool();

        return null !== CmsFormSubmissionQuery::create()->findPk($id);
    }
}
