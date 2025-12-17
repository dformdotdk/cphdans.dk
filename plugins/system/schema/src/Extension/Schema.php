<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.schema
 */

namespace Joomla\Plugin\System\Schema\Extension;

\defined('_JEXEC') or die;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use Throwable;

/**
 * System plugin to generate JSON-LD schema.org data from page markup and configuration.
 */
final class Schema extends CMSPlugin implements SubscriberInterface
{
    /**
     * Cache of generated schemas per-request to avoid duplicate work when rendering modules.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $schemaCache = [];

    /**
     * Returns events the plugin listens to.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterRender' => 'onAfterRender',
        ];
    }

    /**
     * Collect schema data after rendering the page and inject JSON-LD.
     */
    public function onAfterRender(): void
    {
        $app = $this->getApplication() ?? Factory::getApplication();

        if ($app->isClient('administrator')) {
            return;
        }

        $cacheKey = ($app->getMenu()->getActive()?->id ?? '0') . ':' . $app->getUri()->toString();
        if (isset($this->schemaCache[$cacheKey])) {
            $body = $app->getBody();
            $this->injectJsonLd($this->schemaCache[$cacheKey], $body, $app);
            return;
        }

        $body    = $app->getBody();
        $schemas = [];

        $localBusinessSchema = $this->buildLocalBusinessSchema($app);
        if (!empty($localBusinessSchema)) {
            $schemas[] = $localBusinessSchema;
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);

        if ($body !== '' && $dom->loadHTML('<?xml encoding="utf-8" ?>' . $body)) {
            $xpath = new DOMXPath($dom);

            foreach ($xpath->query('//*[@schema]') as $element) {
                $type = strtolower($element->getAttribute('schema'));

                try {
                    $schema = $this->buildSchemaForType($type, $element, $xpath);

                    if (!empty($schema)) {
                        $schemas[] = $schema;
                    }
                } catch (Throwable $exception) {
                    Log::add(
                        'Schema plugin: Skipping schema type ' . $type . ' due to error: ' . $exception->getMessage(),
                        Log::WARNING,
                        'plg_system_schema'
                    );
                }
            }
        }

        libxml_clear_errors();

        if (empty($schemas)) {
            return;
        }

        $this->schemaCache[$cacheKey] = $schemas;
        $this->injectJsonLd($schemas, $body, $app);
    }

    /**
     * Build schema for specific type.
     */
    private function buildSchemaForType(string $type, DOMElement $element, DOMXPath $xpath): array
    {
        return match ($type) {
            'faq' => $this->buildFaqSchema($element, $xpath),
            'localbusiness' => $this->buildLocalBusinessSchema($this->getApplication() ?? Factory::getApplication()),
            default => [],
        };
    }

    /**
     * Build FAQ schema.org JSON-LD from uk-accordion markup.
     */
    private function buildFaqSchema(DOMElement $element, DOMXPath $xpath): array
    {
        $faqs = [];

        $accordion = $this->locateAccordionContainer($element, $xpath);

        if (!$accordion) {
            return [];
        }

        $items = $this->locateAccordionItems($accordion, $xpath);

        foreach ($items as $item) {
            $titleNode = $this->queryFirstByClass($xpath, $item, 'uk-accordion-title')
                ?? $this->queryFirstByClass($xpath, $item, 'el-title');
            $contentNode = $this->queryFirstByClass($xpath, $item, 'uk-accordion-content')
                ?? $this->queryFirstByClass($xpath, $item, 'el-content');

            $question = $titleNode ? trim($titleNode->textContent) : '';
            $answer   = $contentNode ? trim($this->getInnerHTML($contentNode)) : '';

            if ($question === '' || $answer === '') {
                Log::add('Schema plugin: Skipping FAQ item with missing title or content.', Log::WARNING, 'plg_system_schema');
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
    private function buildLocalBusinessSchema(CMSApplicationInterface $app): array
    {
        $name        = trim((string) $this->params->get('business_name'));
        $logo        = trim((string) $this->params->get('business_logo'));
        $description = trim((string) $this->params->get('business_description'));

        if ($name === '' || $description === '') {
            Log::add('Schema plugin: LocalBusiness requires name and description.', Log::WARNING, 'plg_system_schema');
            return [];
        }

        $address = array_filter([
            'streetAddress'   => trim((string) $this->params->get('business_street')),
            'postalCode'      => trim((string) $this->params->get('business_postal')),
            'addressLocality' => trim((string) $this->params->get('business_city')),
            'addressCountry'  => trim((string) $this->params->get('business_country')),
        ]);

        $openingHours = [];
        for ($i = 1; $i <= 7; $i++) {
            $hours = trim((string) $this->params->get('opening_hours_' . $i));
            if ($hours !== '') {
                $openingHours[] = $hours;
            }
        }

        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'LocalBusiness',
            'name'        => $name,
            'description' => $description,
            'url'         => $app->getUri()->toString(),
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

        return $schema;
    }

    /**
     * Inject the JSON-LD payload into the document body.
     */
    private function injectJsonLd(array $schemas, string $body, CMSApplicationInterface $app): void
    {
        if (empty($schemas)) {
            return;
        }

        $jsonLd = json_encode($schemas, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($jsonLd === false) {
            Log::add('Schema plugin: Failed to encode JSON-LD.', Log::ERROR, 'plg_system_schema');
            return;
        }

        $scriptTag = '<script type="application/ld+json">' . $jsonLd . '</script>';

        if (stripos($body, '</head>') !== false) {
            $body = preg_replace('/<\/head>/i', $scriptTag . "\n</head>", $body, 1);
        } elseif (stripos($body, '</body>') !== false) {
            $body = preg_replace('/<\/body>/i', $scriptTag . "\n</body>", $body, 1);
        } else {
            $body .= $scriptTag;
        }

        $app->setBody($body);
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
