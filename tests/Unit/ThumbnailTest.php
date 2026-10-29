<?php

declare(strict_types=1);

namespace TMSWP\Thumbnail\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Closure;
use PhpToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Exception\RuntimeException;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Testing\UnitTestCase;
use TMSWP\Thumbnail\Exception\InvalidThumbOptionException;
use TMSWP\Thumbnail\Exception\InvalidThumbSourceException;
use TMSWP\Thumbnail\RestController;
use TMSWP\Thumbnail\SourceResolver;
use TMSWP\Thumbnail\Tests\Support\BootsThumbnailKernel;
use TMSWP\Thumbnail\Thumbnail;
use TMSWP\Thumbnail\ThumbnailConfig;

/**
 * Thumbnailの登録(1サイトに1つのKernel)と、URLの生成のエラー方針。
 */
final class ThumbnailTest extends UnitTestCase
{
    use BootsThumbnailKernel;

    private const string BASE_URL = 'https://example.test/wp-content/uploads';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_version'] = '7.0';
        $GLOBALS['wp_actions'] = [];
    }

    protected function tearDown(): void
    {
        Kernel::reset();
        unset($GLOBALS['wp_version'], $GLOBALS['wp_actions']);
        parent::tearDown();
    }

    /** 訳の読み込み・RESTのルート・管理画面の通知は、bootではなくフックに予約する(Kernel::defer())。 */
    public function testRegisterDefersHooks(): void
    {
        $added = [];

        foreach (['init', 'rest_api_init', 'admin_notices'] as $hook) {
            Actions\expectAdded($hook)->zeroOrMoreTimes()->whenHappen(static function (mixed $callback, int $priority) use ($hook, &$added): void {
                $added[] = "{$hook}:{$priority}";
            });
        }

        $thumbnail = new Thumbnail();
        $booted = $this->bootThumbnailKernel('Defer', $thumbnail);

        self::assertContains('init:1', $added);
        self::assertContains('rest_api_init:10', $added);
        self::assertContains('admin_notices:10', $added);
        self::assertSame(['rest_api_init:10'], array_values(array_filter($added, static fn(string $hook): bool => str_starts_with($hook, 'rest_api_init'))));
        self::assertSame($booted['kernel'], $thumbnail->kernel());
        self::assertSame($thumbnail, Thumbnail::current());
    }

    /** thumbnailを登録できるKernelはサイトに1つ。2つ目のKernelの登録は、debugに関係なく例外(D-50)。 */
    #[DataProvider('debugModes')]
    public function testSecondKernelCannotRegisterThumbnail(bool $debug): void
    {
        $first = new Thumbnail();
        $this->bootThumbnailKernel('First', $first);

        try {
            $this->bootThumbnailKernel('Second', new Thumbnail(), $debug);
            self::fail('2つ目のKernelへの登録が例外になりませんでした。');
        } catch (LogicException $exception) {
            self::assertStringContainsString('thumbnail is already registered to the kernel', $exception->getMessage());
        }

        self::assertSame($first, Thumbnail::current());
    }

    /**
     * 1つのインスタンスを2つのKernelに渡すと、2つ目のKernelのbootが、debugに関係なく例外になる(インスタンスの検査が
     * D-50の検査より先に効く)。
     */
    #[DataProvider('debugModes')]
    public function testOneInstanceCannotBeRegisteredToTwoKernels(bool $debug): void
    {
        $thumbnail = new Thumbnail();
        $booted = $this->bootThumbnailKernel('Shared', $thumbnail);

        try {
            $this->bootThumbnailKernel('SharedOther', $thumbnail, $debug);
            self::fail('1つのインスタンスの2つ目のKernelへの登録が例外になりませんでした。');
        } catch (LogicException $exception) {
            self::assertSame("this Thumbnail instance is already registered to the kernel '{$booted['kernel']->config()->appNamespace}'", $exception->getMessage());
        }

        self::assertSame($booted['kernel'], $thumbnail->kernel());
        self::assertSame($thumbnail, Thumbnail::current());
    }

    /** @return iterable<string, array{bool}> */
    public static function debugModes(): iterable
    {
        yield 'debug' => [true];
        yield '本番' => [false];
    }

    /** 1つのインスタンスは1つのKernelにだけ登録できる。 */
    public function testInstanceCannotBeRegisteredTwice(): void
    {
        $thumbnail = new Thumbnail();
        $booted = $this->bootThumbnailKernel('Twice', $thumbnail);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('this Thumbnail instance is already registered');

        $thumbnail->register($booted['kernel']);
    }

    public function testCurrentAndTmsThumbThrowWithoutRegistration(): void
    {
        try {
            Thumbnail::current();
            self::fail('登録が無いのにThumbnail::current()が例外になりませんでした。');
        } catch (LogicException $exception) {
            self::assertStringContainsString('thumbnail is not registered', $exception->getMessage());
        }

        $this->expectException(LogicException::class);

        tms_thumb(1, 100, 100);
    }

    public function testUrlOfUnregisteredInstanceThrows(): void
    {
        $this->expectException(LogicException::class);

        (new Thumbnail())->url(1, 100, 100);
    }

    /** アイキャッチの未設定(0)と空のURLは、debugでも例外にせずnullを返す(データが無い)。 */
    public function testUrlReturnsNullForMissingSourceEvenInDebug(): void
    {
        $this->bootThumbnailKernel('Missing', new Thumbnail());
        $uploads = $this->stubUploads();
        Functions\when('get_attached_file')->justReturn(false);

        self::assertNull(tms_thumb(0, 100, 100));
        self::assertNull(tms_thumb('', 100, 100));
        // 添付ファイルが無い・実体が無い
        self::assertNull(tms_thumb(123, 100, 100));
        self::assertNull(tms_thumb(self::BASE_URL . '/missing.jpg', 100, 100));
        self::assertDirectoryExists($uploads);
    }

    /**
     * 引数とオプションの誤りは、本番でも例外(記録してnullにしない)。
     *
     * @return iterable<string, array{0: int|string, 1: int|null, 2: int|null, 3: array<string, mixed>, 4: class-string<Throwable>}>
     */
    public static function misuseProvider(): iterable
    {
        yield 'uploadsの外のURL' => ['https://other.test/image.jpg', 100, 100, [], InvalidThumbSourceException::class];
        yield '許可されない拡張子' => [self::BASE_URL . '/file.pdf', 100, 100, [], InvalidThumbSourceException::class];
        yield '負の添付ファイルのID' => [-1, 100, 100, [], InvalidThumbSourceException::class];
        yield '幅と高さが両方無い' => [1, null, null, [], InvalidThumbSourceException::class];
        yield 'maxDimensionの超過' => [1, 2561, 100, [], InvalidThumbSourceException::class];
        yield '許可されないオプション' => [1, 100, 100, ['fit' => 'crop'], InvalidThumbOptionException::class];
        yield '範囲外のquality' => [1, 100, 100, ['quality' => 101], InvalidThumbOptionException::class];
    }

    /**
     * @param array<string, mixed> $options
     * @param class-string<Throwable> $exception
     */
    #[DataProvider('misuseProvider')]
    public function testUrlThrowsForMisuseInProduction(int|string $src, ?int $width, ?int $height, array $options, string $exception): void
    {
        $booted = $this->bootThumbnailKernel('Misuse', new Thumbnail(), false);
        $this->stubUploads();
        Functions\when('get_attached_file')->justReturn(false);

        try {
            tms_thumb($src, $width, $height, $options);
            self::fail('誤用が例外になりませんでした。');
        } catch (Throwable $thrown) {
            self::assertInstanceOf($exception, $thrown);
        }

        self::assertSame([], $booted['logger']->records);
    }

    /**
     * 実行時の失敗は、本番ではKernelのロガーにerrorで記録してnullを返す。文脈には例外のクラスと場所だけを入れ、
     * 例外のオブジェクト・メッセージ・トレースは入れない。
     */
    public function testRuntimeFailureIsLoggedWithoutExceptionDetailsInProduction(): void
    {
        $booted = $this->bootThumbnailKernel('Production', new Thumbnail(), false);
        Functions\when('wp_upload_dir')->justReturn([]);

        self::assertNull(tms_thumb(self::BASE_URL . '/image.jpg', 100, 100));

        self::assertCount(1, $booted['logger']->records);
        $record = $booted['logger']->records[0];
        self::assertSame('error', $record['level']);
        self::assertSame('tmswp.thumbnail: failed to build a thumbnail URL', $record['message']);
        self::assertSame(['exceptionClass', 'exceptionAt', 'previousClasses'], array_keys($record['context']));
        self::assertSame(RuntimeException::class, $record['context']['exceptionClass']);
        self::assertIsString($record['context']['exceptionAt']);
        self::assertStringEndsWith('Thumbnail.php:' . self::lineOf('wp_upload_dir() did not return basedir and baseurl'), $record['context']['exceptionAt']);
        self::assertSame([], $record['context']['previousClasses']);
    }

    /** 実行時の失敗は、登録したKernelがdebugなら例外のまま投げる(WP_DEBUGは見ない)。 */
    public function testRuntimeFailureIsThrownInDebug(): void
    {
        $booted = $this->bootThumbnailKernel('Debug', new Thumbnail());
        Functions\when('wp_upload_dir')->justReturn([]);

        try {
            tms_thumb(self::BASE_URL . '/image.jpg', 100, 100);
            self::fail('debugで実行時の失敗が例外になりませんでした。');
        } catch (RuntimeException $exception) {
            self::assertSame('wp_upload_dir() did not return basedir and baseurl', $exception->getMessage());
        }

        self::assertSame([], $booted['logger']->records);
    }

    public function testNormalizeOptionsSortsAndOmitsDefaults(): void
    {
        $config = new ThumbnailConfig();

        self::assertSame('', Thumbnail::normalizeOptions([], $config));
        self::assertSame('', Thumbnail::normalizeOptions(['crop' => true, 'quality' => 82], $config));
        self::assertSame('crop=0&quality=90', Thumbnail::normalizeOptions(['quality' => 90, 'crop' => false], $config));
    }

    public function testParseOptionsFillsDefaultsAndRejectsOutOfRangeQuality(): void
    {
        $config = new ThumbnailConfig(defaultQuality: 70, defaultCrop: false);

        self::assertSame(['crop' => false, 'quality' => 70], RestController::parseOptions('', $config));
        self::assertSame(['crop' => true, 'quality' => 90], RestController::parseOptions('crop=1&quality=90', $config));

        $this->expectException(InvalidThumbOptionException::class);

        RestController::parseOptions('quality=101', $config);
    }

    /**
     * @return iterable<string, array{0: Closure(): ThumbnailConfig}>
     */
    public static function invalidConfigProvider(): iterable
    {
        yield 'maxDimensionが0' => [static fn(): ThumbnailConfig => new ThumbnailConfig(maxDimension: 0)];
        yield 'defaultQualityが0' => [static fn(): ThumbnailConfig => new ThumbnailConfig(defaultQuality: 0)];
        yield 'defaultQualityが101' => [static fn(): ThumbnailConfig => new ThumbnailConfig(defaultQuality: 101)];
        yield 'cacheDirNameが空' => [static fn(): ThumbnailConfig => new ThumbnailConfig(cacheDirName: '')];
        yield 'cacheDirNameが.' => [static fn(): ThumbnailConfig => new ThumbnailConfig(cacheDirName: '.')];
        yield 'cacheDirNameが..' => [static fn(): ThumbnailConfig => new ThumbnailConfig(cacheDirName: '..')];
        yield 'cacheDirNameがuploadsの外' => [static fn(): ThumbnailConfig => new ThumbnailConfig(cacheDirName: '../victim')];
        yield 'cacheDirNameが区切りを含む' => [static fn(): ThumbnailConfig => new ThumbnailConfig(cacheDirName: 'a/b')];
        yield 'cacheDirNameが隠しファイル' => [static fn(): ThumbnailConfig => new ThumbnailConfig(cacheDirName: '.thumbs')];
        yield 'cacheDirNameの末尾が改行' => [static fn(): ThumbnailConfig => new ThumbnailConfig(cacheDirName: "tmswp-thumb\n")];
        yield 'redirectMaxAgeが0' => [static fn(): ThumbnailConfig => new ThumbnailConfig(redirectMaxAge: 0)];
    }

    /**
     * @param Closure(): ThumbnailConfig $create
     */
    #[DataProvider('invalidConfigProvider')]
    public function testConfigRejectsInvalidValues(Closure $create): void
    {
        $this->expectException(InvalidArgumentException::class);

        $create();
    }

    /** キャッシュのディレクトリが、uploadsの外を指すシンボリックリンクなら、キャッシュを操作する前に例外にする。 */
    public function testCacheRejectsDirectoryOutsideUploads(): void
    {
        $uploads = $this->stubUploads();
        $outside = $this->createTempDirectory('thumb-outside');
        self::assertTrue(symlink($outside, $uploads . '/tmswp-thumb'));

        try {
            (new Thumbnail())->cache();
            self::fail('uploadsの外を指すキャッシュのディレクトリが拒まれませんでした。');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('must be a real directory directly under the uploads directory', $exception->getMessage());
        } finally {
            // 一時ディレクトリの後始末がリンクの先をたどらないよう、先にリンクを消す
            unlink($uploads . '/tmswp-thumb');
        }
    }

    /**
     * uploadsの外を指すsrcは、相手のファイルの有無に関係なく例外(ファイルの有無を見る前に、パスの文字列で拒む)。
     *
     * @return iterable<string, array{0: string, 1: string|false}>
     */
    public static function outsideSourceProvider(): iterable
    {
        yield '..の区間(相手が無い)' => [self::BASE_URL . '/../outside/missing.jpg', false];
        yield 'エンコードした..の区間' => [self::BASE_URL . '/a/%2E%2E/%2E%2E/missing.jpg', false];
        yield 'エンコードした絶対パス' => [self::BASE_URL . '/%2Fetc%2Fmissing.jpg', false];
        yield '途中の./' => [self::BASE_URL . '/2026/./missing.jpg', false];
        yield '途中の空の区間' => [self::BASE_URL . '/2026//missing.jpg', false];
        yield '接頭辞の直後の/(baseurlの末尾が/でない)' => [self::BASE_URL . '//2026/missing.jpg', false];
        yield 'uploadsの外を指す添付ファイルのメタ(相手が無い)' => ['id:5', '/elsewhere/missing.jpg'];
        yield '..を含む添付ファイルのメタ' => ['id:5', '{uploads}/../missing.jpg'];
    }

    #[DataProvider('outsideSourceProvider')]
    public function testSourceResolverRejectsOutsidePathsBeforeCheckingExistence(string $src, string|false $attachedFile): void
    {
        $uploads = $this->stubUploads();
        Functions\when('get_attached_file')->justReturn(\is_string($attachedFile) ? str_replace('{uploads}', $uploads, $attachedFile) : false);

        $this->expectException(InvalidThumbSourceException::class);
        $this->expectExceptionMessage('must be inside the uploads directory');

        (new SourceResolver())->resolve($src);
    }

    /**
     * uploadsと同じ接頭辞の別のディレクトリ(`uploads-evil`)を指す添付ファイルのメタを拒む。パスの文字列がuploads+`/`で
     * 始まらないため、ファイルの有無を見る前の、名前での封じ込めで止まる(実パスでの封じ込めは
     * testSourceResolverRejectsSymlinkOutsideUploadsが確かめる)。
     */
    public function testSourceResolverRejectsPathsOutsideUploads(): void
    {
        $uploads = $this->stubUploads();
        $evil = $uploads . '-evil';
        self::assertTrue(mkdir($evil));
        self::assertNotFalse(file_put_contents($evil . '/image.jpg', 'jpeg'));
        Functions\when('get_attached_file')->justReturn($evil . '/image.jpg');

        try {
            (new SourceResolver())->resolve('id:123');
            self::fail('uploadsの外を指す添付ファイルが拒まれませんでした。');
        } catch (InvalidThumbSourceException $exception) {
            self::assertStringContainsString('must be inside the uploads directory', $exception->getMessage());
        } finally {
            unlink($evil . '/image.jpg');
            rmdir($evil);
        }
    }

    /**
     * basedirの末尾が`/`のサイト(UPLOADS定数・upload_path・upload_dirのフィルタ)でも、WordPressと同じ結合
     * (get_attached_file()の`basedir . "/$file"`)で作った添付ファイルのパスを解決する(basedirの直後の`//`を許す)。
     */
    public function testSourceResolverAcceptsAttachmentWhenBasedirEndsWithSlash(): void
    {
        $uploads = $this->createTempDirectory('thumb-uploads');
        Functions\when('wp_upload_dir')->justReturn(['basedir' => $uploads . '/', 'baseurl' => self::BASE_URL]);
        self::assertTrue(mkdir($uploads . '/2026/10', 0755, true));
        self::assertNotFalse(file_put_contents($uploads . '/2026/10/photo.jpg', 'jpeg'));
        Functions\when('get_attached_file')->justReturn($uploads . '/' . '/2026/10/photo.jpg');

        self::assertSame(realpath($uploads . '/2026/10/photo.jpg'), (new SourceResolver())->resolve('id:5'));
    }

    /**
     * baseurlとbasedirの末尾が`/`のサイトでも、WordPressと同じ結合(wp_get_attachment_url()の`baseurl . "/$file"`)で
     * 作ったURLを解決する(接頭辞の直後の`/`を1つだけ余分に受け入れる)。それ以上の重なりとエンコードした`/`は拒む。
     */
    public function testSourceResolverAcceptsUrlWhenBaseurlEndsWithSlash(): void
    {
        $uploads = $this->createTempDirectory('thumb-uploads');
        Functions\when('wp_upload_dir')->justReturn(['basedir' => $uploads . '/', 'baseurl' => self::BASE_URL . '/']);
        self::assertTrue(mkdir($uploads . '/2026/10', 0755, true));
        self::assertNotFalse(file_put_contents($uploads . '/2026/10/photo.jpg', 'jpeg'));
        $resolver = new SourceResolver();

        self::assertSame(realpath($uploads . '/2026/10/photo.jpg'), $resolver->resolve(self::BASE_URL . '/' . '/2026/10/photo.jpg'));
        self::assertSame(realpath($uploads . '/2026/10/photo.jpg'), $resolver->resolve(self::BASE_URL . '/2026/10/photo.jpg'));

        foreach ([self::BASE_URL . '///2026/10/photo.jpg', self::BASE_URL . '/%2F2026/10/photo.jpg', self::BASE_URL . '//%2F2026/10/photo.jpg'] as $src) {
            try {
                $resolver->resolve($src);
                self::fail("{$src}が拒まれませんでした。");
            } catch (InvalidThumbSourceException $exception) {
                self::assertStringContainsString('must be inside the uploads directory', $exception->getMessage(), $src);
            }
        }
    }

    /**
     * 名前の上ではuploadsの中でも、実体がuploadsの外の実在する画像を指すシンボリックリンクは、実パスでの封じ込め(最終
     * ゲート)で拒む。
     */
    public function testSourceResolverRejectsSymlinkOutsideUploads(): void
    {
        $uploads = $this->stubUploads();
        $outside = $this->createTempDirectory('thumb-outside');
        self::assertNotFalse(file_put_contents($outside . '/secret.jpg', 'jpeg'));
        self::assertTrue(symlink($outside . '/secret.jpg', $uploads . '/link.jpg'));

        try {
            (new SourceResolver())->resolve(self::BASE_URL . '/link.jpg');
            self::fail('uploadsの外を指すシンボリックリンクが拒まれませんでした。');
        } catch (InvalidThumbSourceException $exception) {
            self::assertStringContainsString('must be inside the uploads directory', $exception->getMessage());
        } finally {
            unlink($uploads . '/link.jpg');
        }
    }

    /**
     * URLの形のsrcは、uploadsのURL(baseurl+`/`)で始まるものだけを受け付ける。ほかのホスト・スキーム・同じ接頭辞の
     * 別のパス・クエリやフラグメント付き・許可されない拡張子は、ファイルの有無に関係なく例外になる。
     *
     * @return iterable<string, array{string, string}>
     */
    public static function rejectedUrlProvider(): iterable
    {
        $outside = 'must be an attachment ID or a URL under the uploads directory';
        $extension = 'must be a jpg, png, gif, webp or avif image';

        yield 'ほかのホスト' => ['https://evil.example/wp-content/uploads/photo.jpg', $outside];
        yield 'ほかのスキーム' => ['http://example.test/wp-content/uploads/photo.jpg', $outside];
        yield '同じ接頭辞の別のパス' => [self::BASE_URL . '-evil/photo.jpg', $outside];
        yield 'uploadsの外の同じホストのURL' => ['https://example.test/wp-content/themes/photo.jpg', $outside];
        yield 'プロトコル相対のURL' => ['//example.test/wp-content/uploads/photo.jpg', $outside];
        yield 'uploadsのURLそのもの' => [self::BASE_URL . '/', 'does not name a file'];
        yield 'クエリ付き' => [self::BASE_URL . '/photo.jpg?ver=1', $extension];
        yield 'フラグメント付き' => [self::BASE_URL . '/photo.jpg#top', $extension];
        yield '許可されない拡張子' => [self::BASE_URL . '/photo.php', $extension];
        yield 'エンコードしたNUL' => [self::BASE_URL . '/photo%00.jpg', 'does not name a file'];
    }

    #[DataProvider('rejectedUrlProvider')]
    public function testSourceResolverRejectsUrlOutsideTheUploadsUrl(string $src, string $message): void
    {
        $uploads = $this->stubUploads();
        self::assertNotFalse(file_put_contents($uploads . '/photo.jpg', 'jpeg'));
        self::assertNotFalse(file_put_contents($uploads . '/photo.php', '<?php'));

        $this->expectException(InvalidThumbSourceException::class);
        $this->expectExceptionMessage($message);

        (new SourceResolver())->resolve($src);
    }

    /**
     * uploadsのURLの下の画像は、パーセントエンコードを1回デコードした名前のファイルに解決する。無いファイルはnull
     * (画像が無い)。
     */
    public function testSourceResolverResolvesUrlUnderTheUploadsUrl(): void
    {
        $uploads = $this->stubUploads();
        self::assertTrue(mkdir($uploads . '/2026/10', 0755, true));
        self::assertNotFalse(file_put_contents($uploads . '/2026/10/a b%c.jpg', 'jpeg'));
        self::assertNotFalse(file_put_contents($uploads . '/2026/10/UPPER.JPEG', 'jpeg'));
        $resolver = new SourceResolver();

        self::assertSame(realpath($uploads . '/2026/10/a b%c.jpg'), $resolver->resolve(self::BASE_URL . '/2026/10/' . rawurlencode('a b%c.jpg')));
        self::assertSame(realpath($uploads . '/2026/10/UPPER.JPEG'), $resolver->resolve(self::BASE_URL . '/2026/10/UPPER.JPEG'));
        self::assertNull($resolver->resolve(self::BASE_URL . '/2026/10/missing.jpg'));
    }

    /**
     * `tms_thumb()`は`function_exists`のガードで囲まずに、ファイルの最上位で定義する(同名の関数が先にあれば、再宣言の
     * エラーで止まる)。
     */
    public function testTmsThumbIsDefinedWithoutFunctionExistsGuard(): void
    {
        $contents = file_get_contents(\dirname(__DIR__, 2) . '/src/functions.php');
        self::assertIsString($contents);
        $depth = 0;
        $definedAtTopLevel = false;
        $tokens = PhpToken::tokenize($contents);

        foreach ($tokens as $index => $token) {
            self::assertFalse($token->is(T_STRING) && $token->text === 'function_exists', 'functions.phpがfunction_existsを使っています。');

            if ($token->text === '{') {
                ++$depth;
            } elseif ($token->text === '}') {
                --$depth;
            } elseif ($token->is(T_FUNCTION)) {
                $next = $tokens[$index + 2] ?? null;
                $definedAtTopLevel = $definedAtTopLevel || ($depth === 0 && $next?->text === 'tms_thumb');
            }
        }

        self::assertTrue($definedAtTopLevel, 'tms_thumbがファイルの最上位で定義されていません。');
    }

    /** 名前が画像の拡張子でも、実体が許可されない拡張子のファイル(シンボリックリンクの先)なら拒む。 */
    public function testSourceResolverChecksExtensionOfRealPath(): void
    {
        $uploads = $this->stubUploads();
        self::assertNotFalse(file_put_contents($uploads . '/data.php', '<?php'));
        self::assertTrue(symlink($uploads . '/data.php', $uploads . '/picture.jpg'));

        $this->expectException(InvalidThumbSourceException::class);
        $this->expectExceptionMessage('must be a jpg, png, gif, webp or avif image');

        (new SourceResolver())->resolve(self::BASE_URL . '/picture.jpg');
    }

    /** uploadsを一時ディレクトリにするwp_upload_dir()のスタブ。 */
    private function stubUploads(): string
    {
        $uploads = $this->createTempDirectory('thumb-uploads');
        Functions\when('wp_upload_dir')->justReturn(['basedir' => $uploads, 'baseurl' => self::BASE_URL]);

        return $uploads;
    }

    /** Thumbnail.phpで、文字列を含む行の番号。 */
    private static function lineOf(string $needle): int
    {
        $lines = file(\dirname(__DIR__, 2) . '/src/Thumbnail.php');
        self::assertIsArray($lines);

        foreach ($lines as $index => $line) {
            if (str_contains($line, $needle)) {
                return $index + 1;
            }
        }

        self::fail("Thumbnail.phpに{$needle}がありません。");
    }
}
