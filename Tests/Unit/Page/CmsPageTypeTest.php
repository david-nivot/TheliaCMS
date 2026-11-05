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

namespace TheliaCMS\Tests\Unit\Page;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Validation;
use TheliaCMS\Page\Admin\CmsPageType;
use TheliaCMS\Page\Field\FieldDefinitions;
use TheliaCMS\Tests\Support\CeilingImages;

/**
 * The page form is built on every screen that edits a page: building it must not
 * raise a deprecation, and an address typed without its scheme is read as https.
 */
final class CmsPageTypeTest extends TestCase
{
    public function testTheFormIsBuiltWithoutADeprecation(): void
    {
        $deprecations = [];
        set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, \E_USER_DEPRECATED | \E_DEPRECATED);

        try {
            $this->createForm();
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $deprecations);
    }

    public function testACanonicalAddressWithoutItsSchemeIsReadAsHttps(): void
    {
        $form = $this->createForm();
        $form->submit(['canonical' => 'www.example.com/page'], false);

        self::assertSame('https://www.example.com/page', $form->get('canonical')->getData());
    }

    public function testThePageTypeIsOneOfTheTypesOffered(): void
    {
        $form = $this->createForm(['page_type_choices' => ['default', 'recipe']]);
        $form->submit(['pageType' => 'recipe'], false);

        self::assertTrue($form->get('pageType')->isValid());
        self::assertSame('recipe', $form->get('pageType')->getData());
    }

    public function testATypeMadeOfDigitsOnlyIsKeptAsItsCode(): void
    {
        $form = $this->createForm(['page_type_choices' => ['default', '2024']]);
        $form->submit(['pageType' => '2024'], false);

        self::assertTrue($form->get('pageType')->isValid());
        self::assertSame('2024', $form->get('pageType')->getData());
    }

    public function testATypeTheSiteDoesNotHaveIsRefused(): void
    {
        $form = $this->createForm(['page_type_choices' => ['default', 'recipe']]);
        $form->submit(['pageType' => 'news'], false);

        self::assertFalse($form->get('pageType')->isValid());
    }

    public function testThePageTagIsOneOfTheTagsOfTheSiteOrNone(): void
    {
        $form = $this->createForm(['tag_choices' => [3 => 'Recette', 7 => 'Actualité']]);
        $form->submit(['tag' => '7'], false);

        self::assertTrue($form->get('tag')->isValid());
        self::assertSame(7, $form->get('tag')->getData());

        $form = $this->createForm(['tag_choices' => [3 => 'Recette']]);
        $form->submit(['tag' => ''], false);

        self::assertTrue($form->get('tag')->isValid());
        self::assertNull($form->get('tag')->getData());
    }

    public function testATagTheSiteDoesNotHaveIsRefused(): void
    {
        $form = $this->createForm(['tag_choices' => [3 => 'Recette']]);
        $form->submit(['tag' => '4'], false);

        self::assertFalse($form->get('tag')->isValid());
    }

    public function testTheHomePageHasNoAddressFieldAndOtherPagesHaveBoth(): void
    {
        $home = $this->createForm(['is_home' => true]);

        self::assertFalse($home->has('slug'));
        self::assertFalse($home->has('fixedUrl'));
        self::assertTrue($home->has('title'));

        $ordinary = $this->createForm();

        self::assertTrue($ordinary->has('slug'));
        self::assertTrue($ordinary->has('fixedUrl'));
    }

    public function testTheFormOfTheHomePageIsValidWithoutAnAddress(): void
    {
        $form = $this->createForm(['is_home' => true]);
        $form->submit(['title' => 'Accueil', 'parent' => '0'], false);

        self::assertTrue($form->isValid());
    }

    public function testTheUploadFieldIsThereOnlyWhenUploadingIsAllowed(): void
    {
        self::assertFalse($this->createForm()->has('imageUpload'));
        self::assertTrue($this->createForm(['allow_image_upload' => true])->has('imageUpload'));
    }

    public function testTheImageOfAPageIsHeldUnderTheCeilingOfTheModule(): void
    {
        $error = static fn (UploadedFile $file): string => CeilingImages::errorOf(CmsPageType::class, 'imageUpload', $file, ['allow_image_upload' => true], ['title' => 'Page']);

        self::assertSame('', $error(CeilingImages::small()));
        self::assertStringContainsString('too many pixels', $error(CeilingImages::declaringTooManyPixels()));
        self::assertStringContainsString('too heavy', $error(CeilingImages::heavy()));
    }

    public function testAFileSentWithoutTheRightToUploadIsAnInvalidForm(): void
    {
        $form = $this->createForm();
        $form->submit(['title' => 'Page', 'imageUpload' => 'forged'], false);

        self::assertFalse($form->isValid());
        self::assertArrayHasKey('imageUpload', $form->getExtraData());
    }

    public function testTheFieldsOfTheTypeAreWidgetsNamedByTheirCode(): void
    {
        $form = $this->createForm([
            'field_locale' => 'fr_FR',
            'field_definitions' => FieldDefinitions::fromArray([
                ['code' => 'servings', 'kind' => 'integer', 'labels' => ['fr_FR' => 'Personnes'], 'required' => true],
                ['code' => 'vegetarian', 'kind' => 'boolean'],
                ['code' => 'difficulty', 'kind' => 'choice', 'choices' => ['facile', 'moyen']],
                ['code' => 'day', 'kind' => 'date'],
            ]),
        ]);

        self::assertSame(['servings', 'vegetarian', 'difficulty', 'day'], array_keys($form->get('fields')->all()));
        self::assertSame('Personnes', $form->get('fields')->get('servings')->getConfig()->getOption('label'));
        self::assertFalse($form->get('fields')->get('servings')->isRequired(), 'A draft is saved without its required values: publishing asks for them.');

        $form->submit(['fields' => ['servings' => '4', 'vegetarian' => '1', 'difficulty' => 'moyen', 'day' => '2026-12-24']], false);

        self::assertTrue($form->get('fields')->isValid());
        self::assertSame(['servings' => 4, 'vegetarian' => true, 'difficulty' => 'moyen', 'day' => '2026-12-24'], $form->get('fields')->getData());
    }

    public function testAPageOfATypeWithoutFieldsHasNoneToFill(): void
    {
        self::assertSame([], $this->createForm()->get('fields')->all());
    }

    /**
     * @param array<string, mixed> $options
     */
    private function createForm(array $options = []): FormInterface
    {
        return Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory()
            ->create(CmsPageType::class, null, $options);
    }
}
