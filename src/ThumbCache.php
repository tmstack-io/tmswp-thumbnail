<?php

declare(strict_types=1);

namespace TMSWP\Thumbnail;

use FilesystemIterator;
use SplFileInfo;
use Throwable;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Exception\RuntimeException;
use UnexpectedValueException;

/**
 * サムネイルのキャッシュのディレクトリ(`{uploads}/{cacheDirName}/{shard}/`)の読み書き。
 *
 * shardのディレクトリには、生成したサムネイル(`{key}.{ext}`)のほかに、恒久的な失敗の印(`{key}.failed`)、生成中の
 * ロック(`{key}.lock`)と一時ファイル(`{key}.tmp-{乱数}.{ext}`)を置く。キャッシュのディレクトリとshardのディレクトリには、
 * 空の`index.html`を置く。
 *
 * キャッシュのディレクトリには、印のファイル(MARKER)を置く。印の無い既存のディレクトリは、キャッシュとして使い始めず、
 * GCと集計もしない(例外にする)。
 *
 * GCと集計が扱うのは、このクラスが作ったファイル(shardのディレクトリの直下にあり、名前が40桁の16進のキーで始まり、
 * そのキーの先頭2文字がshardのディレクトリ名と同じもの)だけである。それ以外のファイル・ディレクトリには触れない。
 * shardのディレクトリは、キャッシュのディレクトリの直下の実在のディレクトリでなければならない(シンボリックリンク等で
 * 外を指していれば、読み書きの前に例外にする)。
 *
 * ロックのファイルは、ロックを持ったまま消す(ThumbLock::release()とGC)。消した後に別のプロセスが同じ名前で作り直しても、
 * lock()はロックを取った後に、開いたファイルとパスの今のファイルが同じ(inodeとデバイスが一致)かを確かめ、違えば取り直す。
 * 消される前に開いた古いファイルのロックを取ったプロセスが、新しいファイルのロックを持つプロセスと同時に動くことは無い。
 */
final class ThumbCache
{
    /** 生成したサムネイルの拡張子(画像エディタの出力形式のフィルタで、元画像と違う形式になりうる) */
    private const array OUTPUT_EXTENSIONS = ['jpg', 'png', 'gif', 'webp', 'avif'];

    /**
     * キャッシュのディレクトリの印のファイル名。ensureDirectory()が、キャッシュのディレクトリを作ったときに置く。印の無い
     * ディレクトリ(cacheDirNameがuploadsの既存のディレクトリと重なった等)を、キャッシュとして使わず、GCもしない。
     */
    public const string MARKER = '.tmswp-thumb-cache';

    private const int DAY_IN_SECONDS = 86400;

    /** lock()が、開いたファイルとパスの今のファイルの食い違いで取り直す上限の回数 */
    private const int LOCK_ATTEMPTS = 3;

    /**
     * clear()に渡せる日数の上限。秒に換算(86400倍)してintに収まる最大の日数である。
     */
    public static function maxOlderThanDays(): int
    {
        return intdiv(\PHP_INT_MAX, self::DAY_IN_SECONDS);
    }

    public function __construct(
        private readonly string $baseDir,
        private readonly string $baseUrl,
    ) {}

    /**
     * キャッシュのキー(SHA-256の16進の先頭40文字)。元画像(uploadsからの相対パスと更新日時)、寸法と、既定値で補った
     * crop・qualityから作る。ThumbnailConfigの既定値を変えると、オプションを省いたURLも別のキーになる。
     */
    public function key(string $relativePath, int $mtime, int $width, int $height, bool $crop, int $quality): string
    {
        return substr(hash('sha256', serialize([$relativePath, $mtime, $width, $height, $crop, $quality])), 0, 40);
    }

