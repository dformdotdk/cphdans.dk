<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Content.Schema
 */

namespace Joomla\Plugin\Content\Schema;

defined('_JEXEC') or die;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Throwable;

/**
 * Content plugin to generate JSON-LD schema.org data from markup and configuration.
 */
class PlgContentSchema extends CMSPlugin
{
    /**
     * Cache of generated schemas keyed by article id.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $schemaCache = [];

    /**
     * Application instance.
     *
     * @var CMSApplicationInterface
     */
    protected $app;

    /**
     * Handle onContentPrepare to parse schema annotations and inject JSON-LD.
     *
     * @param string   $context
     * @param object   $article
     * @param mixed    $params
     * @param integer  $page
     *
     * @return void
     */
    public function onContentPrepare(string $context, &$article, &$params, $page = 0): void
    {
        $articleId = (int) ($article->id ?? 0);

        if (isset($this->schemaCache[$articleId])) {
            $this->injectJsonLd($this->schemaCache[$articleId]);
            return;
        }

        $schemas = [];

        $localBusinessSchema = $this->buildLocalBusinessSchema($article);
        if (!empty($localBusinessSchema)) {
            $schemas[] = $localBusinessSchema;
        }

        if (!isset($article->text) || trim($article->text) === '') {
            if (!empty($schemas)) {
                $this->schemaCache[$articleId] = $schemas;
                $this->injectJsonLd($schemas);
            }

            return;
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        if (!$dom->loadHTML('<?xml encoding="utf-8" ?>' . $article->text)) {
            Log::add('Schema plugin: Unable to parse article HTML.', Log::WARNING, 'plg_content_schema');
            return;
        }
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//*[@schema]') as $element) {
            $type = strtolower($element->getAttribute('schema'));

            try {
                $handlerSchema = $this->buildSchemaForType($type, $element, $xpath, $article);
                if (!empty($handlerSchema)) {
                    $schemas[] = $handlerSchema;
                }
            } catch (Throwable $exception) {
                Log::add(
                    'Schema plugin: Skipping schema type ' . $type . ' due to error: ' . $exception->getMessage(),
                    Log::WARNING,
                    'plg_content_schema'
                );
            }
        }

        $overrides = $this->getArticleOverrides($article);
        if ($overrides) {
            $schemas[] = $overrides;
        }

        if (empty($schemas)) {
            return;
        }

        $this->schemaCache[$articleId] = $schemas;
        $this->injectJsonLd($schemas);
    }

    /**
     * Build schema for specific type.
     */
    private function buildSchemaForType(string $type, DOMElement $element, DOMXPath $xpath, object $article): array
    {
        switch ($type) {
            case 'faq':
                return $this->buildFaqSchema($element, $xpath, $article);
            case 'localbusiness':
                return $this->buildLocalBusinessSchema($article);
            default:
                return [];
        }
    }

    /**
     * Build FAQ schema.org JSON-LD from uk-accordion markup.
     */
    private function buildFaqSchema(DOMElement $element, DOMXPath $xpath, object $article): array
    {
        $faqs = [];

        $accordion = $this->locateAccordionContainer($element, $xpath);

        if (!$accordion) {
            return [];
        }

        $items = $this->locateAccordionItems($accordion, $xpath);

        foreach ($items as $item) {
            $titleNode = $this->queryFirstByClass($xpath, $item, 'uk-accordion-title');
            $contentNode = $this->queryFirstByClass($xpath, $item, 'uk-accordion-content');

            $question = $titleNode ? trim($titleNode->textContent) : '';
            $answer = $contentNode ? trim($this->getInnerHTML($contentNode)) : '';

            if ($question === '' || $answer === '') {
                Log::add('Schema plugin: Skipping FAQ item with missing title or content.', Log::WARNING, 'plg_content_schema');
                continue;
            }

            $faqs[] = [
                '@type' => 'Question',
                'name' => $question,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $answer,
                ],
            ];
        }

