<?php

declare(strict_types=1);

namespace TMSWP\Thumbnail\Tests\Integration;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use TMSWP\Testing\IntegrationTestCase;
use TMSWP\Testing\UsesTempDirectories;
use TMSWP\Testing\WpCliPharDeprecations;
use TMSWP\Thumbnail\ThumbCache;

/**
 * `wp tmswp thumb clear`・`wp tmswp thumb status`を、本物のWP-CLIのプロセスで確かめる。
 *
 * fixtureのテーマとプラグインのKernelはthumbnailを登録していない。そこでテストごとに、一時ディレクトリにWP-CLIの
 * --requireのファイルを書き、そのプロセスの中だけ、Thumbnailを登録したプラグインのKernel(ルートは一時ディレクトリ)を
 * muplugins_loadedでbootする。uploadsは、同じ--requireのファイルが置くupload_dirのフィルタで一時ディレクトリに向ける
 * (開発用サイトのwp-content/uploadsに触れない)。WP-CLIのプロセスは開発用サイトのDB(wp_のテーブル)で動くが、
 * このテストのKernelとコマンドはDBに書かない。fixtureのテーマとプラグインは、そのプロセスでは有効にしない。
 */
final class ThumbCommandIntegrationTest extends IntegrationTestCase
{
    use UsesTempDirectories;

    private const int DAY = 86400;

    private const string APP_NAMESPACE = 'TmswpThumbCommandIntegration';

    private const string NOT_POSITIVE_INTEGER = 'wp tmswp thumb clear --days must be a positive integer';

    private string $uploads = '';

    private string $cacheDir = '';

    public function set_up(): void
    {
        parent::set_up();
        $this->uploads = $this->createTempDirectory('thumb-command-uploads');
        $this->cacheDir = $this->uploads . '/tmswp-thumb';
    }

    public function tear_down(): void
    {
        $this->cleanupTempDirectories();
        parent::tear_down();
    }

    /**
     * docs/08の「`wp tmswp thumb clear --days=30`が、更新日時が30日より前のファイルだけを削除し、`index.html`を残す」。
     * 41日前のサムネイルと失敗の印を消し、29日前のサムネイルと、41日前にしたindex.html・印のファイルを残す。
     */
    public function testClearWithThirtyDaysDeletesOnlyOlderFilesAndKeepsIndexHtml(): void
    {
        $old = self::key('aa', '0');
        $recent = self::key('aa', '1');
        $oldFailed = self::key('cc', '0');
        $this->createCache([$old, $recent, $oldFailed]);
        $this->writeCacheFile("aa/{$old}.jpg", 'old', 41);
        $this->writeCacheFile("aa/{$recent}.jpg", 'recent', 29);
        $this->writeCacheFile("cc/{$oldFailed}.failed", '', 41);

        foreach (['index.html', 'aa/index.html', 'cc/index.html', ThumbCache::MARKER] as $file) {
            self::assertTrue(touch($this->cacheDir . '/' . $file, time() - 41 * self::DAY));
        }

        $result = $this->wpThumb(['clear', '--days=30']);

        self::assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
        self::assertSame("Success: deleted 2 thumbnail cache file(s)\n", $result['stdout']);
        self::assertSame(
            [ThumbCache::MARKER, "aa/{$recent}.jpg", 'aa/index.html', 'cc/index.html', 'index.html'],
            array_keys($this->cacheFiles()),
        );
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: int, 2: string}>
     */
    public static function invalidDays(): iterable
    {
        $maxDays = ThumbCache::maxOlderThanDays();

        yield 'zero' => [['--days=0'], 3600, self::NOT_POSITIVE_INTEGER];
        yield 'negative' => [['--days=-1'], 3600, self::NOT_POSITIVE_INTEGER];
        yield 'not an integer' => [['--days=1.5'], 3600, self::NOT_POSITIVE_INTEGER];
        yield 'not a number' => [['--days=abc'], 3600, self::NOT_POSITIVE_INTEGER];
        yield 'without a value' => [['--days'], 3600, self::NOT_POSITIVE_INTEGER];
        yield 'over the maximum' => [['--days=' . ($maxDays + 1)], 3600, "wp tmswp thumb clear --days must be at most {$maxDays}"];
        yield 'not longer than redirectMaxAge' => [
            ['--days=30'],
            30 * self::DAY,
            'wp tmswp thumb clear --days must be longer than the cache lifetime of the REST redirects (2592000 seconds); a cached redirect could point to a deleted file',
        ];
    }

