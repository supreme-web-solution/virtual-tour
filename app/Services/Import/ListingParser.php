<?php

namespace App\Services\Import;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Str;

/**
 * Pulls what a listing page publicly exposes: title, description, schema.org
 * data, Open Graph tags, candidate photos and the visible text (for the AI).
 */
class ListingParser
{
    /**
     * Signals that a page is a bot challenge rather than the listing.
     */
    public const CHALLENGE_MARKERS = ['cf-chl', 'challenge-platform', 'g-recaptcha', 'h-captcha', 'hcaptcha.com', 'captcha-delivery', 'are you a robot', 'verify you are human'];

    /**
     * @return array{title: string|null, description: string|null, structured: array<string, mixed>, images: list<array{url: string, alt: string|null}>, text: string, is_challenge: bool}
     */
    public function parse(string $html, string $baseUrl): array
    {
        $lower = strtolower(Str::limit($html, 200_000, ''));
        $isChallenge = collect(self::CHALLENGE_MARKERS)->contains(fn (string $marker): bool => str_contains($lower, $marker));

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $meta = fn (string $query): ?string => $this->clean($xpath->evaluate("string({$query})"));

        $structured = $this->structuredData($xpath);
        $images = [];

        foreach ([$meta('//meta[@property="og:image"]/@content'), $meta('//meta[@name="twitter:image"]/@content')] as $url) {
            $this->addImage($images, $url, null, $baseUrl);
        }

        foreach ((array) ($structured['images'] ?? []) as $url) {
            $this->addImage($images, is_string($url) ? $url : null, null, $baseUrl);
        }

        /** @var DOMElement $img */
        foreach ($xpath->query('//img|//source') ?: [] as $img) {
            $alt = $this->clean($img->getAttribute('alt'));
            $srcset = $img->getAttribute('srcset') ?: $img->getAttribute('data-srcset');

            if ($srcset !== '') {
                $this->addImage($images, $this->largestFromSrcset($srcset), $alt, $baseUrl);
            }

            foreach (['data-src', 'data-lazy-src', 'data-original', 'data-full', 'src'] as $attribute) {
                if ($img->getAttribute($attribute) !== '') {
                    $this->addImage($images, $img->getAttribute($attribute), $alt, $baseUrl);

                    break;
                }
            }
        }

        return [
            'title' => $structured['name'] ?? $meta('//meta[@property="og:title"]/@content') ?? $meta('//title'),
            'description' => $structured['description'] ?? $meta('//meta[@property="og:description"]/@content') ?? $meta('//meta[@name="description"]/@content'),
            'structured' => collect($structured)->except('images')->all(),
            'images' => array_slice(array_values($images), 0, (int) config('import.max_candidates', 60)),
            'text' => $this->visibleText($xpath),
            'is_challenge' => $isChallenge,
        ];
    }

