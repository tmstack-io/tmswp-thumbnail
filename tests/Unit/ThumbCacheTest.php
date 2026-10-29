<?php

declare(strict_types=1);

namespace TMSWP\Thumbnail\Tests\Unit;

use Brain\Monkey\Functions;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Exception\RuntimeException;
use TMSWP\Testing\UnitTestCase;
use TMSWP\Thumbnail\ThumbCache;
use TMSWP\Thumbnail\ThumbLock;

/**
 * キャッシュのキー・ディレクトリ・ロック・置き換え・GC・集計。
 */
final class ThumbCacheTest extends UnitTestCase
{
    private const string KEY = 'ab0123456789abcdef0123456789abcdef012345';

    /** KEYと同じshardの、別のキー */
    private const string OTHER_KEY = 'ab99999999999999999999999999999999999999';

    /** プロセス間のロックの試験で、各プロセスの応答を待つ秒数 */
    private const int WORKER_TIMEOUT = 15;

    public function testKeyIncludesSourceDimensionsAndEffectiveOptions(): void
    {
        $cache = new ThumbCache('/cache', 'https://example.test/cache');
        $key = $cache->key('a.jpg', 1, 100, 100, true, 82);

        self::assertMatchesRegularExpression('/\A[0-9a-f]{40}\z/', $key);
        self::assertNotSame($key, $cache->key('a.jpg', 2, 100, 100, true, 82));
        self::assertNotSame($key, $cache->key('a.jpg', 1, 100, 100, false, 82));
        // 既定値の変更(URLの`o`が空のまま)でも別のキーになる
        self::assertNotSame($key, $cache->key('a.jpg', 1, 100, 100, true, 70));
        self::assertNotSame($cache->key('a|1', 1, 1, 1, true, 82), $cache->key('a', 11, 1, 1, true, 82));
    }

    public function testEnsureDirectoryPlacesIndexHtmlInRootAndShard(): void
    {
        $base = $this->createTempDirectory('thumb-cache') . '/tmswp-thumb';
        $this->stubMkdir();

        (new ThumbCache($base, 'https://example.test/cache'))->ensureDirectory(self::KEY);

        self::assertFileExists($base . '/index.html');
        self::assertFileExists($base . '/ab/index.html');
    }

    /**
     * 初回の同時アクセスで、wp_mkdir_p()が有無を確かめた後に別のプロセスがディレクトリを作り、falseを返しても、
     * ディレクトリができていれば例外にしない(キャッシュのディレクトリとshardの両方)。
     */
    public function testEnsureDirectoryAcceptsDirectoryCreatedConcurrently(): void
    {
        $base = $this->createTempDirectory('thumb-uploads') . '/tmswp-thumb';
        $created = [];
        // 別のプロセスが先に作った後の、wp_mkdir_p()の@mkdir()の失敗にあたる
        Functions\when('wp_mkdir_p')->alias(static function (string $dir) use (&$created): bool {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $created[] = $dir;

            return false;
        });

        (new ThumbCache($base, 'https://example.test/cache'))->ensureDirectory(self::KEY);

        self::assertSame([$base, $base . '/ab'], $created);
        self::assertFileExists($base . '/' . ThumbCache::MARKER);
        self::assertFileExists($base . '/index.html');
        self::assertFileExists($base . '/ab/index.html');
    }

    public function testEnsureDirectoryThrowsWhenDirectoryCannotBeCreated(): void
    {
        Functions\when('wp_mkdir_p')->justReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('failed to create the thumbnail cache directory');

        (new ThumbCache($this->createTempDirectory('thumb-cache') . '/tmswp-thumb', 'https://example.test/cache'))->ensureDirectory(self::KEY);
    }

