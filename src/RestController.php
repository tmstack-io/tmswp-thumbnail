<?php

declare(strict_types=1);

namespace TMSWP\Thumbnail;

use Psr\Log\LogLevel;
use Throwable;
use TMSWP\Core\Exception\RuntimeException;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Thumbnail\Exception\InvalidThumbOptionException;
use TMSWP\Thumbnail\Exception\InvalidThumbSourceException;
use TMSWP\Thumbnail\Exception\UnprocessableImageException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `GET /wp-json/tmswp/v1/thumb`。署名を確かめ、サムネイルを生成(またはキャッシュから取得)して、その静的なURLへ302で
 * リダイレクトする。
 *
 * 生成済みのファイルへの302は短期間(ThumbnailConfigのredirectMaxAge)だけキャッシュさせ、返すたびにファイルの更新日時を
 * 今にする(GCは最後に返してからの日数で消す)。生成できないとき(恒久的な失敗・ロックの競合・実行時の失敗)は、
 * 元画像へキャッシュさせない302を返す。
 */
final class RestController
{
    public const string NAMESPACE = 'tmswp/v1';

    public const string ROUTE = '/thumb';

    public function __construct(
        private readonly UrlSigner $signer,
        private readonly SourceResolver $resolver,
        private readonly ThumbCache $cache,
        private readonly ThumbGenerator $generator,
        private readonly ThumbnailConfig $config,
        private readonly Kernel $kernel,
    ) {}

    /** ルートを登録する(rest_api_initで呼ぶ)。認可は署名の検証が担う。 */
    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods' => 'GET',
            'callback' => [$this, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * 要求を処理する。
     *
     * 元画像が無い(解決の後に消えた場合を含む)ときは404。実行時の失敗(元画像の情報の読み取り・生成・キャッシュの
     * 読み書き)は、登録したKernelのdebugなら例外のまま投げ、本番ならKernelのロガーに記録して、元画像へのキャッシュさせない
     * 302(元画像のURLを決める前の失敗は500)を返す。
     *
     * @throws RuntimeException debugのときの実行時の失敗
     */
    public function handle(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $src = $this->stringParam($request, 'src');
        $widthRaw = $this->stringParam($request, 'w');
        $heightRaw = $this->stringParam($request, 'h');
        $optionsRaw = $this->stringParam($request, 'o');
        $signature = $this->stringParam($request, 's');

        if (preg_match('/\A[0-9a-f]{64}\z/i', $signature) !== 1) {
            return new WP_Error('tmswp_thumb_unsigned', 'Unsigned thumbnail request.', ['status' => 403]);
        }

        if (!$this->signer->verify($src, $widthRaw, $heightRaw, $optionsRaw, $signature)) {
            return new WP_Error('tmswp_thumb_bad_signature', 'Bad thumbnail signature.', ['status' => 403]);
        }

        try {
            $options = self::parseOptions($optionsRaw, $this->config);
            $width = $this->integer($widthRaw);
            $height = $this->integer($heightRaw);
            Thumbnail::assertDimensions($width, $height, $this->config);
        } catch (InvalidThumbOptionException|InvalidThumbSourceException $exception) {
            return new WP_Error('tmswp_thumb_bad_request', $exception->getMessage(), ['status' => 400]);
        }

        $path = null;
        $sourceUrl = null;

        try {
            try {
                $path = $this->resolver->resolve($src);
            } catch (InvalidThumbSourceException) {
                return self::notFound();
            }

            if ($path === null) {
                return self::notFound();
            }

            $mtime = Thumbnail::modificationTime($path);
            $uploads = Thumbnail::uploads();
            $relative = Thumbnail::relativePath($path, $uploads['basedir']);
            $sourceUrl = rtrim($uploads['baseurl'], '/') . '/' . implode('/', array_map(rawurlencode(...), explode('/', $relative)));
            $key = $this->cache->key($relative, $mtime, $width, $height, $options['crop'], $options['quality']);

            return $this->serve($key, $path, $sourceUrl, $width, $height, $options['crop'], $options['quality']);
        } catch (RuntimeException $exception) {
            // 解決の後に元画像が消えた(差し替え・削除と重なった)なら、元画像が無い要求として扱う
            if ($path !== null && !self::exists($path)) {
                return self::notFound();
            }

            if ($sourceUrl === null) {
                $this->report('failed to prepare a thumbnail request', $exception);

                return new WP_Error('tmswp_thumb_failed', 'Thumbnail could not be generated.', ['status' => 500]);
            }

            $this->report('failed to generate a thumbnail; redirected to the source image', $exception);

            return $this->redirect($sourceUrl, false);
        }
    }

    /**
     * 受け取った`o`をcrop・qualityに分解し、無いキーをThumbnailConfigの既定値で補う(再正規化はしない)。
     *
     * @throws InvalidThumbOptionException 形の誤り、許可されないキー、範囲外の値
     *
     * @return array{crop: bool, quality: int}
     */
    public static function parseOptions(string $options, ThumbnailConfig $config): array
    {
        $parsed = ['crop' => $config->defaultCrop, 'quality' => $config->defaultQuality];

        if ($options === '') {
            return $parsed;
        }

        foreach (explode('&', $options) as $pair) {
            $parts = explode('=', $pair, 2);

            if (\count($parts) !== 2) {
                throw new InvalidThumbOptionException('thumbnail option format is invalid');
            }

            [$key, $value] = $parts;

            if ($key === 'crop') {
                if (!\in_array($value, ['0', '1'], true)) {
                    throw new InvalidThumbOptionException('thumbnail crop option must be 0 or 1');
                }

                $parsed['crop'] = $value === '1';
                continue;
            }

            if ($key === 'quality') {
                if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1 || (int) $value > 100) {
                    throw new InvalidThumbOptionException('thumbnail quality option must be an integer from 1 to 100');
                }

                $parsed['quality'] = (int) $value;
                continue;
            }

            throw new InvalidThumbOptionException('thumbnail option keys must be crop or quality');
        }

        return $parsed;
    }