    /**
     * docs/08の「`--days`の0・負・整数でない値・値の無い`--days`・上限(`intdiv(PHP_INT_MAX, 86400)`日)を超える日数・
     * `redirectMaxAge`以下の日数が、何も消さずに`Error:`と終了コード1になる(`WP_CLI::error()`)」。
     * どの`--days`より古いファイルを置き、実行の前後でキャッシュのディレクトリのファイル・更新日時・大きさが変わらない
     * ことを確かめる。PHPのFatal errorやWordPressのcritical errorの文言を出さない。
     *
     * @dataProvider invalidDays
     *
     * @param list<string> $days
     */
    public function testClearRejectsInvalidDaysWithErrorAndDeletesNothing(array $days, int $redirectMaxAge, string $message): void
    {
        $thumbnail = self::key('aa', '0');
        $failed = self::key('cc', '0');
        $this->createCache([$thumbnail, $failed]);
        $this->writeCacheFile("aa/{$thumbnail}.jpg", 'old', 100);
        $this->writeCacheFile("cc/{$failed}.failed", '', 100);
        $before = $this->cacheFiles();

        $result = $this->wpThumb(['clear', ...$days], $redirectMaxAge);

        self::assertSame(1, $result['code'], $result['stdout'] . $result['stderr']);
        self::assertSame("Error: {$message}\n", $result['stderr']);
        self::assertSame('', $result['stdout']);
        self::assertSame($before, $this->cacheFiles());
    }