    /** 同じキーのロックは同時に1つだけ。手放すとロックのファイルを消し、また取れる。 */
    public function testLockIsExclusivePerKey(): void
    {
        $base = $this->createTempDirectory('thumb-cache');
        $this->stubMkdir();
        $cache = new ThumbCache($base, 'https://example.test/cache');
        $cache->ensureDirectory(self::KEY);

        $lock = $cache->lock(self::KEY);
        self::assertInstanceOf(ThumbLock::class, $lock);
        self::assertNull($cache->lock(self::KEY));

        $lock->release();
        self::assertFileDoesNotExist($base . '/ab/' . self::KEY . '.lock');

        $again = $cache->lock(self::KEY);
        self::assertInstanceOf(ThumbLock::class, $again);
        $again->release();
    }

    /** 置き換えは、書いたファイルの拡張子(出力形式)に従い、find()がそのファイルを返す。 */
    public function testCommitKeepsWrittenExtension(): void
    {
        $base = $this->createTempDirectory('thumb-cache');
        $this->stubMkdir();
        $cache = new ThumbCache($base, 'https://example.test/cache');
        $cache->ensureDirectory(self::KEY);
        $temporary = $cache->temporaryPath(self::KEY, 'jpg');
        $written = substr($temporary, 0, -3) . 'webp';
        self::assertNotFalse(file_put_contents($written, 'webp'));

        $path = $cache->commit($written, self::KEY);

        self::assertSame($base . '/ab/' . self::KEY . '.webp', $path);
        self::assertFileDoesNotExist($written);
        self::assertSame($path, $cache->find(self::KEY));
        self::assertSame('https://example.test/cache/ab/' . self::KEY . '.webp', $cache->url($path));
    }

    public function testCommitRejectsFileOutsideTheShard(): void
    {
        $base = $this->createTempDirectory('thumb-cache');
        $this->stubMkdir();
        $cache = new ThumbCache($base, 'https://example.test/cache');
        $cache->ensureDirectory(self::KEY);
        $outside = $base . '/elsewhere.jpg';
        self::assertNotFalse(file_put_contents($outside, 'jpeg'));

        try {
            $cache->commit($outside, self::KEY);
            self::fail('shardの外のファイルが拒まれませんでした。');
        } catch (RuntimeException) {
            self::assertFileDoesNotExist($outside);
            self::assertNull($cache->find(self::KEY));
        }
    }

    public function testFailureMarker(): void
    {
        $base = $this->createTempDirectory('thumb-cache');
        $this->stubMkdir();
        $cache = new ThumbCache($base, 'https://example.test/cache');
        $cache->ensureDirectory(self::KEY);

        self::assertFalse($cache->hasFailed(self::KEY));
        $cache->markFailed(self::KEY);
        self::assertTrue($cache->hasFailed(self::KEY));
        self::assertNull($cache->find(self::KEY));
    }

    /** GCは、更新日時が指定の日数より前のファイルだけを消し、index.htmlは残す。 */
    public function testClearDeletesOnlyOldFilesAndKeepsIndexHtml(): void
    {
        $base = $this->createTempDirectory('thumb-cache');
        $this->stubMkdir();
        $cache = new ThumbCache($base, 'https://example.test/cache');
        $cache->ensureDirectory(self::KEY);
        $old = $base . '/ab/' . self::KEY . '.jpg';
        $recent = $base . '/ab/' . self::OTHER_KEY . '.jpg';
        self::assertNotFalse(file_put_contents($old, 'old'));
        self::assertNotFalse(file_put_contents($recent, 'recent'));
        self::assertTrue(touch($old, time() - 31 * 86400));
        self::assertTrue(touch($base . '/index.html', time() - 31 * 86400));

        self::assertSame(1, $cache->clear(30));
        self::assertFileDoesNotExist($old);
        self::assertFileExists($recent);
        self::assertFileExists($base . '/index.html');

        self::assertSame(1, $cache->clear());
        self::assertFileExists($base . '/ab/index.html');
    }