    /**
     * 生成済みのサムネイルのパス。無ければnull。
     */
    public function find(string $key): ?string
    {
        foreach (self::OUTPUT_EXTENSIONS as $extension) {
            $path = $this->shardDir($key) . DIRECTORY_SEPARATOR . $key . '.' . $extension;

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * キャッシュのファイル(find()・commit()が返したパス)の静的なURL。
     */
    public function url(string $path): string
    {
        $file = basename($path);

        return rtrim($this->baseUrl, '/') . '/' . substr($file, 0, 2) . '/' . $file;
    }

    /**
     * キャッシュのファイルの更新日時を今にする(GCは、最後にREST経由で返してからの日数で消す)。
     *
     * @throws RuntimeException 更新できない場合
     */
    public function touch(string $path): void
    {
        if (!touch($path)) {
            throw new RuntimeException("failed to update the modification time of the thumbnail cache file: {$path}");
        }
    }

    /** このキーの生成が、恒久的な失敗として記録されているか。 */
    public function hasFailed(string $key): bool
    {
        return is_file($this->failedPath($key));
    }

    /**
     * このキーの生成を、恒久的な失敗として記録する(元画像の更新日時か指定が変わればキーが変わる。GCでも消える)。
     *
     * @throws RuntimeException 記録できない場合
     */
    public function markFailed(string $key): void
    {
        if (file_put_contents($this->failedPath($key), '') === false) {
            throw new RuntimeException("failed to write the thumbnail failure marker: {$this->failedPath($key)}");
        }
    }

    /**
     * キャッシュのディレクトリとshardのディレクトリを作り、それぞれに空のindex.htmlを置く。キャッシュのディレクトリには、
     * 印のファイルも置く。
     *
     * キャッシュのディレクトリが既にあって印が無い場合は、空なら印を置いて使い始め、空でなければ例外にする(uploadsの
     * 既存のディレクトリを、キャッシュとして使い始めない)。
     *
     * @throws RuntimeException ディレクトリ・印・index.htmlを作れない場合、印の無い空でない既存のディレクトリの場合、
     *                          shardのディレクトリがキャッシュのディレクトリの直下の実在のディレクトリでない場合
     */
    public function ensureDirectory(string $key): void
    {
        $this->ensureCacheDirectory();
        $dir = $this->shardDir($key);

        self::makeDirectory($dir);
        $this->assertRealShard($dir);
        $this->ensureIndex($dir);
    }

    /**
     * このキーの排他ロックを、待たずに取る(ensureDirectory()の後に呼ぶ)。
     *
     * flockを取った後に、開いたファイルがパスの今のファイルと同じかを確かめる。パスが消えた、または別のファイルに
     * 替わっていた(前の持ち主が手放すときに消した)ら、手放して開き直す(LOCK_ATTEMPTS回まで)。
     *
     * @throws RuntimeException ロックのファイルを開けない場合
     *
     * @return ThumbLock|null 別のプロセスがロックを持っている(取り直しの上限に達した場合を含む)ときはnull
     */
    public function lock(string $key): ?ThumbLock
    {
        $path = $this->lockPath($key);

        for ($attempt = 0; $attempt < self::LOCK_ATTEMPTS; ++$attempt) {
            $handle = fopen($path, 'c');

            if ($handle === false) {
                throw new RuntimeException("failed to open the thumbnail lock file: {$path}");
            }

            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                fclose($handle);

                return null;
            }

            if (self::isSameFile($handle, $path)) {
                return new ThumbLock($handle, $path);
            }

            fclose($handle);
        }

        return null;
    }

    /**
     * 生成の書き込み先の一時ファイルのパス(同じshardのディレクトリ。rename()を同じファイルシステムの中で行うため)。
     */
    public function temporaryPath(string $key, string $extension): string
    {
        return $this->shardDir($key) . DIRECTORY_SEPARATOR . $key . '.tmp-' . bin2hex(random_bytes(8)) . '.' . $extension;
    }

    /**
     * 書き込みを終えた一時ファイルを、rename()でキャッシュのファイルに置き換える。拡張子は、実際に書いたファイルの
     * 拡張子(画像エディタの出力形式)に従う。
     *
     * @param string $writtenPath 画像エディタのsave()が返したパス(temporaryPath()と拡張子だけが違いうる)
     *
     * @throws RuntimeException 一時ファイルがこのキーのshardの外にある、出力の拡張子が画像でない、rename()に失敗した場合
     *
     * @return string キャッシュのファイルのパス
     */
    public function commit(string $writtenPath, string $key): string
    {
        $extension = strtolower(pathinfo($writtenPath, PATHINFO_EXTENSION));

        if (\dirname($writtenPath) !== $this->shardDir($key) || !\in_array($extension, self::OUTPUT_EXTENSIONS, true)) {
            $this->discard($writtenPath);

            throw new RuntimeException("the image editor wrote the thumbnail to an unexpected path: {$writtenPath}");
        }

        $target = $this->shardDir($key) . DIRECTORY_SEPARATOR . $key . '.' . $extension;

        if (!rename($writtenPath, $target)) {
            $this->discard($writtenPath);

            throw new RuntimeException("failed to move the generated thumbnail to {$target}");
        }

        return $target;
    }

    /**
     * 失敗した生成の一時ファイルを消す(無ければ何もしない)。
     *
     * @throws RuntimeException 消せない場合
     */
    public function discard(string $path): void
    {
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException("failed to remove the temporary thumbnail file: {$path}");
        }
    }

