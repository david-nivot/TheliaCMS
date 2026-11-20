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

namespace TheliaCMS\Tests\Unit\Generation;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Forms;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\SecurityContext;
use TheliaCMS\Content\Prop;
use TheliaCMS\Content\PropSchema;
use TheliaCMS\Content\SectionDefinitionInterface;
use TheliaCMS\Generation\Admin\SectionFormBuilder;
use TheliaCMS\Media\Admin\CmsMediaWriter;
use TheliaCMS\Media\CmsMediaLibrary;

/**
 * The controls of a component on its page of the catalogue: the fields of
 * its props, filled with its sample, with nothing of a section kept in a
 * document and nothing that sends a file.
 */
final class SectionFormComponentTest extends TestCase
{
    public function testTheControlsAreFilledWithTheSample(): void
    {
        $form = $this->builder()->forComponent($this->definition(), '/preview');

        self::assertSame(SectionFormBuilder::COMPONENT_FORM, $form->getName());
        self::assertSame('Hello', $form->get('title')->getData());
        self::assertSame('wide', $form->get('width')->getData());
        self::assertSame('Basic', $form->get('plans')->get('0')->get('name')->getData(), 'The rows of a list are filled too.');
        self::assertSame('/preview', $form->getConfig()->getOption('action'));
    }

    /**
     * Sabotage: add the appearance in forComponent() (`addAppearance`): the
     * child of the appearance appears.
     */
    public function testTheControlsHaveNoAppearanceNorSectionField(): void
    {
        $form = $this->builder()->forComponent($this->definition(), '/preview');

        self::assertFalse($form->has(SectionFormBuilder::APPEARANCE));
        self::assertFalse($form->has('section'));
        self::assertSame(['title', 'width', 'photo', 'photo__url', 'plans'], array_keys($form->all()));
    }

    /**
     * Sabotage: drop the `$files &&` of addImage() (or pass `files: true` in
     * forComponent()): the `__file` field is asked of the security context,
     * which this test leaves unbuilt, and the test goes red. The field itself
     * that comes back for whoever may upload is proved by the integration test
     * of the preview, with a real administrator.
     */
    public function testTheControlsSendNoFile(): void
    {
        $builder = $this->builder();
        $definition = $this->definition();

        $controls = $builder->forComponent($definition, '/preview');
        self::assertFalse($controls->has('photo__file'));
        self::assertTrue($controls->has('photo__url'), 'A picture is still given as an address.');
        self::assertArrayNotHasKey('data-cms-image-upload', $controls->get('photo')->getConfig()->getOption('attr'), 'The picker does not offer to add to the library.');

        foreach ($controls->get('plans')->all() as $row) {
            self::assertFalse($row->has('badge__file'));
            self::assertArrayNotHasKey('data-cms-image-upload', $row->get('badge')->getConfig()->getOption('attr'));
        }

        $prototype = $controls->createView()['plans']->vars['prototype'];
        self::assertFalse(isset($prototype['badge__file']), 'A row added by the script sends no file either.');
        self::assertTrue(isset($prototype['badge__url']));
    }

    public function testWhatIsTypedIsReadAsTheContentScreenReadsIt(): void
    {
        $builder = $this->builder();
        $definition = $this->definition();
        $form = $builder->forComponent($definition, '/preview');
        $form->submit(['title' => 'Typed', 'width' => 'narrow', 'plans' => [['name' => 'Pro', 'badge' => '', 'badge__url' => 'https://example.com/b.png']]]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame(
            ['title' => 'Typed', 'width' => 'narrow', 'photo' => 0, 'plans' => [['name' => 'Pro', 'badge' => 'https://example.com/b.png']]],
            $builder->propsFrom((array) $form->getData(), $definition->schema()->props),
        );
    }

    private function builder(): SectionFormBuilder
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/admin/cms/somewhere');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new SectionFormBuilder(
            Forms::createFormFactoryBuilder()->addTypeExtension(new class extends AbstractTypeExtension {
                public static function getExtendedTypes(): iterable
                {
                    return [FormType::class];
                }

                public function configureOptions(OptionsResolver $resolver): void
                {
                    $resolver->setDefaults(['allow_extra_fields' => false, 'csrf_protection' => false]);
                }
            })->getFormFactory(),
            (new \ReflectionClass(CmsMediaLibrary::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(CmsMediaWriter::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(SecurityContext::class))->newInstanceWithoutConstructor(),
            $urls,
            $translator,
        );
    }

    private function definition(): SectionDefinitionInterface
    {
        return new class implements SectionDefinitionInterface {
            public function name(): string
            {
                return 'Site:Plans';
            }

            public function label(): string
            {
                return 'Plans';
            }

            public function description(): string
            {
                return '';
            }

            public function schema(): PropSchema
            {
                return new PropSchema([
                    Prop::text('title', 'Title'),
                    Prop::choice('width', 'Width', ['narrow' => 'Narrow', 'wide' => 'Wide'], 'wide'),
                    Prop::image('photo', 'Photo'),
                    Prop::items('plans', 'Plans', [Prop::text('name', 'Name'), Prop::image('badge', 'Badge')], 0, 4),
                ]);
            }

            public function sample(): array
            {
                return ['title' => 'Hello', 'width' => 'wide', 'photo' => 0, 'plans' => [['name' => 'Basic', 'badge' => 0]]];
            }

            public function normalizeProps(array $props): array
            {
                return $props;
            }

            public function render(array $props, string $locale): string
            {
                return '';
            }
        };
    }
}
