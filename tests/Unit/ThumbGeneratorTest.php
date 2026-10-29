<?php

declare(strict_types=1);

namespace TMSWP\Thumbnail\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Testing\UnitTestCase;
use TMSWP\Thumbnail\ThumbGenerator;

/**
 * リサイズの寸法の決め方(元画像の寸法での頭打ち)。
 */
final class ThumbGeneratorTest extends UnitTestCase
{
    /**
     * 元画像は400x300。
     *
     * @return iterable<string, array{0: int, 1: int, 2: bool, 3: array{0: int, 1: int}|null}>
     */
    public static function fitProvider(): iterable
    {
        yield '切り抜き・元画像より小さい' => [100, 100, true, [100, 100]];
        yield '切り抜き・両方が元画像より大きい(縦横比を保って縮める)' => [800, 600, true, null];
        yield '切り抜き・縦横比の違う拡大' => [800, 200, true, [400, 100]];
        yield '切り抜き・片方だけ大きい' => [600, 150, true, [400, 100]];
        yield '切り抜き・幅だけ指定で元画像より大きい' => [1000, 0, true, null];
        yield '切り抜かない・枠が元画像より大きい' => [1000, 1000, false, null];
        yield '切り抜かない・幅だけ縮める' => [200, 0, false, [200, 0]];
        yield '切り抜かない・高さだけ大きい枠' => [200, 1000, false, [200, 1000]];
        yield '切り抜かない・1px以内の差' => [399, 0, false, null];
        yield '高さだけ指定・元画像と同じ' => [0, 300, false, null];
    }

    /**
     * @param array{0: int, 1: int}|null $expected
     */
    #[DataProvider('fitProvider')]
    public function testFitCapsAtSourceSize(int $width, int $height, bool $crop, ?array $expected): void
    {
        self::assertSame($expected, ThumbGenerator::fit($width, $height, 400, 300, $crop));
    }
}