    /**
     * キャッシュのファイルを返すか、キーのロックを取って生成する。
     *
     * @throws RuntimeException 生成・キャッシュの読み書きの失敗
     */
    private function serve(string $key, string $source, string $sourceUrl, int $width, int $height, bool $crop, int $quality): WP_REST_Response
    {
        $cached = $this->cache->find($key);

        if ($cached !== null) {
            return $this->redirectToCached($cached);
        }

        if ($this->cache->hasFailed($key)) {
            return $this->redirect($sourceUrl, false);
        }

        $this->cache->ensureDirectory($key);
        $lock = $this->cache->lock($key);

        if (!$lock instanceof ThumbLock) {
            // 別のプロセスが同じキーを生成中。待たずに元画像を返す
            return $this->redirect($sourceUrl, false);
        }

        try {
            $response = $this->generateLocked($key, $source, $sourceUrl, $width, $height, $crop, $quality);
        } catch (Throwable $exception) {
            $lock->release($exception);

            throw $exception;
        }

        $lock->release();

        return $response;
    }

    /**
     * ロックを取った後に、キャッシュと失敗の印を確かめ直してから、一時ファイルに生成してrename()で置き換える。
     *
     * @throws RuntimeException 生成・キャッシュの読み書きの失敗(恒久的な失敗は、失敗の印を書いてから投げる)
     */
    private function generateLocked(string $key, string $source, string $sourceUrl, int $width, int $height, bool $crop, int $quality): WP_REST_Response
    {
        $cached = $this->cache->find($key);

        if ($cached !== null) {
            return $this->redirectToCached($cached);
        }

        if ($this->cache->hasFailed($key)) {
            return $this->redirect($sourceUrl, false);
        }

        $temporary = $this->cache->temporaryPath($key, Thumbnail::extension($source));

        try {
            $written = $this->generator->generate($source, $temporary, $width, $height, $crop, $quality);
        } catch (UnprocessableImageException $exception) {
            $this->cache->discard($temporary);
            $this->cache->markFailed($key);

            throw $exception;
        } catch (RuntimeException $exception) {
            $this->cache->discard($temporary);

            throw $exception;
        }

        return $this->redirectToCached($this->cache->commit($written, $key));
    }

    /**
     * キャッシュのファイルの更新日時を今にして、その静的なURLへリダイレクトする。更新日時を変えられないときも、
     * ファイルは返す(本番は記録だけ。debugは例外)。
     *
     * @throws RuntimeException debugのときの、更新日時の変更の失敗
     */
    private function redirectToCached(string $path): WP_REST_Response
    {
        try {
            $this->cache->touch($path);
        } catch (RuntimeException $exception) {
            $this->report('failed to update the modification time of a thumbnail cache file', $exception);
        }

        return $this->redirect($this->cache->url($path), true);
    }

    /**
     * 失敗を次のように扱う。debugでは例外を投げ、本番ではKernelのロガーにerrorで記録する。
     * 文脈には、例外のクラス・投げられた場所・previousのクラスだけを入れる(要求に由来するパス・メッセージ・トレースは
     * 入れない)。
     *
     * @throws RuntimeException debugのとき
     */
    private function report(string $message, RuntimeException $exception): void
    {
        if ($this->kernel->config()->debug) {
            throw $exception;
        }

        $this->kernel->logger()->log(LogLevel::ERROR, 'tmswp.thumbnail: ' . $message, Thumbnail::exceptionContext($exception));
    }

    private static function notFound(): WP_Error
    {
        return new WP_Error('tmswp_thumb_not_found', 'Thumbnail source was not found.', ['status' => 404]);
    }

    private static function exists(string $path): bool
    {
        clearstatcache(true, $path);

        return is_file($path);
    }

    /**
     * @param bool $cacheable trueなら生成済みのファイルへの302(短期間だけキャッシュさせる)。falseなら元画像への302
     *                        (キャッシュさせない)
     */
    private function redirect(string $url, bool $cacheable): WP_REST_Response
    {
        return new WP_REST_Response(null, 302, [
            'Location' => $url,
            'Cache-Control' => $cacheable ? "public, max-age={$this->config->redirectMaxAge}" : 'no-store',
        ]);
    }

    private function stringParam(WP_REST_Request $request, string $key): string
    {
        $value = $request->get_param($key);

        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * @throws InvalidThumbSourceException 整数でない場合
     */
    private function integer(string $value): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidThumbSourceException('thumbnail dimensions must be integers');
        }

        return (int) $value;
    }
}
