<?php

namespace App\Tests\Service;

use App\Service\Import\MapLinkCoordinates;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MapLinkCoordinatesTest extends TestCase
{
    public function testReadsThePinThenThePlacemarkThenTheMapCentre(): void
    {
        $links = new MapLinkCoordinates(new MockHttpClient());

        self::assertSame(['51.8413100', '107.6278560'], $links->resolve('https://yandex.ru/maps/198/ulan-ude/?ll=107.628476%2C51.841500&mode=whatshere&whatshere%5Bpoint%5D=107.627856%2C51.841310&whatshere%5Bzoom%5D=18.12&z=18'));
        self::assertSame(['51.2875280', '106.5338330'], $links->resolve('https://yandex.ru/maps/?ll=106.5%2C51.2&pt=106.533833,51.287528,pm2rdm~106.6,51.3&z=16'));
        self::assertSame(['51.8352590', '107.5126910'], $links->resolve('https://yandex.ru/maps/198/ulan-ude/?l=sat%2Cskl&ll=107.512691%2C51.835259&z=17'));
        self::assertNull($links->resolve('https://yandex.ru/maps/198/ulan-ude/'));
        self::assertNull($links->resolve('https://yandex.ru/maps/?ll=300,95'));
    }

    public function testFollowsShortLinksAndAsksOncePerLink(): void
    {
        $requests = 0;
        $client = new MockHttpClient(static function (string $method, string $url) use (&$requests) {
            ++$requests;

            return match ($url) {
                'https://yandex.ru/maps/-/CLbQv4Oa' => new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: /maps/?um=constructor']]),
                'https://yandex.ru/maps/?um=constructor' => new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://yandex.ru/maps/?ll=107.605221%2C51.800583&mode=whatshere&whatshere%5Bpoint%5D=107.605221%2C51.800583&z=17']]),
                default => new MockResponse('captcha', ['http_code' => 200]),
            };
        });
        $links = new MapLinkCoordinates($client);

        self::assertSame(['51.8005830', '107.6052210'], $links->resolve('https://yandex.ru/maps/-/CLbQv4Oa'));
        self::assertSame(['51.8005830', '107.6052210'], $links->resolve('https://yandex.ru/maps/-/CLbQv4Oa'));
        self::assertSame(2, $requests);

        self::assertNull($links->resolve('https://yandex.ru/maps/-/Broken'));
        self::assertNull($links->resolve('https://example.com/maps/-/CLbQv4Oa')); // not a Yandex link: no request
        self::assertSame(3, $requests);
    }
}
