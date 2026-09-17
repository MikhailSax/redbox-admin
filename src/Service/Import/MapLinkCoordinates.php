<?php

namespace App\Service\Import;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Coordinates of a structure from its Yandex Maps link in the address programme.
 *
 * Full links carry the point in the query: "whatshere[point]" (the pin), "pt" (a placemark) or, failing those,
 * "ll" (the map centre); all are "longitude,latitude". Short links (yandex.ru/maps/-/CLbQv4Oa) are followed
 * redirect by redirect until a full link shows up.
 */
class MapLinkCoordinates
{
    private const MAX_REDIRECTS = 5;

    /** @var array<string, array{0: string, 1: string}|null> url => [latitude, longitude] */
    private array $resolved = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return array{0: string, 1: string}|null [latitude, longitude] with 7 decimals; null when the link has no point
     *                                          or a short link could not be followed
     */
    public function resolve(string $url): ?array
    {
        return \array_key_exists($url, $this->resolved)
            ? $this->resolved[$url]
            : $this->resolved[$url] = self::fromUrl($url) ?? $this->followShortLink($url);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    public static function fromUrl(string $url): ?array
    {
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        $candidates = [
            $query['whatshere']['point'] ?? null,
            // "pt" may list several placemarks ("lon,lat,style~lon,lat"): the first one is taken
            \is_string($query['pt'] ?? null) ? explode('~', $query['pt'])[0] : null,
            $query['ll'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (!\is_string($candidate) || !preg_match('/^\s*(-?\d{1,3}(?:\.\d+)?)\s*,\s*(-?\d{1,2}(?:\.\d+)?)/', $candidate, $m)) {
                continue;
            }
            [$longitude, $latitude] = [(float) $m[1], (float) $m[2]];
            if (abs($latitude) <= 90 && abs($longitude) <= 180) {
                return [number_format($latitude, 7, '.', ''), number_format($longitude, 7, '.', '')];
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function followShortLink(string $url): ?array
    {
        if (!preg_match('~^https?://(?:www\.)?yandex\.[a-z]+/maps/-/~i', $url)) {
            return null;
        }

        try {
            for ($i = 0; $i < self::MAX_REDIRECTS; ++$i) {
                $response = $this->httpClient->request('GET', $url, ['max_redirects' => 0, 'timeout' => 15]);
                $location = $response->getHeaders(false)['location'][0] ?? null;
                $response->cancel();
                if (null === $location) {
                    return null;
                }
                $url = str_starts_with($location, '/') ? 'https://yandex.ru'.$location : $location;
                if (null !== ($coordinates = self::fromUrl($url))) {
                    return $coordinates;
                }
            }
        } catch (ExceptionInterface) {
            return null;
        }

        return null;
    }
}