    /**
     * Listing facts from JSON-LD (schema.org), when the site provides them.
     *
     * @return array<string, mixed>
     */
    protected function structuredData(DOMXPath $xpath): array
    {
        $result = [];

        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $script) {
            if (! $script instanceof DOMElement) {
                continue;
            }

            $data = json_decode($script->textContent, true);

            if (! is_array($data)) {
                continue;
            }

            $nodes = array_is_list($data) ? $data : ($data['@graph'] ?? [$data]);

            foreach ((array) $nodes as $node) {
                if (! is_array($node)) {
                    continue;
                }

                $item = $node['mainEntity'] ?? $node['itemOffered'] ?? $node;
                $item = is_array($item) ? $item : $node;
                $offer = is_array($node['offers'] ?? null) ? $node['offers'] : (is_array($item['offers'] ?? null) ? $item['offers'] : []);
                $offer = array_is_list($offer) ? ($offer[0] ?? []) : $offer;
                $address = is_array($item['address'] ?? null) ? $item['address'] : [];
                $geo = is_array($item['geo'] ?? null) ? $item['geo'] : [];
                $floor = $item['floorSize'] ?? null;

                $result += array_filter([
                    'name' => $this->clean($item['name'] ?? $node['name'] ?? null),
                    'description' => $this->clean($item['description'] ?? $node['description'] ?? null),
                    'address' => $this->clean($address['streetAddress'] ?? null),
                    'city' => $this->clean($address['addressLocality'] ?? null),
                    'region' => $this->clean($address['addressRegion'] ?? null),
                    'country' => $this->clean(is_array($address['addressCountry'] ?? null) ? ($address['addressCountry']['name'] ?? null) : ($address['addressCountry'] ?? null)),
                    'latitude' => is_numeric($geo['latitude'] ?? null) ? (float) $geo['latitude'] : null,
                    'longitude' => is_numeric($geo['longitude'] ?? null) ? (float) $geo['longitude'] : null,
                    'bedrooms' => is_numeric($item['numberOfBedrooms'] ?? $item['numberOfRooms'] ?? null) ? (int) ($item['numberOfBedrooms'] ?? $item['numberOfRooms']) : null,
                    'bathrooms' => is_numeric($item['numberOfBathroomsTotal'] ?? null) ? (float) $item['numberOfBathroomsTotal'] : null,
                    'area' => is_array($floor) && is_numeric($floor['value'] ?? null) ? (float) $floor['value'] : null,
                    'price' => is_numeric($offer['price'] ?? null) ? (float) $offer['price'] : null,
                    'currency' => $this->clean($offer['priceCurrency'] ?? null),
                    'images' => $this->imageList($item['image'] ?? $node['image'] ?? null),
                ], fn ($value): bool => $value !== null && $value !== [] && $value !== '');
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    protected function imageList(mixed $value): array
    {
        $list = is_array($value) && ! array_is_list($value) ? [$value] : (array) $value;

        return array_values(array_filter(array_map(
            fn ($image): ?string => is_string($image) ? $image : (is_array($image) ? ($image['url'] ?? $image['contentUrl'] ?? null) : null),
            $list,
        )));
    }

    /**
     * @param  array<string, array{url: string, alt: string|null}>  $images
     */
    protected function addImage(array &$images, ?string $reference, ?string $alt, string $baseUrl): void
    {
        $url = $reference === null ? null : SafeHttpClient::absoluteUrl($reference, $baseUrl);

        if ($url === null || strlen($url) > 2000) {
            return;
        }

        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        // Icons, logos, sprites and vector graphics are never property photos.
        if (preg_match('/\.(svg|gif|ico)$/', $path) || preg_match('/(logo|icon|sprite|avatar|favicon|placeholder|pixel|badge|flag)/', $path)) {
            return;
        }

        $key = strtolower(Str::before($url, '?'));
        $images[$key] ??= ['url' => $url, 'alt' => $alt];
    }

    protected function largestFromSrcset(string $srcset): ?string
    {
        $best = null;
        $bestWidth = -1;

        foreach (explode(',', $srcset) as $candidate) {
            $pieces = preg_split('/\s+/', trim($candidate)) ?: [];
            $width = (int) rtrim($pieces[1] ?? '0', 'wx');

            if (($pieces[0] ?? '') !== '' && $width >= $bestWidth) {
                $best = $pieces[0];
                $bestWidth = $width;
            }
        }

        return $best;
    }

    protected function visibleText(DOMXPath $xpath): string
    {
        foreach ($xpath->query('//script|//style|//noscript|//svg|//nav|//footer|//header|//form|//iframe') ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $node->parentNode?->removeChild($node);
            }
        }

        $main = $this->firstElement($xpath, '//main|//article') ?? $this->firstElement($xpath, '//body');
        $text = preg_replace('/\s+/u', ' ', (string) $main?->textContent) ?? '';

        return Str::limit(trim($text), (int) config('import.max_text_chars', 12000), '');
    }

    protected function firstElement(DOMXPath $xpath, string $query): ?DOMElement
    {
        $nodes = $xpath->query($query);
        $node = $nodes === false ? null : $nodes->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    protected function clean(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5)) ?? '');

        return $value === '' ? null : Str::limit($value, 5000, '');
    }
}