        if (empty($faqs)) {
            return [];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $faqs,
        ];
    }

    /**
     * Build LocalBusiness schema using plugin parameters.
     */
    private function buildLocalBusinessSchema(object $article): array
    {
        $name = trim((string) $this->params->get('business_name'));
        $logo = trim((string) $this->params->get('business_logo'));
        $description = trim((string) $this->params->get('business_description'));

        if ($name === '' || $description === '') {
            Log::add('Schema plugin: LocalBusiness requires name and description.', Log::WARNING, 'plg_content_schema');
            return [];
        }

        $address = array_filter([
            'streetAddress' => trim((string) $this->params->get('business_street')),
            'postalCode' => trim((string) $this->params->get('business_postal')),
            'addressLocality' => trim((string) $this->params->get('business_city')),
            'addressCountry' => trim((string) $this->params->get('business_country')),
        ]);

        $openingHours = [];
        for ($i = 1; $i <= 7; $i++) {
            $hours = trim((string) $this->params->get('opening_hours_' . $i));
            if ($hours !== '') {
                $openingHours[] = $hours;
            }
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => $name,
            'description' => $description,
        ];

        if ($logo !== '') {
            $schema['logo'] = $logo;
        }

        if (!empty($address)) {
            $schema['address'] = array_merge(['@type' => 'PostalAddress'], $address);
        }

        if (!empty($openingHours)) {
            $schema['openingHours'] = $openingHours;
        }

        $schema['url'] = $article->canonical ?? $article->link ?? '';

        return $schema;
    }

    /**
     * Retrieve article-specific overrides from custom fields.
     */
    private function getArticleOverrides(object $article): array
    {
        if (!isset($article->id)) {
            return [];
        }

        try {
            $fields = FieldsHelper::getFields('com_content.article', $article, true);
        } catch (Throwable $exception) {
            Log::add('Schema plugin: Unable to load custom fields. ' . $exception->getMessage(), Log::WARNING, 'plg_content_schema');
            return [];
        }

        foreach ($fields as $field) {
            if ($field->name === 'schema_jsonld' && !empty($field->value)) {
                $decoded = json_decode($field->value, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::add('Schema plugin: Invalid JSON in schema_jsonld field.', Log::WARNING, 'plg_content_schema');
                    return [];
                }

                return $decoded;
            }
        }

        return [];
    }

    /**
     * Inject the JSON-LD payload into the document.
     */
    private function injectJsonLd(array $schemas): void
    {
        if (empty($schemas)) {
            return;
        }

        $document = Factory::getApplication()->getDocument();
        $jsonLd = json_encode($schemas, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($jsonLd === false) {
            Log::add('Schema plugin: Failed to encode JSON-LD.', Log::ERROR, 'plg_content_schema');
            return;
        }

        $document->addCustomTag('<script type="application/ld+json">' . $jsonLd . '</script>');
    }

    /**
     * Find the accordion container from the schema-marked element.
     */
    private function locateAccordionContainer(DOMElement $element, DOMXPath $xpath): ?DOMElement
    {
        if ($this->nodeHasClass($element, 'uk-accordion')) {
            return $element;
        }

        $container = $xpath->query(
            'self::*[contains(concat(" ", normalize-space(@class), " "), " uk-accordion ")] | '
            . './/*[contains(concat(" ", normalize-space(@class), " "), " uk-accordion ")]',
            $element
        )->item(0);

        return $container instanceof DOMElement ? $container : null;
    }

    /**
     * Collect accordion items in both UL/LI and DIV-based structures.
     *
     * @return DOMElement[]
     */
    private function locateAccordionItems(DOMElement $accordion, DOMXPath $xpath): array
    {
        $items = [];

        foreach ($accordion->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            if (
                $child->tagName === 'li'
                || $this->nodeHasClass($child, 'el-item')
                || $this->nodeHasClass($child, 'uk-accordion-item')
            ) {
                $items[] = $child;
            }
        }

        if (!empty($items)) {
            return $items;
        }

        $nodeList = $xpath->query(
            './/*[contains(concat(" ", normalize-space(@class), " "), " el-item ")'
            . ' or contains(concat(" ", normalize-space(@class), " "), " uk-accordion-item ")]',
            $accordion
        );

        foreach ($nodeList as $node) {
            if ($node instanceof DOMElement) {
                $items[] = $node;
            }
        }

        if (empty($items)) {
            foreach ($xpath->query('.//li', $accordion) as $node) {
                if ($node instanceof DOMElement) {
                    $items[] = $node;
                }
            }
        }

        return $items;
    }

    /**
     * Query for the first descendant with the given class.
     */
    private function queryFirstByClass(DOMXPath $xpath, DOMElement $context, string $class): ?DOMElement
    {
        $node = $xpath->query(
            './/*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]',
            $context
        )->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    /**
     * Determine whether a DOMElement has a specific class name.
     */
    private function nodeHasClass(DOMElement $element, string $class): bool
    {
        $classes = ' ' . preg_replace('/\s+/', ' ', $element->getAttribute('class')) . ' ';

        return str_contains($classes, ' ' . $class . ' ');
    }

    /**
     * Helper to capture inner HTML of a DOMElement.
     */
    private function getInnerHTML(DOMElement $element): string
    {
        $innerHTML = '';

        foreach ($element->childNodes as $child) {
            $innerHTML .= $element->ownerDocument->saveHTML($child);
        }

        return trim($innerHTML);
    }
}
