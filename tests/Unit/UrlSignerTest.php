<?php

declare(strict_types=1);

namespace TMSWP\Thumbnail\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Testing\UnitTestCase;
use TMSWP\Thumbnail\UrlSigner;

/**
 * URLの署名の対象(区切りのあいまいさが無い)と、鍵の導出。
 */
final class UrlSignerTest extends UnitTestCase
{
    /**
     * `sign('id:1', 50, 40, '')`の署名に対して、受け取ったままの値で検証する。どの値が1文字違っても不合格。
     *
     * @return iterable<string, array{string, string, string, string, bool}>
     */
    public static function verifiedValues(): iterable
    {
        yield 'same values' => ['id:1', '50', '40', '', true];
        yield 'width with a leading zero' => ['id:1', '050', '40', '', false];
        yield 'other source' => ['id:2', '50', '40', '', false];
        yield 'other width' => ['id:1', '51', '40', '', false];
        yield 'other height' => ['id:1', '50', '41', '', false];
        yield 'other options' => ['id:1', '50', '40', 'crop', false];
    }

    #[DataProvider('verifiedValues')]
    public function testVerifyUsesRawPayloadValues(string $src, string $width, string $height, string $options, bool $valid): void
    {
        $signer = new UrlSigner(UrlSigner::deriveKey('secret'));
        $signature = $signer->sign('id:1', 50, 40, '');

        self::assertSame($valid, $signer->verify($src, $width, $height, $options, $signature));
    }

    /**
     * 値の中の`|`で、別の組み合わせと同じ署名の対象にならない(旧来の`{src}|{w}|{h}|{o}`では同じになった)。
     * `sign('a|1', 2, 3, '')`の署名に対する検証。
     *
     * @return iterable<string, array{string, string, string, string, bool}>
     */
    public static function valuesContainingTheOldSeparator(): iterable
    {
        yield 'same values' => ['a|1', '2', '3', '', true];
        yield 'separator moved to the width' => ['a', '1|2', '3', '', false];
        yield 'separator moved to the options' => ['a', '1', '2', '3|', false];
    }

    #[DataProvider('valuesContainingTheOldSeparator')]
    public function testPayloadIsNotAmbiguous(string $src, string $width, string $height, string $options, bool $valid): void
    {
        $signer = new UrlSigner(UrlSigner::deriveKey('secret'));
        $signature = $signer->sign('a|1', 2, 3, '');

        self::assertSame($valid, $signer->verify($src, $width, $height, $options, $signature));
    }

    /** 鍵は秘密の値そのものではなく、用途を入れて導出したもの。同じ秘密の値からは同じ鍵になる。 */
    public function testDeriveKeySeparatesTheKeyFromTheSecret(): void
    {
        $key = UrlSigner::deriveKey('secret');

        self::assertSame($key, UrlSigner::deriveKey('secret'));
        self::assertNotSame('secret', $key);
        self::assertNotSame($key, UrlSigner::deriveKey('other secret'));
        self::assertNotSame(
            (new UrlSigner('secret'))->sign('id:1', 1, 1, ''),
            (new UrlSigner($key))->sign('id:1', 1, 1, ''),
        );
    }
}
