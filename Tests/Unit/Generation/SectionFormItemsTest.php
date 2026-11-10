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
use TheliaCMS\Content\Section;
use TheliaCMS\Content\SectionDefinitionInterface;
use TheliaCMS\Generation\Admin\SectionFormBuilder;
use TheliaCMS\Media\Admin\CmsMediaWriter;
use TheliaCMS\Media\CmsMediaLibrary;

/**
 * A list of scalars is stored as a list of strings and edited as one field per
 * row: the form wraps each value into a row and unwraps it on submit.
 */
final class SectionFormItemsTest extends TestCase
{
    public function testAListOfScalarsIsOneFieldPerRowInTheForm(): void
    {
        $steps = Prop::items('steps', 'steps', [Prop::text('value', 'value', required: true, max: 500)], 0, 50, scalarItems: true);
        $form = $this->builder()->build($this->definition($steps), new Section('recipe', 'Organisms:Recipe:Base', ['steps' => ['Mix', 'Bake']]), '/save');

        self::assertSame([['value' => 'Mix'], ['value' => 'Bake']], $form->get('steps')->getData(), 'Each stored scalar is the one field of a row.');
        self::assertCount(2, $form->get('steps')->all());
        self::assertSame('Mix', $form->get('steps')->get('0')->get('value')->getData());
    }

    public function testASubmittedRowIsUnwrappedToAScalar(): void
    {
        $steps = Prop::items('steps', 'steps', [Prop::text('value', 'value', required: true, max: 500)], 0, 50, scalarItems: true);
        $builder = $this->builder();
        $form = $builder->build($this->definition($steps), new Section('recipe', 'Organisms:Recipe:Base', ['steps' => []]), '/save');
        $form->submit(['steps' => [['value' => 'Mix'], ['value' => 'Bake']]]);

        self::assertSame(['steps' => ['Mix', 'Bake']], $builder->propsFrom($form->getData(), [$steps]));
    }

    public function testARowOfFieldsStaysARow(): void
    {
        $ingredients = Prop::items('ingredients', 'ingredients', [Prop::text('name', 'name', required: true)], 0, 50);
        $builder = $this->builder();
        $form = $builder->build($this->definition($ingredients), new Section('recipe', 'Organisms:Recipe:Base', ['ingredients' => [['name' => 'Flour']]]), '/save');

        self::assertSame([['name' => 'Flour']], $form->get('ingredients')->getData());

        $form->submit(['ingredients' => [['name' => 'Flour'], ['name' => 'Salt']]]);

        self::assertSame(['ingredients' => [['name' => 'Flour'], ['name' => 'Salt']]], $builder->propsFrom($form->getData(), [$ingredients]));
    }

    public function testARowSpelledAsItsTemplateFillsItsFields(): void
    {
        $slides = Prop::items('items', 'items', [Prop::text('title', 'title'), Prop::text('button_label', 'button_label')], 0, 50);
        $builder = $this->builder();
        $form = $builder->build($this->definition($slides), new Section('carousel', 'Organisms:Carousel:Base', ['items' => [['title' => 'Spring', 'buttonLabel' => 'Read more']]]), '/save');

        self::assertSame('Read more', $form->get('items')->get('0')->get('button_label')->getData(), 'A row stored before it was read as fields keeps its values in the form.');
    }

    public function testARequiredFieldOfARowIsRequiredInAListThatMayBeEmpty(): void
    {
        $videos = Prop::items('videos', 'videos', [Prop::text('title', 'title', required: true), Prop::text('caption', 'caption')], 0, 50);
        $view = $this->builder()->build($this->definition($videos), new Section('videos', 'Organisms:Videos:Base', ['videos' => [['title' => 'Spring']]]), '/save')->createView();

        self::assertTrue($view['videos'][0]['title']->vars['required'], 'A list of no row at least says nothing of the fields of its rows.');
        self::assertFalse($view['videos'][0]['caption']->vars['required']);
        self::assertTrue($view['videos']->vars['prototype']['title']->vars['required'], 'A row added by the script is drawn from the prototype.');
        self::assertFalse($view['videos']->vars['prototype']['caption']->vars['required']);
    }