    /**
     * キャッシュのファイルを消す。
     *
     * 対象は、このクラスが作ったファイル(クラスの説明を参照)だけである。キーごとにそのキーのロックを待たずに取り、取れた
     * キーのファイルだけを消す(生成中のキーのロックと一時ファイルを消さない)。ロックのファイルは、ロックを手放すときに
     * 消す(残っていたロックのファイルも消える)。キャッシュの形でないファイルには触れない。
     *
     * @param int|null $olderThanDays 指定すると、更新日時がこの日数より前のファイルだけを消す。nullならすべて消す
     *
     * @throws InvalidArgumentException $olderThanDaysが1未満の場合、maxOlderThanDays()を超える場合(何も消さない)
     * @throws RuntimeException 消せないファイルがあった場合(消せたファイルは消したまま。件数を含める)、ロックを扱えない場合、
     *                          キャッシュのディレクトリがあるのに印が無い場合(何も消さない)
     *
     * @return int 消したファイルの数(ロックのファイルは数えない)
     */
    public function clear(?int $olderThanDays = null): int
    {
        if ($olderThanDays !== null && $olderThanDays < 1) {
            throw new InvalidArgumentException("the number of days to keep thumbnail cache files must be positive, {$olderThanDays} given");
        }

        $maxDays = self::maxOlderThanDays();

        if ($olderThanDays !== null && $olderThanDays > $maxDays) {
            throw new InvalidArgumentException("the number of days to keep thumbnail cache files must be at most {$maxDays}, {$olderThanDays} given");
        }

        $threshold = $olderThanDays === null ? null : time() - ($olderThanDays * self::DAY_IN_SECONDS);
        $deleted = 0;
        $failed = [];
        $byKey = [];

        foreach ($this->files() as ['key' => $key, 'file' => $file]) {
            $byKey[$key][] = $file->getPathname();
        }

        foreach ($byKey as $key => $paths) {
            $lock = $this->lock($key);

            if (!$lock instanceof ThumbLock) {
                continue;
            }

            try {
                $this->deleteFiles(array_values(array_diff($paths, [$this->lockPath($key)])), $threshold, $deleted, $failed);
            } catch (Throwable $exception) {
                $lock->release($exception);

                throw $exception;
            }

            $lock->release();
        }

        if ($failed !== []) {
            throw new RuntimeException(\count($failed) . " thumbnail cache file(s) could not be deleted ({$deleted} deleted), e.g. {$failed[0]}");
        }

        return $deleted;
    }

    /**
     * 生成済みのサムネイルの数と合計のバイト数、恒久的な失敗の印の数。index.html・ロック・一時ファイルと、キャッシュの
     * 形でないファイルは数えない。キャッシュのディレクトリが無ければ0件。
     *
     * @throws RuntimeException キャッシュのディレクトリがあるのに印が無い場合
     *
     * @return array{files: int, bytes: int, failed: int}
     */
    public function status(): array
    {
        $status = ['files' => 0, 'bytes' => 0, 'failed' => 0];
        $thumbnail = '/\A[0-9a-f]{40}\.(?:' . implode('|', self::OUTPUT_EXTENSIONS) . ')\z/';

        foreach ($this->files() as ['file' => $file]) {
            if (preg_match($thumbnail, $file->getFilename()) === 1) {
                ++$status['files'];
                $status['bytes'] += $file->getSize();
            } elseif (preg_match('/\A[0-9a-f]{40}\.failed\z/', $file->getFilename()) === 1) {
                ++$status['failed'];
            }
        }

        return $status;
    }

