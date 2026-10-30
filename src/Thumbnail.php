<?php

declare(strict_types=1);

namespace TMSWP\Thumbnail;

use Psr\Log\LogLevel;
use SplFileInfo;
use Throwable;
use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Exception\RuntimeException;
use TMSWP\Core\Kernel\ExtensionInterface;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Thumbnail\Cli\ThumbCommand;
use TMSWP\Thumbnail\Exception\InvalidThumbOptionException;
use TMSWP\Thumbnail\Exception\InvalidThumbSourceException;
use WP_CLI;

/**
 * thumbnailの入口。`KernelConfig::theme()` / `plugin()`の`extensions`に渡して登録する。
 *
 * ```php
 * return KernelConfig::theme(__DIR__ . '/app', 'App', extensions: [new Thumbnail()]);
 * ```
 *
 * thumbnailを登録できるKernelは、サイトに1つだけである(RESTのルートとキャッシュのディレクトリはサイトに1つのため)。
 * `tms_thumb()`・RESTのルート・`wp tmswp thumb`は、登録したKernelのdebug・ロガーと、このインスタンスの設定に従う。
 */
final class Thumbnail implements ExtensionInterface
{
    private ?Kernel $kernel = null;

    /**
     * WordPressの関数は呼ばない(tmswp-kernel.phpの中で、WordPressを起動しない経路からも生成されるため)。
     */
    public function __construct(private readonly ThumbnailConfig $config = new ThumbnailConfig()) {}

    /**
     * 登録したKernelのboot中に1回呼ばれる。訳の読み込み(init 1)、RESTのルートの登録(rest_api_init 10)、RESTのルートが
     * 使えるかの確認と管理者への通知(admin_notices 10)を予約し、WP-CLIの環境なら`wp tmswp thumb`を登録する。
     *
     * @throws LogicException このインスタンスを登録済みの場合、別のKernelにthumbnailを登録済みの場合
     */
    public function register(Kernel $kernel): void
    {
        if ($this->kernel instanceof Kernel) {
            throw new LogicException("this Thumbnail instance is already registered to the kernel '{$this->kernel->config()->appNamespace}'");
        }

        $registered = self::registered();

        if ($registered instanceof self) {
            throw new LogicException("thumbnail is already registered to the kernel '{$registered->kernel()->config()->appNamespace}'; register Thumbnail to only one kernel of the site (kernel '{$kernel->config()->appNamespace}')");
        }

        $this->kernel = $kernel;
        $kernel->defer('init', 1, static function (): void {
            load_textdomain('tmswp', \dirname(__DIR__) . '/languages/tmswp-' . determine_locale() . '.mo');
        });
        $kernel->defer('rest_api_init', 10, function (): void {
            $this->controller()->registerRoutes();
        });
        $kernel->defer('admin_notices', 10, static function (): void {
            (new RestRouteCheck())->notice();
        });

        if (\defined('WP_CLI') && WP_CLI === true && class_exists('WP_CLI')) {
            WP_CLI::add_command('tmswp thumb', new ThumbCommand($this));
        }
    }

    /**
     * サイトでthumbnailを登録したインスタンス(起動したKernelのextensionsにあり、そのKernelに登録したもの)。
     *
     * @throws LogicException thumbnailを登録したKernelが無い場合
     */
    public static function current(): self
    {
        return self::registered() ?? throw new LogicException('thumbnail is not registered; pass new Thumbnail() in the extensions of the KernelConfig of one kernel of the site');
    }

    /**
     * サムネイルのURLを返す。
     *
     * 生成済みのキャッシュがあればその静的なURLを、無ければ署名付きのRESTのURLを返す。値はURLエンコードして埋め込む。
     *
     * - 画像が無いこと(`$src`が0か空文字、添付ファイルやその実体が無い)は、nullを返す(エラーにしない)
     * - 引数とオプションの誤り(uploadsの外・許可されない拡張子・寸法・オプション)は、debugに関係なく例外
     * - 実行時の失敗(元画像の更新日時が読めない等)は、登録したKernelのdebugなら例外、本番ならKernelのロガーに
     *   errorで記録してnullを返す
     *
     * @param int|string $src 添付ファイルのID(`get_post_thumbnail_id()`等)、またはuploadsの下の画像のURL
     * @param int|null $width 幅(px)。null・0なら高さと元画像の縦横比で決める
     * @param int|null $height 高さ(px)。null・0なら幅と元画像の縦横比で決める
     * @param array<string, mixed> $options `crop`(bool)・`quality`(int 1〜100)
     *
     * @throws LogicException このインスタンスが登録されていない場合
     * @throws InvalidThumbSourceException srcの誤り、幅と高さが両方0・負・maxDimensionの超過
     * @throws InvalidThumbOptionException オプションの誤り
     * @throws RuntimeException debugのときの実行時の失敗
     *
     * @return string|null URL。画像が無いとき・本番の実行時の失敗のときはnull
     */
    public function url(int|string $src, ?int $width = null, ?int $height = null, array $options = []): ?string
    {
        $kernel = $this->kernel();
        $path = null;

        try {
            return $this->buildUrl($src, $width ?? 0, $height ?? 0, $options, $path);
        } catch (InvalidThumbSourceException|InvalidThumbOptionException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            // 解決の後に元画像が消えた(差し替え・削除と重なった)なら、画像が無い
            if ($path !== null && !is_file($path)) {
                return null;
            }

            if ($kernel->config()->debug) {
                throw $exception;
            }

            $kernel->logger()->log(LogLevel::ERROR, 'tmswp.thumbnail: failed to build a thumbnail URL', self::exceptionContext($exception));

            return null;
        }
    }