    public function testTheFormShowsTheHelpWrittenForTheEditor(): void
    {
        $asked = Prop::text('caption', 'Caption', help: 'Instruction to the model.')->forEditor(help: 'Words for the editor.');
        $plain = Prop::text('alt', 'Alt', help: 'Only one help.');
        $form = $this->builder()->build($this->definition($asked, $plain), new Section('picture', 'cms:image', []), '/save');

        self::assertSame('Words for the editor.', $form->get('caption')->getConfig()->getOption('help'), 'The editor reads the help written for it.');
        self::assertSame('Only one help.', $form->get('alt')->getConfig()->getOption('help'), 'Without one, the help of the prop.');
    }

    public function testAnEmbedFieldShowsTheAddressBuiltFromWhatWasKept(): void
    {
        $video = Prop::embed('video', 'Presentation video');
        $clips = Prop::items('clips', 'clips', [Prop::embed('clip', 'Clip')], 0, 6);
        $form = $this->builder()->build($this->definition($video, $clips), new Section('band', 'Site:Band', ['video' => 'youtube:dQw4w9WgXcQ', 'clips' => [['clip' => 'vimeo:76979871'], ['clip' => 'javascript:alert(1)']]]), '/save');

        self::assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $form->get('video')->getData());
        self::assertSame('https://vimeo.com/76979871', $form->get('clips')->get('0')->get('clip')->getData());
        self::assertSame('', $form->get('clips')->get('1')->get('clip')->getData(), 'What was stored that is no video is not shown.');
        self::assertStringContainsString('YouTube, Vimeo or Dailymotion', (string) $form->get('video')->getConfig()->getOption('help'), 'The field says what it takes.');
        self::assertSame('url', $form->get('video')->getConfig()->getOption('attr')['inputmode']);
    }

    public function testAnAddressTypedInAnEmbedFieldGoesToTheSchemaAsTyped(): void
    {
        $video = Prop::embed('video', 'Presentation video');
        $builder = $this->builder();
        $form = $builder->build($this->definition($video), new Section('band', 'Site:Band', []), '/save');
        $form->submit(['video' => 'https://youtu.be/dQw4w9WgXcQ?t=4']);

        $props = $builder->propsFrom($form->getData(), [$video]);

        self::assertSame(['video' => 'https://youtu.be/dQw4w9WgXcQ?t=4'], $props);
        self::assertSame(['video' => 'youtube:dQw4w9WgXcQ'], (new PropSchema([$video]))->normalize($props));
    }

    private function builder(): SectionFormBuilder
    {
        return new SectionFormBuilder(
            Forms::createFormFactoryBuilder()->addTypeExtension($this->validatorAndCsrfOptions())->getFormFactory(),
            (new \ReflectionClass(CmsMediaLibrary::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(CmsMediaWriter::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(SecurityContext::class))->newInstanceWithoutConstructor(),
            $this->createStub(UrlGeneratorInterface::class),
            $this->translator(),
        );
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return $translator;
    }

    /**
     * The options the builder passes that the validator and the CSRF
     * extensions of the application declare: neither is installed here.
     */
    private function validatorAndCsrfOptions(): AbstractTypeExtension
    {
        return new class extends AbstractTypeExtension {
            public static function getExtendedTypes(): iterable
            {
                return [FormType::class];
            }

            public function configureOptions(OptionsResolver $resolver): void
            {
                $resolver->setDefaults(['allow_extra_fields' => false, 'csrf_protection' => false]);
            }
        };
    }

    private function definition(Prop ...$props): SectionDefinitionInterface
    {
        return new class($props) implements SectionDefinitionInterface {
            /**
             * @param list<Prop> $props
             */
            public function __construct(private readonly array $props)
            {
            }

            public function name(): string
            {
                return 'Organisms:Recipe:Base';
            }

            public function label(): string
            {
                return 'Recipe';
            }

            public function description(): string
            {
                return '';
            }

            public function schema(): PropSchema
            {
                return new PropSchema($this->props);
            }

            public function sample(): array
            {
                return [];
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