    /**
     * このクラスが作ったファイル。shardのディレクトリ(キャッシュのディレクトリの直下の2桁の16進の名前)の直下にあり、
     * 名前が40桁の16進のキーで始まり、そのキーの先頭2文字がshardの名前と同じ、シンボリックリンクでない通常のファイル。
     * キャッシュのディレクトリが無ければ空。
     *
     * @throws RuntimeException キャッシュのディレクトリに印が無い場合、キャッシュのディレクトリを読めない場合、shardの
     *                          ディレクトリが直下の実在のディレクトリでない場合
     *
     * @return list<array{key: string, file: SplFileInfo}>
     */
    private function files(): array
    {
        if (!is_dir($this->baseDir)) {
            return [];
        }

        if (!is_file($this->markerPath())) {
            throw new RuntimeException(self::unmarkedMessage($this->baseDir));
        }

        $files = [];

        try {
            foreach (new FilesystemIterator($this->baseDir) as $shard) {
                if (!$shard instanceof SplFileInfo || preg_match('/\A[0-9a-f]{2}\z/', $shard->getFilename()) !== 1 || !$shard->isDir()) {
                    continue;
                }

                $this->assertRealShard($shard->getPathname());

                foreach (new FilesystemIterator($shard->getPathname()) as $file) {
                    if (
                        $file instanceof SplFileInfo
                        && !$file->isLink()
                        && $file->isFile()
                        && preg_match('/\A(' . preg_quote($shard->getFilename(), '/') . '[0-9a-f]{38})\./', $file->getFilename(), $matches) === 1
                    ) {
                        $files[] = ['key' => $matches[1], 'file' => $file];
                    }
                }
            }
        } catch (UnexpectedValueException $exception) {
            throw new RuntimeException("failed to read the thumbnail cache directory: {$this->baseDir}", previous: $exception);
        }

        return $files;
    }

    /**
     * キャッシュのディレクトリを作り、印とindex.htmlを置く。既にあって印が無ければ、空のときだけ印を置く。
     *
     * @throws RuntimeException 作れない場合、印の無い空でない既存のディレクトリの場合
     */
    private function ensureCacheDirectory(): void
    {
        $marker = $this->markerPath();

        if (!is_file($marker)) {
            if (is_dir($this->baseDir)) {
                if (!self::isEmptyDirectory($this->baseDir)) {
                    // 同時に作り始めた別のプロセスが、先に印を置いた後かもしれない
                    clearstatcache(true, $marker);

                    if (!is_file($marker)) {
                        throw new RuntimeException(self::unmarkedMessage($this->baseDir));
                    }
                }
            } else {
                self::makeDirectory($this->baseDir);
            }

            if (!is_file($marker) && file_put_contents($marker, '') === false) {
                throw new RuntimeException("failed to write the marker file of the thumbnail cache directory: {$marker}");
            }
        }

        $this->ensureIndex($this->baseDir);
    }

    /**
     * ディレクトリを作る。wp_mkdir_p()は、有無を確かめた後に作るため、その間に別のプロセスが作るとfalseを返す(初回の
     * 同時アクセス)。falseでも、ディレクトリができていれば成功として扱う。
     *
     * @throws RuntimeException ディレクトリができていない場合
     */
    private static function makeDirectory(string $dir): void
    {
        if (wp_mkdir_p($dir)) {
            return;
        }

        clearstatcache(true, $dir);

        if (!is_dir($dir)) {
            throw new RuntimeException("failed to create the thumbnail cache directory: {$dir}");
        }
    }

    /**
     * @throws RuntimeException index.htmlを作れない場合
     */
    private function ensureIndex(string $dir): void
    {
        $index = $dir . DIRECTORY_SEPARATOR . 'index.html';

        if (!is_file($index) && file_put_contents($index, '') === false) {
            throw new RuntimeException("failed to write index.html in the thumbnail cache directory: {$dir}");
        }
    }