    public function config(): ThumbnailConfig
    {
        return $this->config;
    }

    /**
     * 登録したKernel。
     *
     * @throws LogicException このインスタンスが登録されていない場合
     */
    public function kernel(): Kernel
    {
        return $this->kernel ?? throw new LogicException('this Thumbnail instance is not registered to a kernel; pass it in the extensions of a KernelConfig');
    }

    /** RESTのルートのコントローラ(rest_api_initで作る)。 */
    private function controller(): RestController
    {
        return new RestController($this->signer(), new SourceResolver(), $this->cache(), new ThumbGenerator(), $this->config, $this->kernel());
    }

    /**
     * @internal URLの生成・RESTのルート・CLIが使う
     *
     * キャッシュのディレクトリは`{uploadsの実パス}/{cacheDirName}`である。既にあるなら、その実パスが同じ(シンボリック
     * リンク等でuploadsの外を指していない)ことを確かめてから返す。
     *
     * @throws RuntimeException wp_upload_dir()がbasedir・baseurlを返さない、uploadsの実パスが取れない、キャッシュの
     *                          ディレクトリの実パスがuploadsの下のその名前でない場合
     */
    public function cache(): ThumbCache
    {
        $uploads = self::uploads();
        $base = realpath($uploads['basedir']);

        if ($base === false) {
            throw new RuntimeException("the uploads directory does not exist: {$uploads['basedir']}");
        }

        $dir = $base . DIRECTORY_SEPARATOR . $this->config->cacheDirName;

        if (file_exists($dir) && realpath($dir) !== $dir) {
            throw new RuntimeException("the thumbnail cache directory must be a real directory directly under the uploads directory: {$dir}");
        }

        return new ThumbCache($dir, rtrim($uploads['baseurl'], '/') . '/' . $this->config->cacheDirName);
    }

    /**
     * URLの署名。鍵はwp_salt('auth')から、URLの署名の用途だけに導出する。
     *
     * @throws RuntimeException wp_salt()が空文字を返した場合
     */
    private function signer(): UrlSigner
    {
        $salt = wp_salt('auth');

        if ($salt === '') {
            throw new RuntimeException('wp_salt(\'auth\') returned an empty string; the thumbnail URL signature needs a secret');
        }

        return new UrlSigner(UrlSigner::deriveKey($salt));
    }

    /**
     * オプションを`o`の正規化した文字列にする(キーの昇順、既定値と同じキーは含めない)。
     *
     * @param array<string, mixed> $options
     *
     * @throws InvalidThumbOptionException 許可されないキー・型・範囲外の値
     */
    public static function normalizeOptions(array $options, ThumbnailConfig $config): string
    {
        $allowed = ['crop' => true, 'quality' => true];
        $normalized = [];

        foreach ($options as $key => $value) {
            if (!\is_string($key) || !\array_key_exists($key, $allowed)) {
                throw new InvalidThumbOptionException('thumbnail option keys must be crop or quality');
            }

            if ($key === 'crop') {
                if (!\is_bool($value)) {
                    throw new InvalidThumbOptionException('thumbnail crop option must be boolean');
                }

                if ($value !== $config->defaultCrop) {
                    $normalized[$key] = $value ? '1' : '0';
                }

                continue;
            }

            if (!\is_int($value) || $value < 1 || $value > 100) {
                throw new InvalidThumbOptionException('thumbnail quality option must be an integer from 1 to 100');
            }

            if ($value !== $config->defaultQuality) {
                $normalized[$key] = (string) $value;
            }
        }

        ksort($normalized, SORT_STRING);
        $pairs = [];

        foreach ($normalized as $key => $value) {
            $pairs[] = "{$key}={$value}";
        }

        return implode('&', $pairs);
    }

    /**
     * @throws RuntimeException wp_upload_dir()がbasedir・baseurlを返さない場合
     *
     * @return array{basedir: string, baseurl: string}
     */
    public static function uploads(): array
    {
        $uploads = wp_upload_dir();

        if (!\is_array($uploads) || !\is_string($uploads['basedir'] ?? null) || !\is_string($uploads['baseurl'] ?? null)) {
            throw new RuntimeException('wp_upload_dir() did not return basedir and baseurl');
        }

        return ['basedir' => $uploads['basedir'], 'baseurl' => $uploads['baseurl']];
    }

