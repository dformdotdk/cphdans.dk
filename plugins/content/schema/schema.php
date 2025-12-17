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
        if (!isset($article->text) || trim($article->text) === '') {
            return;
        }

        $articleId = (int) ($article->id ?? 0);

        if (isset($this->schemaCache[$articleId])) {
            $this->injectJsonLd($this->schemaCache[$articleId]);
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
        $schemas = [];

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

        // Find the nearest accordion context
        $accordionNodes = $element->getElementsByTagName('ul');
        $accordion = null;

        foreach ($accordionNodes as $node) {
            if ($node->hasAttribute('class') && str_contains($node->getAttribute('class'), 'uk-accordion')) {
                $accordion = $node;
                break;
            }
        }

        if (!$accordion) {
            $accordion = $xpath->query('.//*[@class[contains(.,"uk-accordion")]]', $element)->item(0);
        }

        if (!$accordion) {
            return [];
        }

        foreach ($xpath->query('.//li', $accordion) as $item) {
            $titleNode = $xpath->query('.//*[contains(@class,"uk-accordion-title")]', $item)->item(0);
            $contentNode = $xpath->query('.//*[contains(@class,"uk-accordion-content")]', $item)->item(0);

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