    /**
     * @throws RuntimeException ディレクトリを読めない場合
     */
    private static function isEmptyDirectory(string $dir): bool
    {
        try {
            return !(new FilesystemIterator($dir))->valid();
        } catch (UnexpectedValueException $exception) {
            throw new RuntimeException("failed to read the thumbnail cache directory: {$dir}", previous: $exception);
        }
    }

    private static function unmarkedMessage(string $dir): string
    {
        return 'the thumbnail cache directory exists without the marker file ' . self::MARKER . ": {$dir}; ThumbnailConfig cacheDirName may overlap an existing directory of uploads, so choose a name that is not used in uploads";
    }

    private function markerPath(): string
    {
        return $this->baseDir . DIRECTORY_SEPARATOR . self::MARKER;
    }

    /**
     * 更新日時がしきい値より前(しきい値がnullならすべて)のファイルを消す。一覧を作った後に消えたファイルは飛ばす。
     *
     * @param list<string> $paths
     * @param list<string> $failed 消せなかったファイルを足す
     */
    private function deleteFiles(array $paths, ?int $threshold, int &$deleted, array &$failed): void
    {
        foreach ($paths as $path) {
            clearstatcache(true, $path);

            try {
                $mtime = (new SplFileInfo($path))->getMTime();
            } catch (\RuntimeException) {
                // 一覧を作った後に、生成の終わり(rename)等で消えた
                continue;
            }

            if ($threshold !== null && $mtime >= $threshold) {
                continue;
            }

            if (unlink($path)) {
                ++$deleted;
            } else {
                $failed[] = $path;
            }
        }
    }

    /**
     * 開いたハンドルのファイルと、パスの今のファイルが同じか(inodeとデバイスで比べる)。
     *
     * パスの今のファイルを調べる間に、別のプロセスがロックのファイルを消すことがある(予想どおりの競合)。stat()の失敗の
     * 警告を外に出さずに受け、パスが無くなっていれば「同じでない」として取り直しに戻す。パスがあるのにstat()が失敗した
     * ときは、例外にする。
     *
     * @param resource $handle
     *
     * @throws RuntimeException パスがあるのに、開いたハンドルかパスの情報を取れない場合
     */
    private static function isSameFile($handle, string $path): bool
    {
        $opened = fstat($handle);

        if ($opened === false) {
            throw new RuntimeException("failed to read the opened thumbnail lock file: {$path}");
        }

        clearstatcache(true, $path);
        $warning = null;
        set_error_handler(static function (int $errno, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        }, E_WARNING);

        try {
            $current = stat($path);
        } finally {
            restore_error_handler();
        }

        if ($current === false) {
            clearstatcache(true, $path);

            if (file_exists($path)) {
                throw new RuntimeException("failed to read the thumbnail lock file: {$path}" . ($warning === null ? '' : " ({$warning})"));
            }

            return false;
        }

        return $opened['ino'] === $current['ino'] && $opened['dev'] === $current['dev'];
    }

    /**
     * キーのshardのディレクトリ。既にあるなら、キャッシュのディレクトリの直下の実在のディレクトリであることを確かめる。
     *
     * @throws RuntimeException shardのディレクトリがシンボリックリンク等で、直下の実在のディレクトリでない場合
     */
    private function shardDir(string $key): string
    {
        $dir = $this->baseDir . DIRECTORY_SEPARATOR . substr($key, 0, 2);

        if (file_exists($dir) || is_link($dir)) {
            $this->assertRealShard($dir);
        }

        return $dir;
    }

    /**
     * @throws RuntimeException shardのディレクトリの実パスが、キャッシュのディレクトリの実パスの直下のその名前でない場合
     */
    private function assertRealShard(string $dir): void
    {
        $base = realpath($this->baseDir);

        if ($base === false || realpath($dir) !== $base . DIRECTORY_SEPARATOR . basename($dir)) {
            throw new RuntimeException("the thumbnail cache shard directory must be a real directory directly under the cache directory: {$dir}");
        }
    }

    private function lockPath(string $key): string
    {
        return $this->shardDir($key) . DIRECTORY_SEPARATOR . $key . '.lock';
    }

    private function failedPath(string $key): string
    {
        return $this->shardDir($key) . DIRECTORY_SEPARATOR . $key . '.failed';
    }
}
