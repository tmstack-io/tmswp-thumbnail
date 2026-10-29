<?php

declare(strict_types=1);

namespace TMSWP\Thumbnail\Cli;

use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Exception\RuntimeException;
use TMSWP\Thumbnail\ThumbCache;
use TMSWP\Thumbnail\Thumbnail;
use WP_CLI;

/**
 * Manages the thumbnail cache of TMSWP.
 *
 * `wp tmswp thumb clear`・`wp tmswp thumb status`。Thumbnail::register()が、WP-CLIの環境で登録する。
 */
final class ThumbCommand
{
    private const int DAY_IN_SECONDS = 86400;

    public function __construct(private readonly Thumbnail $thumbnail) {}

    /**
     * Deletes thumbnail cache files.
     *
     * ## OPTIONS
     *
     * [--days[=<days>]]
     * : Delete only the files that were last returned through the REST API (or generated) more than this many days ago. A positive integer whose length in seconds exceeds the cache lifetime of the REST redirects (redirectMaxAge).
     *
     * ## EXAMPLES
     *
     *     wp tmswp thumb clear
     *     wp tmswp thumb clear --days=30
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     *
     * @throws RuntimeException WP-CLIの外で呼んだ場合、消せないファイルがあった場合
     */
    public function clear(array $args, array $assocArgs): void
    {
        if (!class_exists('WP_CLI')) {
            throw new RuntimeException('WP_CLI is not available');
        }

        try {
            $days = $this->days($assocArgs);
        } catch (InvalidArgumentException $exception) {
            // --daysの誤りは利用者の入力の誤りなので、何も消さずにWP-CLIのエラー(終了コード1)で知らせる。
            // WP_CLI::error()は終了するためreturnには進まないが、PHPStanはWP_CLIの型を知らないため、$daysが未定義のまま
            // 先へ進まないことをreturnで示す
            WP_CLI::error($exception->getMessage());

            return;
        }

        $deleted = $this->thumbnail->cache()->clear($days);
        WP_CLI::success("deleted {$deleted} thumbnail cache file(s)");
    }

    /**
     * Shows the number and total size of the generated thumbnails, and the number of sources that failed to be processed.
     *
     * ## EXAMPLES
     *
     *     wp tmswp thumb status
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     *
     * @throws RuntimeException WP-CLIの外で呼んだ場合、キャッシュのディレクトリを読めない場合
     */
    public function status(array $args, array $assocArgs): void
    {
        if (!class_exists('WP_CLI')) {
            throw new RuntimeException('WP_CLI is not available');
        }

        $status = $this->thumbnail->cache()->status();
        WP_CLI::line("files: {$status['files']}");
        WP_CLI::line("bytes: {$status['bytes']}");
        WP_CLI::line("failed: {$status['failed']}");
    }

    /**
     * @param array<string, mixed> $assocArgs
     *
     * @throws InvalidArgumentException --daysが正の整数でない場合、秒に換算するとintに収まらない場合、302のキャッシュの
     *                                  期間以下の場合
     */
    private function days(array $assocArgs): ?int
    {
        if (!\array_key_exists('days', $assocArgs)) {
            return null;
        }

        $value = $assocArgs['days'];

        if (!\is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            throw new InvalidArgumentException('wp tmswp thumb clear --days must be a positive integer');
        }

        // ThumbCache::clear()に渡せる日数(秒に換算してintに収まる日数)に限る。PHP_INT_MAXを超える文字列は(int)で
        // 丸まるため、変換と範囲の判定をfilter_var()でまとめて行う
        $maxDays = ThumbCache::maxOlderThanDays();
        $days = filter_var($value, \FILTER_VALIDATE_INT, ['options' => ['max_range' => $maxDays]]);

        if (!\is_int($days)) {
            throw new InvalidArgumentException("wp tmswp thumb clear --days must be at most {$maxDays}");
        }

        $redirectMaxAge = $this->thumbnail->config()->redirectMaxAge;

        if ($days * self::DAY_IN_SECONDS <= $redirectMaxAge) {
            throw new InvalidArgumentException("wp tmswp thumb clear --days must be longer than the cache lifetime of the REST redirects ({$redirectMaxAge} seconds); a cached redirect could point to a deleted file");
        }

        return $days;
    }
}
