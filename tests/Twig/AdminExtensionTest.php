<?php

namespace App\Tests\Twig;

use App\Twig\AdminExtension;
use PHPUnit\Framework\TestCase;

final class AdminExtensionTest extends TestCase
{
    public function testHighlightEscapesAndMarksCaseInsensitively(): void
    {
        self::assertSame('Щит на <mark class="hl">Лен</mark>ина', AdminExtension::highlight('Щит на Ленина', 'лен'));
        self::assertSame('&lt;b&gt;<mark class="hl">x</mark>&lt;/b&gt;', AdminExtension::highlight('<b>x</b>', 'x'));
        self::assertSame('a.b', AdminExtension::highlight('a.b', ''));
        self::assertSame('<mark class="hl">a.b</mark>', AdminExtension::highlight('a.b', 'a.b'), 'regex characters are literal');
        self::assertSame('', AdminExtension::highlight(null, 'x'));
    }

    public function testInitials(): void
    {
        self::assertSame('ИП', AdminExtension::initials('Иван Петров'));
        self::assertSame('МR', AdminExtension::initials('мария redbox'));
        self::assertSame('MR', AdminExtension::initials('maria@redbox.local'));
        self::assertSame('?', AdminExtension::initials(''));
    }
}
