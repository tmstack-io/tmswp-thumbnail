<?php

declare(strict_types=1);

namespace TMSWP\Thumbnail;

use TMSWP\Core\Exception\InvalidArgumentException;

/**
 * thumbnailの設定。`new Thumbnail(new ThumbnailConfig(...))`で渡す。
 */
final readonly class ThumbnailConfig
{
    /**
     * WordPressの関数は呼ばない(tmswp-kernel.phpの中で生成されるため)。
     *
     * @param int $maxDimension 幅・高さの上限(px。1以上)。WordPressのbig_imageの閾値に合わせる
     * @param int $defaultQuality `quality`を指定しないときの品質(1〜100)
     * @param bool $defaultCrop `crop`を指定しないときに切り抜くか
     * @param string $cacheDirName uploadsの直下のキャッシュのディレクトリ名。英数字で始まり、英数字・`.`・`_`・`-`だけの
     *                             1つの名前(パスの区切り・`..`を含まない)
     * @param int $redirectMaxAge REST経由の302(生成済みのファイルへのリダイレクト)をキャッシュさせる秒数(1以上)。
     *                            `wp tmswp thumb clear --days`の日数は、この秒数より長くなければならない
     *
     * @throws InvalidArgumentException いずれかの値が範囲・形の外の場合
     */
    public function __construct(
        public int $maxDimension = 2560,
        public int $defaultQuality = 82,
        public bool $defaultCrop = true,
        public string $cacheDirName = 'tmswp-thumb',
        public int $redirectMaxAge = 3600,
    ) {
        if ($maxDimension < 1) {
            throw new InvalidArgumentException("ThumbnailConfig maxDimension must be a positive number of pixels, {$maxDimension} given");
        }

        if ($defaultQuality < 1 || $defaultQuality > 100) {
            throw new InvalidArgumentException("ThumbnailConfig defaultQuality must be an integer from 1 to 100, {$defaultQuality} given");
        }

        // 英数字で始まるため、`.`・`..`・空文字・隠しファイルの名前にもならない
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $cacheDirName) !== 1) {
            throw new InvalidArgumentException("ThumbnailConfig cacheDirName must be a single directory name of letters, digits, '.', '_' and '-' starting with a letter or digit, '{$cacheDirName}' given");
        }

        if ($redirectMaxAge < 1) {
            throw new InvalidArgumentException("ThumbnailConfig redirectMaxAge must be a positive number of seconds, {$redirectMaxAge} given");
        }
    }
}
