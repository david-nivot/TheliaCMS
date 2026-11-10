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

namespace TheliaCMS\Generation\Text\Admin;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Template\Parser\ParserResolver;
use TheliaCMS\Generation\Ai\AiAvailability;
use TheliaCMS\Generation\Text\TextField;
use TheliaCMS\Generation\Text\TextGenerator;
use TheliaCMS\Page\Admin\EditLanguage;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\TheliaCMS;
use Twig\Environment;

/**
 * The button that writes a rich-text field from a brief, on the screens
 * whose fields it serves.
 *
 * Hooked where the rich-text editor is: the screens call `wysiwyg.js` with
 * their own location, which says which form holds the fields. Each screen
 * gets the fields the button sits beside, what each of them is for, and where
 * the form keeps the title, the id and the language of the object. The CMS
 * page form keeps no id nor language: they are the ones of the screen.
 */
class TextBriefHook extends BaseHook
{
    /**
     * By location of the hook: the type of the object, the name of its form,
     * and its fields by name.
     *
     * @var array<string, array{type: string, form: string, fields: array<string, TextField>}>
     */
    private const array SCREENS = [
        'cms-page-edit-js' => ['type' => 'page', 'form' => 'cms_page', 'fields' => ['chapo' => TextField::Summary, 'description' => TextField::Description]],
        'product-edit-js' => ['type' => 'product', 'form' => 'thelia_product_modification', 'fields' => self::CATALOGUE_FIELDS],
        'category-edit-js' => ['type' => 'category', 'form' => 'thelia_category_modification', 'fields' => self::CATALOGUE_FIELDS],
        'wysiwyg-brand-edit-js' => ['type' => 'brand', 'form' => 'thelia_brand_modification', 'fields' => self::CATALOGUE_FIELDS],
        'folder-edit-js' => ['type' => 'folder', 'form' => 'thelia_folder_modification', 'fields' => self::CATALOGUE_FIELDS],
        'content-edit-js' => ['type' => 'content', 'form' => 'thelia_content_modification', 'fields' => self::CATALOGUE_FIELDS],
    ];

    private const array CATALOGUE_FIELDS = ['chapo' => TextField::Summary, 'description' => TextField::Description, 'postscriptum' => TextField::Conclusion];

    private const string SCRIPT = __DIR__.'/../../../templates/backOffice/default-twig/assets/rich-text-brief.js';

    public function __construct(
        private readonly SecurityContext $securityContext,
        private readonly UrlGeneratorInterface $urls,
        // Read on every rendering: BaseHook keeps the first request it is given.
        private readonly RequestStack $requests,
        private readonly EditLanguage $languages,
        private readonly Environment $twig,
        private readonly AiAvailability $availability,
        ?EventDispatcherInterface $dispatcher = null,
        ?ParserResolver $parserResolver = null,
    ) {
        parent::__construct($dispatcher, $parserResolver);
    }

    public static function getSubscribedHooks(): array
    {
        return [
            'wysiwyg.js' => [
                ['type' => 'back', 'method' => 'onWysiwygJs'],
            ],
        ];
    }

    public function onWysiwygJs(HookRenderEvent $event): void
    {
        $screen = self::SCREENS[(string) $event->getArgument('location', '')] ?? null;

        if (null === $screen || !$this->securityContext->isGranted(['ADMIN'], [CmsResources::GENERATE], [], [AccessManager::UPDATE])) {
            return;
        }

        // The button would only get the refusal of the AI layer: not drawn when it cannot serve the usage.
        if (!$this->availability->isAvailable(TextGenerator::USAGE)) {
            return;
        }

        $event->add($this->twig->render('@TheliaCMSModule/backOffice/default-twig/text-brief/_hook.html.twig', [
            'config' => $this->config($screen),
            'assets_version' => substr(hash('xxh3', (string) @filemtime(self::SCRIPT)), 0, 12),
        ]));
    }

    /**
     * @param array{type: string, form: string, fields: array<string, TextField>} $screen
     *
     * @return array<string, mixed>
     */
    private function config(array $screen): array
    {
        $form = $screen['form'];
        $config = [
            'url' => $this->urls->generate('admin.cms.text.generate'),
            'outcomeUrl' => $this->urls->generate('admin.cms.text.outcome'),
            'type' => $screen['type'],
            'title' => $form.'[title]',
            'idField' => $form.'[id]',
            'localeField' => $form.'[locale]',
            'fields' => array_map(
                static fn (string $name, TextField $field): array => ['name' => $form.'['.$name.']', 'kind' => $field->value],
                array_keys($screen['fields']),
                array_values($screen['fields']),
            ),
            'messages' => $this->messages(),
        ];

        if ('page' === $screen['type']) {
            $request = $this->requests->getMainRequest();
            $config['id'] = (int) $request?->attributes->get('id', 0);
            $config['locale'] = null === $request ? null : $this->languages->resolve($request)->getLocale();
            $config['idField'] = null;
            $config['localeField'] = null;
        }

        return $config;
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        $messages = [
            'button' => 'Write from a brief',
            'title' => 'Write this text from a brief',
            'brief' => 'What the text should say',
            'briefHelp' => 'The facts to give, the tone, the length. The generator reads what the field holds, the title, and may search the catalogue and the pages of the site.',
            'tone' => 'Tone',
            'audience' => 'Audience',
            'write' => 'Write',
            'writing' => 'Writing…',
            'again' => 'Write again',
            'replace' => 'Replace the text',
            'insert' => 'Insert at the cursor',
            'cancel' => 'Cancel',
            'preview' => 'The text written, not saved yet',
            'cost' => 'Cost: %cost% $',
            'failed' => 'The text could not be written.',
            'cut' => 'The connection was cut before the answer: the text may have been written all the same. Looking for it…',
            'lost' => 'No text came back for this brief. Write it again if you still need it.',
            'noEditor' => 'The editor of this field cannot be reached: the text cannot be put in it.',
        ];

        return array_map(fn (string $message): string => $this->trans($message, [], TheliaCMS::DOMAIN_NAME), $messages);
    }
}