    /**
     * docs/08の「`wp tmswp thumb status`が`index.html`・ロック・一時ファイルを数えない」。サムネイル2つ(3バイトと5バイト)と
     * 失敗の印1つのほかに、index.html・印のファイル・ロック・一時ファイル(7バイト)を置き、サムネイルと失敗の印だけを数える。
     */
    public function testStatusCountsNeitherIndexHtmlNorLockNorTemporaryFiles(): void
    {
        $jpg = self::key('aa', '0');
        $webp = self::key('cc', '0');
        $failed = self::key('aa', '1');
        $cache = $this->createCache([$jpg, $webp, $failed]);
        $this->writeCacheFile("aa/{$jpg}.jpg", '123', 0);
        $this->writeCacheFile("cc/{$webp}.webp", '12345', 0);
        $cache->markFailed($failed);
        $this->writeCacheFile("aa/{$jpg}.lock", '', 0);
        self::assertNotFalse(file_put_contents($cache->temporaryPath($jpg, 'jpg'), '1234567'));
        self::assertCount(9, $this->cacheFiles());

        $result = $this->wpThumb(['status']);

        self::assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
        self::assertSame("files: 2\nbytes: 8\nfailed: 1\n", $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    /** shard(先頭2文字)に続けて、1文字を38回繰り返した40桁のキー。 */
    private static function key(string $shard, string $fill): string
    {
        return $shard . str_repeat($fill, 38);
    }

    /**
     * キャッシュのディレクトリ(印のファイルとindex.html)と、キーのshardのディレクトリ(index.html)を、ThumbCacheで作る。
     *
     * @param list<string> $keys
     */
    private function createCache(array $keys): ThumbCache
    {
        $cache = new ThumbCache($this->cacheDir, 'http://example.org/tmswp-thumb');

        foreach ($keys as $key) {
            $cache->ensureDirectory($key);
        }

        return $cache;
    }

    /** キャッシュのディレクトリの下にファイルを書き、更新日時を指定の日数前にする。 */
    private function writeCacheFile(string $relative, string $contents, int $daysAgo): void
    {
        $path = $this->cacheDir . '/' . $relative;
        self::assertNotFalse(file_put_contents($path, $contents));
        self::assertTrue(touch($path, time() - $daysAgo * self::DAY));
    }

    /**
     * キャッシュのディレクトリの下のファイル(相対パスの昇順)と、その更新日時・大きさ。
     *
     * @return array<string, string>
     */
    private function cacheFiles(): array
    {
        clearstatcache();
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->cacheDir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[substr($file->getPathname(), \strlen($this->cacheDir) + 1)] = $file->getMTime() . ':' . $file->getSize();
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * `wp tmswp thumb ...`を、Thumbnailを登録したKernelのもとで、本物のWP-CLIのプロセスで実行する。
     *
     * @param list<string> $arguments `tmswp thumb`に続く引数
     *
     * @return array{code: int, stdout: string, stderr: string, removedDeprecations: int}
     */
    private function wpThumb(array $arguments, int $redirectMaxAge = 3600): array
    {
        $require = $this->writeRequireFile($redirectMaxAge);

        return self::runProcess(['wp', '--path=' . ABSPATH, '--require=' . $require, 'tmswp', 'thumb', ...$arguments], self::repositoryRoot());
    }

    /**
     * WP-CLIの--requireのファイルを一時ディレクトリに書き、そのパスを返す。
     *
     * Composerのオートローダは、muplugins_loadedで読み込む(WP-CLIのpharが--requireの後に自身の古いpsr/containerの
     * オートローダを先頭に登録するため。tests/fixtures/_wp-cli.phpと同じ理由)。Kernelは、その後のmuplugins_loadedで、
     * 一時ディレクトリのプラグインのKernel(debug)として、Thumbnailをextensionsに渡してbootする。
     */
    private function writeRequireFile(int $redirectMaxAge): string
    {
        $root = $this->createTempDirectory('thumb-command-plugin');
        self::assertNotFalse(file_put_contents($root . '/plugin.php', "<?php\n"));
        self::assertTrue(mkdir($root . '/app'));
        $baseUrl = 'http://example.org/tmswp-thumb-command-uploads';
        $code = <<<'PHP'
            <?php

            declare(strict_types=1);

            WP_CLI::add_wp_hook('muplugins_loaded', static function (): void {
                require %1$s;
            }, 0);
            WP_CLI::add_wp_hook('upload_dir', static fn(array $dir): array => [
                ...$dir,
                'path' => %2$s,
                'url' => %3$s,
                'subdir' => '',
                'basedir' => %2$s,
                'baseurl' => %3$s,
            ]);
            WP_CLI::add_wp_hook('muplugins_loaded', static function (): void {
                TMSWP\Core\Kernel\Kernel::boot(TMSWP\Core\Kernel\KernelConfig::plugin(
                    %4$s,
                    %5$s,
                    %6$s,
                    true,
                    [new TMSWP\Thumbnail\Thumbnail(new TMSWP\Thumbnail\ThumbnailConfig(redirectMaxAge: %7$d))],
                ));
            }, 1);

            PHP;
        $require = $root . '/wp-cli-require.php';
        self::assertNotFalse(file_put_contents($require, \sprintf(
            $code,
            var_export(self::repositoryRoot() . '/vendor/autoload.php', true),
            var_export($this->uploads, true),
            var_export($baseUrl, true),
            var_export($root . '/plugin.php', true),
            var_export($root . '/app', true),
            var_export(self::APP_NAMESPACE, true),
            $redirectMaxAge,
        )));

        return $require;
    }

    private static function repositoryRoot(): string
    {
        return \dirname(__DIR__, 4);
    }

    /**
     * @param list<string> $command
     *
     * @return array{code: int, stdout: string, stderr: string, removedDeprecations: int}
     */
    private static function runProcess(array $command, string $cwd): array
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, getenv());

        if (!\is_resource($process)) {
            throw new RuntimeException('cannot start ' . implode(' ', $command));
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        // PHP 8.5でWP-CLI 2.12.0のphar自身が出す非推奨の行だけを除く(WpCliPharDeprecations。WP-CLIが対応したら外す)
        $stripped = WpCliPharDeprecations::strip($stderr);

        return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stripped['output'], 'removedDeprecations' => $stripped['removed']];
    }
}