    /**
     * @throws InvalidThumbSourceException 幅と高さが両方0、負、maxDimensionの超過
     */
    public static function assertDimensions(int $width, int $height, ThumbnailConfig $config): void
    {
        if (($width === 0 && $height === 0) || $width < 0 || $height < 0 || $width > $config->maxDimension || $height > $config->maxDimension) {
            throw new InvalidThumbSourceException('thumbnail dimensions are invalid');
        }
    }

    /**
     * SourceResolverが解決した実パスの、uploadsからの相対パス(区切りは`/`)。
     *
     * @throws RuntimeException 実パスが取れない、またはuploadsの外の場合(解決の後に、ファイルが消えた等)
     */
    public static function relativePath(string $path, string $baseDir): string
    {
        $base = realpath($baseDir);
        $real = realpath($path);

        if ($base === false || $real === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("thumbnail source is no longer inside the uploads directory: {$path}");
        }

        return ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($real, \strlen($base))), '/');
    }

    /**
     * ファイルの更新日時。stat()の警告を出さずに、読めなければ例外にする。
     *
     * @internal URLの生成・RESTのルートが使う
     *
     * @throws RuntimeException 読めない場合(ファイルが消えた等)
     */
    public static function modificationTime(string $path): int
    {
        clearstatcache(true, $path);

        try {
            return (new SplFileInfo($path))->getMTime();
        } catch (\RuntimeException $exception) {
            throw new RuntimeException("failed to read the modification time of the thumbnail source: {$path}", previous: $exception);
        }
    }

    /** 元画像の拡張子(小文字。jpegはjpg)。 */
    public static function extension(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $extension === 'jpeg' ? 'jpg' : $extension;
    }

    /**
     * ログの文脈に入れる例外の情報。例外のメッセージ・トレース・オブジェクトは入れず、例外のクラス・投げられた場所・
     * previousのクラスだけを入れる(パスや利用者の入力値を本番のログに残さない)。開発者はdebugで元の例外を見られる。
     *
     * @internal
     *
     * @return array{exceptionClass: class-string<Throwable>, exceptionAt: string, previousClasses: list<class-string<Throwable>>}
     */
    public static function exceptionContext(Throwable $exception): array
    {
        $previous = [];

        for ($current = $exception->getPrevious(); $current !== null; $current = $current->getPrevious()) {
            $previous[] = $current::class;
        }

        return [
            'exceptionClass' => $exception::class,
            'exceptionAt' => $exception->getFile() . ':' . $exception->getLine(),
            'previousClasses' => $previous,
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @param string|null $path 解決した元画像の実パスを入れる(失敗のときに、元画像が消えたかを確かめるため)
     *
     * @throws InvalidThumbSourceException|InvalidThumbOptionException 引数・オプションの誤り
     * @throws RuntimeException 実行時の失敗
     */
    private function buildUrl(int|string $src, int $width, int $height, array $options, ?string &$path): ?string
    {
        if ($src === 0 || $src === '') {
            return null;
        }

        if (\is_int($src) && $src < 0) {
            throw new InvalidThumbSourceException("thumbnail source attachment ID must not be negative: {$src}");
        }

        $source = \is_int($src) ? "id:{$src}" : $src;
        self::assertDimensions($width, $height, $this->config);
        $optionString = self::normalizeOptions($options, $this->config);
        $effective = RestController::parseOptions($optionString, $this->config);
        $path = (new SourceResolver())->resolve($source);

        if ($path === null) {
            return null;
        }

        $mtime = self::modificationTime($path);
        $cache = $this->cache();
        $key = $cache->key(self::relativePath($path, self::uploads()['basedir']), $mtime, $width, $height, $effective['crop'], $effective['quality']);
        $cached = $cache->find($key);

        if ($cached !== null) {
            return $cache->url($cached);
        }

        // add_query_arg()は値をエンコードしないため、エンコードした値を渡す(受け取った側の$_GETで1回デコードされ、
        // 署名した生の値に戻る)
        return add_query_arg(array_map(rawurlencode(...), [
            'src' => $source,
            'w' => (string) $width,
            'h' => (string) $height,
            'o' => $optionString,
            's' => $this->signer()->sign($source, $width, $height, $optionString),
        ]), rest_url(RestController::NAMESPACE . RestController::ROUTE));
    }

    /**
     * 起動したKernelのうち、thumbnailを登録したKernelのインスタンス。
     */
    private static function registered(): ?self
    {
        foreach (Kernel::all() as $kernel) {
            foreach ($kernel->config()->extensions as $extension) {
                if ($extension instanceof self && $extension->kernel === $kernel) {
                    return $extension;
                }
            }
        }

        return null;
    }
}