    public function testClearRejectsNonPositiveDays(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ThumbCache($this->createTempDirectory('thumb-cache'), 'https://example.test/cache'))->clear(0);
    }

    /**
     * 日数の上限は、秒に換算(86400倍)してintに収まる最大の日数である。上限ちょうどは受け入れ(それより前のファイルは
     * 無いので何も消さない)、上限+1は、秒への換算の前にInvalidArgumentExceptionにして何も消さない。
     */
    public function testClearAcceptsTheMaximumDaysAndRejectsLargerDays(): void
    {
        self::assertSame(intdiv(\PHP_INT_MAX, 86400), ThumbCache::maxOlderThanDays());
        $base = $this->createTempDirectory('thumb-cache');
        $this->stubMkdir();
        $cache = new ThumbCache($base, 'https://example.test/cache');
        $cache->ensureDirectory(self::KEY);
        $file = $base . '/ab/' . self::KEY . '.jpg';
        self::assertNotFalse(file_put_contents($file, 'old'));
        self::assertTrue(touch($file, time() - 365 * 86400));

        self::assertSame(0, $cache->clear(ThumbCache::maxOlderThanDays()));
        self::assertFileExists($file);

        try {
            $cache->clear(ThumbCache::maxOlderThanDays() + 1);
            self::fail('InvalidArgumentException was not thrown');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('the number of days to keep thumbnail cache files must be at most ' . ThumbCache::maxOlderThanDays() . ', ' . (ThumbCache::maxOlderThanDays() + 1) . ' given', $exception->getMessage());
        }

        self::assertFileExists($file);
        self::assertFileExists($base . '/index.html');
    }

    /** 集計は、生成したサムネイルと失敗の印だけを数え、index.html・ロック・一時ファイルは数えない。 */
    public function testStatusCountsOnlyThumbnails(): void
    {
        $base = $this->createTempDirectory('thumb-cache');
        $this->stubMkdir();
        $cache = new ThumbCache($base, 'https://example.test/cache');
        $cache->ensureDirectory(self::KEY);
        self::assertNotFalse(file_put_contents($base . '/ab/' . self::KEY . '.jpg', '12345'));
        self::assertNotFalse(file_put_contents($cache->temporaryPath(self::KEY, 'jpg'), 'temporary'));
        self::assertNotFalse(file_put_contents($base . '/ab/' . self::KEY . '.lock', ''));
        $cache->markFailed(self::OTHER_KEY);

        self::assertSame(['files' => 1, 'bytes' => 5, 'failed' => 1], $cache->status());
    }

    /**
     * 割り込みの順の回帰(実OSのflockと、4つのPHPのプロセス)。Bがロックのファイルを開いた後、flockの前に止まる。
     * Aが手放して(ロックのファイルを消し)、Cが作り直したファイルのロックを取った後に、Bが古いファイルのflockを取る。
     * Bは、開いたファイルがパスの今のファイルと違うことを確かめて取り直し、Cが持っているためロックを取れない。Dも取れない。
     */
    public function testLockOfRemovedLockFileIsNotSharedAcrossProcesses(): void
    {
        $base = $this->createTempDirectory('thumb-lock-race');
        $worker = $this->writeLockWorker();

        $a = $this->startWorker($worker, $base, 'normal');
        self::assertSame('locked', $this->readWorker($a));

        $b = $this->startWorker($worker, $base, 'pause');
        self::assertSame('opened', $this->readWorker($b));

        $this->writeWorker($a, 'release');
        self::assertSame('released', $this->readWorker($a));

        $c = $this->startWorker($worker, $base, 'normal');
        self::assertSame('locked', $this->readWorker($c));

        $this->writeWorker($b, 'continue');
        self::assertSame('busy', $this->readWorker($b));

        $d = $this->startWorker($worker, $base, 'normal');
        self::assertSame('busy', $this->readWorker($d));

        $this->writeWorker($c, 'release');
        self::assertSame('released', $this->readWorker($c));

        foreach ([$a, $b, $c, $d] as $process) {
            self::assertSame(0, $this->closeWorker($process));
        }

        self::assertFileDoesNotExist($base . '/ab/' . self::KEY . '.lock');
    }

    /**
     * flockの後、パスの今のファイルを調べる前に、別のholderがロックのファイルを消しても、stat()の警告を外に出さずに
     * 「同じでない」として取り直し、新しいロックのファイルのロックを取る。
     */
    public function testLockFileRemovedBeforeStatIsRetriedWithoutWarning(): void
    {
        $base = $this->createTempDirectory('thumb-lock-stat');
        $worker = $this->writeLockWorker();

        $b = $this->startWorker($worker, $base, 'pause-stat');
        self::assertSame('stat-paused', $this->readWorker($b));

        $lockFile = $base . '/ab/' . self::KEY . '.lock';
        self::assertFileExists($lockFile);
        self::assertTrue(unlink($lockFile));

        $this->writeWorker($b, 'continue');
        self::assertSame('locked', $this->readWorker($b));
        self::assertFileExists($lockFile);

        $this->writeWorker($b, 'release');
        self::assertSame('released', $this->readWorker($b));
        self::assertSame(0, $this->closeWorker($b));
    }

    /**
     * GCと集計は、このクラスが作ったファイル(shardの直下にあり、キーで始まり、キーの先頭2文字がshardの名前と同じ)だけを
     * 扱う。cacheDirNameがuploadsの既存のディレクトリと同じ名前でも、そこにある元画像等には触れない。
     */
    public function testClearAndStatusIgnoreFilesNotCreatedByTheCache(): void
    {
        $base = $this->createTempDirectory('thumb-cache');
        $this->stubMkdir();
        $cache = new ThumbCache($base, 'https://example.test/cache');
        $cache->ensureDirectory(self::KEY);
        $foreign = [
            $base . '/photo.jpg',
            $base . '/' . self::KEY . '.jpg',
            $base . '/10/' . self::KEY . '.jpg',
            $base . '/sites/2/ab/' . self::KEY . '.jpg',
            $base . '/ab/photo.jpg',
            $base . '/ab/cd' . substr(self::KEY, 2) . '.jpg',
            $base . '/ab/nested/' . self::KEY . '.jpg',
            // 16進でない2文字のディレクトリに、その2文字で始まるキーの形の名前
            $base . '/zz/zz' . substr(self::KEY, 2) . '.jpg',
        ];
        $own = $base . '/ab/' . self::KEY . '.jpg';

        foreach ([...$foreign, $own] as $file) {
            if (!is_dir(\dirname($file))) {
                self::assertTrue(mkdir(\dirname($file), 0755, true));
            }

            self::assertNotFalse(file_put_contents($file, 'image'));
            self::assertTrue(touch($file, time() - 31 * 86400));
        }

        // shardの中の、キーの名前のシンボリックリンク(先は外のファイル)
        $outside = $this->createTempDirectory('thumb-outside') . '/image.jpg';
        self::assertNotFalse(file_put_contents($outside, 'outside image'));
        self::assertTrue(touch($outside, time() - 31 * 86400));
        $link = $base . '/ab/' . self::OTHER_KEY . '.jpg';
        self::assertTrue(symlink($outside, $link));

        self::assertSame(['files' => 1, 'bytes' => 5, 'failed' => 0], $cache->status());
        self::assertSame(1, $cache->clear(30));
        self::assertSame(0, $cache->clear());
        self::assertFileDoesNotExist($own);
        self::assertTrue(is_link($link));
        self::assertFileExists($outside);

        foreach ($foreign as $file) {
            self::assertFileExists($file);
        }
    }

    /**
     * 印の無い既存のディレクトリ(cacheDirNameを年にした場合の、月のディレクトリにキーの形の名前のファイルがあるもの)は、
     * キャッシュとして扱わない。clear・statusは例外になって何も消さず、ensureDirectoryも例外になる。
     */
    public function testUnmarkedExistingDirectoryIsNotTreatedAsCache(): void
    {
        $base = $this->createTempDirectory('thumb-uploads') . '/2026';
        $this->stubMkdir();
        $files = [$base . '/10/10' . substr(self::KEY, 2) . '.jpg', $base . '/01/01' . substr(self::KEY, 2) . '.png'];

        foreach ($files as $file) {
            self::assertTrue(mkdir(\dirname($file), 0755, true));
            self::assertNotFalse(file_put_contents($file, 'photo'));
        }

        $cache = new ThumbCache($base, 'https://example.test/uploads/2026');

        foreach ([
            'clear' => static function () use ($cache): void {
                $cache->clear();
            },
            'status' => static function () use ($cache): void {
                $cache->status();
            },
            'ensureDirectory' => static function () use ($cache): void {
                $cache->ensureDirectory(self::KEY);
            },
        ] as $operation => $call) {
            try {
                $call();
                self::fail("{$operation}が、印の無い既存のディレクトリを拒みませんでした。");
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('exists without the marker file', $exception->getMessage(), $operation);
                self::assertStringContainsString('cacheDirName may overlap an existing directory of uploads', $exception->getMessage(), $operation);
            }
        }

        foreach ($files as $file) {
            self::assertFileExists($file);
        }

        self::assertFileDoesNotExist($base . '/' . ThumbCache::MARKER);
        self::assertDirectoryDoesNotExist($base . '/ab');
    }

    /**
     * 初回の作成(ディレクトリが無い、または空)で印を置き、以後のclear・statusが通る。ディレクトリが無ければ、clear・
     * statusは0件。印のファイルはGC・集計の対象にしない。
     */
    public function testFirstCreationPlacesMarker(): void
    {
        $this->stubMkdir();
        $missing = new ThumbCache($this->createTempDirectory('thumb-uploads') . '/tmswp-thumb', 'https://example.test/cache');
        self::assertSame(0, $missing->clear());
        self::assertSame(['files' => 0, 'bytes' => 0, 'failed' => 0], $missing->status());

        foreach (['作成' => $this->createTempDirectory('thumb-uploads') . '/tmswp-thumb', '空の既存' => $this->createTempDirectory('thumb-empty')] as $case => $base) {
            $cache = new ThumbCache($base, 'https://example.test/cache');
            $cache->ensureDirectory(self::KEY);

            self::assertFileExists($base . '/' . ThumbCache::MARKER, $case);
            self::assertNotFalse(file_put_contents($base . '/ab/' . self::KEY . '.jpg', 'jpeg'));
            self::assertSame(['files' => 1, 'bytes' => 4, 'failed' => 0], $cache->status(), $case);
            self::assertSame(1, $cache->clear(), $case);
            self::assertFileExists($base . '/' . ThumbCache::MARKER, $case);
            $cache->ensureDirectory(self::OTHER_KEY);
        }
    }

    /**
     * shardのディレクトリが、キャッシュのディレクトリの外を指すシンボリックリンクなら、読み書き(作成・取得・GC)の前に
     * 例外にする。外のディレクトリには何も書かない。
     */
    public function testShardDirectoryOutsideTheCacheIsRejected(): void
    {
        $base = $this->createTempDirectory('thumb-cache');
        $outside = $this->createTempDirectory('thumb-outside');
        $this->stubMkdir();
        $cache = new ThumbCache($base, 'https://example.test/cache');
        self::assertNotFalse(file_put_contents($base . '/' . ThumbCache::MARKER, ''));
        self::assertTrue(symlink($outside, $base . '/ab'));
        self::assertNotFalse(file_put_contents($outside . '/' . self::KEY . '.jpg', 'outside'));

        try {
            foreach ([
                'ensureDirectory' => static function () use ($cache): void {
                    $cache->ensureDirectory(self::KEY);
                },
                'find' => static function () use ($cache): void {
                    $cache->find(self::KEY);
                },
                'lock' => static function () use ($cache): void {
                    $cache->lock(self::KEY);
                },
                'clear' => static function () use ($cache): void {
                    $cache->clear();
                },
                'status' => static function () use ($cache): void {
                    $cache->status();
                },
            ] as $operation => $call) {
                try {
                    $call();
                    self::fail("{$operation}が、外を指すshardを拒みませんでした。");
                } catch (RuntimeException $exception) {
                    self::assertStringContainsString('must be a real directory directly under the cache directory', $exception->getMessage(), $operation);
                }
            }

            self::assertSame([self::KEY . '.jpg'], array_values(array_diff((array) scandir($outside), ['.', '..'])));
        } finally {
            // 一時ディレクトリの後始末がリンクの先をたどらないよう、先にリンクを消す
            unlink($base . '/ab');
        }
    }

    /** GCは、ロックを持っている(生成中の)キーのロックと一時ファイルを消さず、手放した後に消す。 */
    public function testClearSkipsKeysBeingGenerated(): void
    {
        $base = $this->createTempDirectory('thumb-cache');
        $this->stubMkdir();
        $cache = new ThumbCache($base, 'https://example.test/cache');
        $cache->ensureDirectory(self::KEY);
        $lock = $cache->lock(self::KEY);
        self::assertInstanceOf(ThumbLock::class, $lock);
        $temporary = $cache->temporaryPath(self::KEY, 'jpg');
        $other = $base . '/ab/' . self::OTHER_KEY . '.jpg';
        self::assertNotFalse(file_put_contents($temporary, 'generating'));
        self::assertNotFalse(file_put_contents($other, 'old'));
        self::assertTrue(touch($temporary, time() - 31 * 86400));
        self::assertTrue(touch($other, time() - 31 * 86400));

        try {
            self::assertSame(1, $cache->clear(30));
            self::assertSame(0, $cache->clear());
            self::assertFileExists($temporary);
            self::assertFileExists($base . '/ab/' . self::KEY . '.lock');
            self::assertFileDoesNotExist($other);
            self::assertNull($cache->lock(self::KEY));
        } finally {
            $lock->release();
        }

        self::assertSame(1, $cache->clear(30));
        self::assertFileDoesNotExist($temporary);
        self::assertFileDoesNotExist($base . '/ab/' . self::KEY . '.lock');
    }

    /** 異常終了で残ったロックのファイル(誰も持っていない)は、GCが消す。 */
    public function testClearRemovesLeftoverLockFile(): void
    {
        $base = $this->createTempDirectory('thumb-cache');
        $this->stubMkdir();
        $cache = new ThumbCache($base, 'https://example.test/cache');
        $cache->ensureDirectory(self::KEY);
        self::assertNotFalse(file_put_contents($base . '/ab/' . self::KEY . '.lock', ''));
        self::assertNotFalse(file_put_contents($base . '/ab/' . self::KEY . '.jpg', 'jpeg'));

        self::assertSame(1, $cache->clear());
        self::assertSame(['index.html'], array_values(array_diff((array) scandir($base . '/ab'), ['.', '..'])));
    }

    /**
     * ロックを取って応答する作業プロセスのスクリプトを、一時ディレクトリに書く。引数は「キャッシュのディレクトリ」と
     * 「normal・pause・pause-stat」。pauseのプロセスは、ロックのファイルを開いた後、flockの前に`opened`を出して
     * `continue`を待つ。pause-statのプロセスは、flockの後、ロックのファイルのstat()の前に`stat-paused`を出して
     * `continue`を待つ(どちらもThumbCacheの名前空間の関数で止める。ロックとstatそのものは実OS)。外に出たPHPの警告は
     * 例外にして、作業プロセスを止める。
     */
    private function writeLockWorker(): string
    {
        $source = <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace TMSWP\Thumbnail {
                function flock($handle, int $operation, ?int &$wouldBlock = null): bool
                {
                    if ($GLOBALS['pauseBeforeFlock'] === true) {
                        $GLOBALS['pauseBeforeFlock'] = false;
                        fwrite(STDOUT, "opened\n");

                        if (trim((string) fgets(STDIN)) !== 'continue') {
                            exit(2);
                        }
                    }

                    return \flock($handle, $operation, $wouldBlock);
                }

                function stat(string $path): array|false
                {
                    if ($GLOBALS['pauseBeforeStat'] === true && str_ends_with($path, '.lock')) {
                        $GLOBALS['pauseBeforeStat'] = false;
                        fwrite(STDOUT, "stat-paused\n");

                        if (trim((string) fgets(STDIN)) !== 'continue') {
                            exit(2);
                        }
                    }

                    return \stat($path);
                }
            }

            namespace {
                require '{autoload}';

                function wp_mkdir_p(string $dir): bool
                {
                    return is_dir($dir) || mkdir($dir, 0755, true);
                }

                // 外に出た警告は、例外にして作業プロセスを止める(警告を出さないことを確かめるため)
                set_error_handler(static function (int $errno, string $message): never {
                    throw new \ErrorException($message, 0, $errno);
                });
                $GLOBALS['pauseBeforeFlock'] = $argv[2] === 'pause';
                $GLOBALS['pauseBeforeStat'] = $argv[2] === 'pause-stat';
                $cache = new \TMSWP\Thumbnail\ThumbCache($argv[1], 'https://example.test/cache');
                $cache->ensureDirectory('{key}');
                $lock = $cache->lock('{key}');
                fwrite(STDOUT, ($lock === null ? 'busy' : 'locked') . "\n");

                if ($lock !== null) {
                    if (trim((string) fgets(STDIN)) !== 'release') {
                        exit(3);
                    }

                    $lock->release();
                    fwrite(STDOUT, "released\n");
                }
            }
            PHP;
        $source = strtr($source, ['{autoload}' => self::composerAutoloadFile(), '{key}' => self::KEY]);
        $file = $this->createTempDirectory('thumb-lock-worker') . '/worker.php';
        self::assertNotFalse(file_put_contents($file, $source));

        return $file;
    }

    /**
     * @return array{process: resource, stdin: resource, stdout: resource, stderr: resource}
     */
    private function startWorker(string $worker, string $base, string $mode): array
    {
        $process = proc_open([PHP_BINARY, $worker, $base, $mode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        self::assertIsResource($pipes[0]);
        self::assertIsResource($pipes[1]);
        self::assertIsResource($pipes[2]);

        return ['process' => $process, 'stdin' => $pipes[0], 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }

    /**
     * @param array{process: resource, stdin: resource, stdout: resource, stderr: resource} $worker
     */
    private function readWorker(array $worker): string
    {
        $read = [$worker['stdout']];
        $write = null;
        $except = null;
        self::assertSame(1, stream_select($read, $write, $except, self::WORKER_TIMEOUT), '作業プロセスの応答がありません。');
        $line = fgets($worker['stdout']);

        if (!\is_string($line)) {
            // 応答せずに終わった。標準エラー(終わったので読み切れる)を示して失敗にする
            self::fail('作業プロセスが応答せずに終わりました: ' . stream_get_contents($worker['stderr']));
        }

        return trim($line);
    }

    /**
     * @param array{process: resource, stdin: resource, stdout: resource, stderr: resource} $worker
     */
    private function writeWorker(array $worker, string $command): void
    {
        self::assertNotFalse(fwrite($worker['stdin'], $command . "\n"));
        self::assertTrue(fflush($worker['stdin']));
    }

    /**
     * @param array{process: resource, stdin: resource, stdout: resource, stderr: resource} $worker
     *
     * @return int 終了コード
     */
    private function closeWorker(array $worker): int
    {
        fclose($worker['stdin']);
        fclose($worker['stdout']);
        fclose($worker['stderr']);

        return proc_close($worker['process']);
    }

    private function stubMkdir(): void
    {
        Functions\when('wp_mkdir_p')->alias(static fn(string $dir): bool => is_dir($dir) || mkdir($dir, 0755, true));
    }
}
