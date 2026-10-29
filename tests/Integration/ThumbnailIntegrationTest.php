<?php

declare(strict_types=1);

namespace TMSWP\Thumbnail\Tests\Integration;

use ReflectionProperty;
use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Exception\RuntimeException;
use TMSWP\Core\Kernel\Discovery;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Testing\BootsKernelBeforeInit;
use TMSWP\Testing\IntegrationTestCase;
use TMSWP\Testing\RecordingLogger;
use TMSWP\Testing\UsesTempDirectories;
use TMSWP\Thumbnail\Exception\UnprocessableImageException;
use TMSWP\Thumbnail\RestController;
use TMSWP\Thumbnail\RestRouteCheck;
use TMSWP\Thumbnail\ThumbLock;
use TMSWP\Thumbnail\Thumbnail;
use TMSWP\Thumbnail\ThumbnailConfig;
use TMSWP\Thumbnail\UrlSigner;
use WP_Error;
use WP_Hook;
use WP_REST_Request;
use WP_REST_Response;

/**
 * 一時ディレクトリのプラグインのKernel(KernelConfigのextensionsにThumbnailを登録したもの)で、URLの生成・RESTのルートでの
 * 実画像の生成とキャッシュ・RESTのルートの無効化の検出を、実際のWordPressで確かめる。
 *
 * uploadsは、upload_dirのフィルタで一時ディレクトリに向ける(テストの後に消す)。元画像はGDで作る400x300のJPEG。
 */
final class ThumbnailIntegrationTest extends IntegrationTestCase
{
    use BootsKernelBeforeInit;
    use UsesTempDirectories;

    private const string BASE_URL = 'http://example.org/tmswp-thumbnail-uploads';

    private const string ROUTE = '/tmswp/v1/thumb';

    private string $uploads = '';

    private RecordingLogger $logger;

    /** wp_get_image_editor()がエディタを選んだ回数(wp_image_editorsのフィルタの適用の回数) */
    private int $editorLoads = 0;

    /** @var array<string, array{0: bool, 1: mixed}>|null RestRouteCheckが確かめている間の、nonceの4つの置き場 */
    private ?array $nonceSlotsDuringCheck = null;

    /** @var list<array{hook: string, priority: int, function: mixed, acceptedArgs: int}> 最後のbootがinitに登録したコールバック */
    private array $initCallbacks = [];

    public function set_up(): void
    {
        parent::set_up();
        $this->logger = new RecordingLogger();
        $this->uploads = $this->createTempDirectory('thumbnail-uploads');
        $uploads = $this->uploads;
        add_filter('upload_dir', static fn(array $dir): array => [
            ...$dir,
            'path' => $uploads,
            'url' => self::BASE_URL,
            'subdir' => '',
            'basedir' => $uploads,
            'baseurl' => self::BASE_URL,
        ]);
        add_filter('wp_image_editors', function (array $editors): array {
            ++$this->editorLoads;

            return $editors;
        });
        $GLOBALS['wp_rest_server'] = null;
    }

    public function tear_down(): void
    {
        // このテストのKernelの登録したルートを持つRESTのサーバーを、後のテストに残さない
        $GLOBALS['wp_rest_server'] = null;
        $this->unregisterAppAutoloaders();
        $this->cleanupTempDirectories();
        parent::tear_down();
    }

    /** アイキャッチの未設定・削除された添付ファイルは、debugでも例外にならずnullになる(データが無い)。 */
    public function testPostWithoutFeaturedImageReturnsNull(): void
    {
        $this->bootThumbnail();
        $post = self::createPost('post');

        self::assertSame(0, get_post_thumbnail_id($post));
        self::assertNull(tms_thumb(get_post_thumbnail_id($post), 100, 100));

        // アイキャッチのメタが、削除された添付ファイルを指している
        update_post_meta($post, '_thumbnail_id', 999999);
        self::assertSame('999999', get_post_meta($post, '_thumbnail_id', true));
        self::assertNull(tms_thumb(get_post_thumbnail_id($post), 100, 100));

        // 添付ファイルはあるが、実体が無い
        $missing = $this->createAttachment('missing/gone.jpg');
        unlink($this->uploads . '/missing/gone.jpg');
        self::assertNull(tms_thumb($missing, 100, 100));
        self::assertSame([], $this->logger->records);
    }

    /**
     * 初回はRESTのURLを返し、RESTのルートが実画像を生成して、生成したファイルへ短期間だけキャッシュさせる302を返す。
     * 生成の後は、tms_thumb()がキャッシュの静的なURLを直接返す。RESTが既存のファイルを返すたびに、更新日時を今にする。
     */
    public function testGeneratesThumbnailAndReusesCache(): void
    {
        $this->bootThumbnail();
        $attachment = $this->createAttachment('2026/10/source.jpg');

        $restUrl = tms_thumb($attachment, 100, 80);
        self::assertIsString($restUrl);
        self::assertSame(strtok(rest_url('tmswp/v1/thumb'), '?'), strtok($restUrl, '?'));
        self::assertSame(['src' => 'id:' . $attachment, 'w' => '100', 'h' => '80', 'o' => ''], array_diff_key($this->queryParams($restUrl), ['s' => true]));

        $response = $this->dispatch($restUrl);
        $location = $this->assertRedirect($response, 'public, max-age=3600');
        self::assertMatchesRegularExpression('#\A' . preg_quote(self::BASE_URL, '#') . '/tmswp-thumb/([0-9a-f]{2})/\1[0-9a-f]{38}\.jpg\z#', $location);
        $file = $this->uploads . substr($location, \strlen(self::BASE_URL));
        self::assertSame([100, 80], \array_slice($this->imageSize($file), 0, 2));
        self::assertSame(1, $this->editorLoads);
        // 一時ファイルとロックのファイルは残らない
        self::assertSame([basename($file), 'index.html'], $this->filesIn(\dirname($file)));
        self::assertFileExists($this->uploads . '/tmswp-thumb/index.html');

        self::assertSame($location, tms_thumb($attachment, 100, 80));

        self::assertTrue(touch($file, time() - 10 * 86400));
        clearstatcache();
        $again = $this->dispatch($restUrl);
        self::assertSame($location, $this->assertRedirect($again, 'public, max-age=3600'));
        clearstatcache();
        self::assertGreaterThan(time() - 60, filemtime($file));
        self::assertSame(1, $this->editorLoads);
    }

    /** 元画像より大きい要求は、元画像の寸法で頭打ちにして生成し、キャッシュする(毎回の再生成にならない)。 */
    public function testUpscaleRequestIsCappedAtSourceSizeAndCached(): void
    {
        $this->bootThumbnail();
        $attachment = $this->createAttachment('upscale/source.jpg');

        foreach ([[800, 600, true, [400, 300]], [1000, 0, false, [400, 300]], [800, 200, true, [400, 100]]] as [$width, $height, $crop, $expected]) {
            $url = tms_thumb($attachment, $width, $height, ['crop' => $crop]);
            self::assertIsString($url);
            $location = $this->assertRedirect($this->dispatch($url), 'public, max-age=3600');
            self::assertStringStartsWith(self::BASE_URL . '/tmswp-thumb/', $location);
            self::assertSame($expected, \array_slice($this->imageSize($this->uploads . substr($location, \strlen(self::BASE_URL))), 0, 2));

            $loads = $this->editorLoads;
            $this->assertRedirect($this->dispatch($url), 'public, max-age=3600');
            self::assertSame($loads, $this->editorLoads, "{$width}x{$height}の2回目の要求で再生成しました。");
        }
    }

    /**
     * 本番で画像エディタが元画像を扱えないときは、元画像へキャッシュさせない302を返し、Kernelのロガーに例外のクラス・
     * 場所・previousのクラスだけを記録する(元画像のパスは入れない)。失敗はネガティブキャッシュに残り、同じ要求で再生成も再記録もしない。
     */
    public function testPermanentFailureRedirectsToSourceWithoutCacheAndIsNotRetried(): void
    {
        $this->bootThumbnail(debug: false);
        $attachment = $this->createAttachment('broken/source.jpg');
        $url = tms_thumb($attachment, 100, 100);
        self::assertIsString($url);
        add_filter('wp_image_editors', '__return_empty_array', 20);

        $location = $this->assertRedirect($this->dispatch($url), 'no-store');

        self::assertSame(self::BASE_URL . '/broken/source.jpg', $location);
        self::assertCount(1, $this->logger->records);
        $record = $this->logger->records[0];
        self::assertSame('error', $record['level']);
        self::assertSame('tmswp.thumbnail: failed to generate a thumbnail; redirected to the source image', $record['message']);
        self::assertSame(['exceptionClass', 'exceptionAt', 'previousClasses'], array_keys($record['context']));
        self::assertSame(UnprocessableImageException::class, $record['context']['exceptionClass']);
        self::assertIsString($record['context']['exceptionAt']);
        self::assertStringContainsString('ThumbGenerator.php:', $record['context']['exceptionAt']);
        self::assertSame([], $record['context']['previousClasses']);
        self::assertSame(1, $this->editorLoads);

        $this->assertRedirect($this->dispatch($url), 'no-store');
        self::assertCount(1, $this->logger->records);
        self::assertSame(1, $this->editorLoads);
    }

    /** debugのKernelでは、生成の失敗を例外のまま投げる(登録したKernelのdebugに従う)。 */
    public function testFailureIsThrownWhenKernelIsDebug(): void
    {
        $this->bootThumbnail();
        $url = tms_thumb($this->createAttachment('debug/source.jpg'), 100, 100);
        self::assertIsString($url);
        add_filter('wp_image_editors', '__return_empty_array', 20);

        $this->expectException(UnprocessableImageException::class);

        $this->dispatch($url);
    }

    /** 同じキーを別のプロセスが生成中(ロックを持っている)なら、待たずに元画像へキャッシュさせない302を返す。 */
    public function testLockedKeyRedirectsToSourceWithoutCache(): void
    {
        $thumbnail = $this->bootThumbnail();
        $url = tms_thumb($this->createAttachment('locked/source.jpg'), 100, 100);
        self::assertIsString($url);
        $cache = $thumbnail->cache();
        $key = $cache->key('locked/source.jpg', (int) filemtime($this->uploads . '/locked/source.jpg'), 100, 100, true, 82);
        $cache->ensureDirectory($key);
        $lock = $cache->lock($key);
        self::assertInstanceOf(ThumbLock::class, $lock);

        try {
            self::assertSame(self::BASE_URL . '/locked/source.jpg', $this->assertRedirect($this->dispatch($url), 'no-store'));
            self::assertSame(0, $this->editorLoads);
        } finally {
            $lock->release();
        }

        $this->assertRedirect($this->dispatch($url), 'public, max-age=3600');
        self::assertSame(1, $this->editorLoads);
    }

    /**
     * 既定値以外のオプション(`o`に`&`・`=`が入る)と、`%`を含むsrcのURLが、署名不一致(403)にならない。値はURLエンコード
     * して埋め込まれ、受け取った側で1回デコードされて署名した値に戻る。
     */
    public function testNonDefaultOptionsAndPercentInSourceAreAccepted(): void
    {
        $this->bootThumbnail();
        $this->createImage('percent/a%b c.jpg', 400, 300);
        $src = self::BASE_URL . '/percent/' . rawurlencode('a%b c.jpg');

        $url = tms_thumb($src, 120, 90, ['crop' => false, 'quality' => 90]);

        self::assertIsString($url);
        self::assertStringContainsString('src=' . rawurlencode($src), $url);
        self::assertStringContainsString('o=crop%3D0%26quality%3D90', $url);
        $params = $this->queryParams($url);
        self::assertSame($src, $params['src']);
        self::assertSame('crop=0&quality=90', $params['o']);
        $location = $this->assertRedirect($this->dispatch($url), 'public, max-age=3600');
        self::assertStringStartsWith(self::BASE_URL . '/tmswp-thumb/', $location);
        self::assertSame([120, 90], \array_slice($this->imageSize($this->uploads . substr($location, \strlen(self::BASE_URL))), 0, 2));
    }

    /** 出力形式を変えるフィルタが効くと、save()が書いたファイル(拡張子の違うファイル)をキャッシュにする。 */
    public function testOutputFormatFilterUsesSavedPath(): void
    {
        $this->bootThumbnail();
        $attachment = $this->createAttachment('format/source.jpg');
        add_filter('image_editor_output_format', static fn(array $formats): array => [...$formats, 'image/jpeg' => 'image/webp']);
        self::assertTrue(wp_image_editor_supports(['mime_type' => 'image/webp']));
        // wp_image_editor_supports()もエディタを選ぶため、数え直す
        $this->editorLoads = 0;

        $url = tms_thumb($attachment, 100, 100);
        self::assertIsString($url);
        $location = $this->assertRedirect($this->dispatch($url), 'public, max-age=3600');

        self::assertStringEndsWith('.webp', $location);
        $file = $this->uploads . substr($location, \strlen(self::BASE_URL));
        self::assertSame('image/webp', $this->imageSize($file)['mime']);
        self::assertSame($location, tms_thumb($attachment, 100, 100));
        $this->assertRedirect($this->dispatch($url), 'public, max-age=3600');
        self::assertSame(1, $this->editorLoads);
    }

    /**
     * RESTのルートが訪問者から使えない(ルートの削除・訪問者への認証のエラー)ことを検出し、管理画面で管理者に通知する。
     * 使えるときと、管理者以外には通知しない。確かめた後は、現在のユーザーとcookieの認証の検査を元に戻す。
     */
    public function testDisabledRestRouteIsDetectedAndNotified(): void
    {
        $this->bootThumbnail();
        $admin = self::factory()->user->create(['role' => 'administrator']);
        self::assertIsInt($admin);
        wp_set_current_user($admin);

        self::assertTrue((new RestRouteCheck())->isAvailable());
        self::assertStringNotContainsString(self::ROUTE, $this->adminNotices());

        $removeRoute = static function (array $endpoints): array {
            unset($endpoints[self::ROUTE]);

            return $endpoints;
        };
        add_filter('rest_endpoints', $removeRoute);
        $GLOBALS['wp_rest_server'] = null;
        self::assertFalse((new RestRouteCheck())->isAvailable());
        self::assertStringContainsString('<code>' . self::ROUTE . '</code>', $this->adminNotices());
        remove_filter('rest_endpoints', $removeRoute);

        $blockVisitors = static fn(mixed $result): mixed => is_user_logged_in() ? $result : new WP_Error('rest_disabled', 'REST API is disabled.', ['status' => 401]);
        add_filter('rest_authentication_errors', $blockVisitors);
        self::assertStringContainsString('<code>' . self::ROUTE . '</code>', $this->adminNotices());
        self::assertSame($admin, get_current_user_id());
        self::assertSame(100, has_filter('rest_authentication_errors', 'rest_cookie_check_errors'));

        $editor = self::factory()->user->create(['role' => 'editor']);
        self::assertIsInt($editor);
        wp_set_current_user($editor);
        self::assertStringNotContainsString(self::ROUTE, $this->adminNotices());
    }

    /** ロケールがjaなら、init(priority 1)で読み込むja訳で、管理画面の通知が日本語になる。 */
    public function testNoticeIsTranslatedToJapanese(): void
    {
        $this->bootThumbnail();
        $admin = self::factory()->user->create(['role' => 'administrator']);
        self::assertIsInt($admin);
        wp_set_current_user($admin);
        $locale = static fn(): string => 'ja';
        add_filter('pre_determine_locale', $locale);
        add_filter('rest_authentication_errors', static fn(mixed $result): mixed => is_user_logged_in() ? $result : new WP_Error('rest_disabled', 'REST API is disabled.', ['status' => 401]));

        try {
            self::fireInit($this->initCallbacks);

            self::assertStringContainsString('REST APIのルート<code>' . self::ROUTE . '</code>を訪問者が使えないため、TMSWPのサムネイルを生成できません。', $this->adminNotices());
        } finally {
            remove_filter('pre_determine_locale', $locale);
            unload_textdomain('tmswp');
        }
    }

    /**
     * 解決の後に元画像が消えた(差し替え・削除と重なった)要求は、debugでも本番でも404になり、記録しない。
     */
    public function testSourceRemovedAfterResolveReturns404(): void
    {
        foreach ([true, false] as $debug) {
            $this->bootThumbnail($debug);
            $relative = 'vanish/' . ($debug ? 'debug' : 'production') . '.jpg';
            $url = tms_thumb($this->createAttachment($relative), 100, 100);
            self::assertIsString($url);
            rest_get_server();
            $file = $this->uploads . '/' . $relative;
            $calls = 0;
            // 解決の後、uploadsからの相対パスを作る前(RestController::handle()のThumbnail::uploads())に消す
            $vanish = static function (array $dir) use (&$calls, $file): array {
                if (self::calledFromHandle()) {
                    ++$calls;
                    unlink($file);
                }

                return $dir;
            };
            add_filter('upload_dir', $vanish, 20);

            try {
                $response = $this->dispatch($url);
            } finally {
                remove_filter('upload_dir', $vanish, 20);
            }

            self::assertSame(1, $calls);
            self::assertSame(404, $response->get_status());
            self::assertSame('tmswp_thumb_not_found', $response->as_error()?->get_error_code());
            self::assertSame([], $this->logger->records);
            $this->restoreFixtureKernels();
        }
    }

    /**
     * 本番で、元画像のURLを決める前の準備に失敗したら、Kernelのロガーに記録して500を返す(例外を要求の外へ漏らさない)。
     */
    public function testPreparationFailureIsLoggedAndAnsweredInProduction(): void
    {
        $this->bootThumbnail(debug: false);
        $url = tms_thumb($this->createAttachment('prepare/source.jpg'), 100, 100);
        self::assertIsString($url);
        rest_get_server();
        $calls = 0;
        // 解決の後のRestController::handle()のThumbnail::uploads()だけを壊す
        $broken = static function (array $dir) use (&$calls): array {
            if (!self::calledFromHandle()) {
                return $dir;
            }

            ++$calls;

            return [...$dir, 'basedir' => null];
        };
        add_filter('upload_dir', $broken, 20);

        try {
            $response = $this->dispatch($url);
        } finally {
            remove_filter('upload_dir', $broken, 20);
        }

        self::assertSame(1, $calls);
        self::assertSame(500, $response->get_status());
        self::assertSame('tmswp_thumb_failed', $response->as_error()?->get_error_code());
        self::assertCount(1, $this->logger->records);
        self::assertSame('tmswp.thumbnail: failed to prepare a thumbnail request', $this->logger->records[0]['message']);
        self::assertSame(['exceptionClass', 'exceptionAt', 'previousClasses'], array_keys($this->logger->records[0]['context']));
        self::assertSame(RuntimeException::class, $this->logger->records[0]['context']['exceptionClass']);
    }

    /**
     * RESTのルートの確認で、現在のユーザーを未ログインにする処理(set_current_userのフック)が例外を投げても、元の
     * ユーザーに戻す。
     */
    public function testRestRouteCheckRestoresUserWhenSwitchingThrows(): void
    {
        $this->bootThumbnail();
        $admin = self::factory()->user->create(['role' => 'administrator']);
        self::assertIsInt($admin);
        wp_set_current_user($admin);
        $throwForVisitor = static function (): void {
            if (get_current_user_id() === 0) {
                throw new \RuntimeException('set_current_user failed');
            }
        };
        add_action('set_current_user', $throwForVisitor);

        try {
            (new RestRouteCheck())->isAvailable();
            self::fail('set_current_userの例外が伝わりませんでした。');
        } catch (\RuntimeException $exception) {
            self::assertSame('set_current_user failed', $exception->getMessage());
        } finally {
            remove_action('set_current_user', $throwForVisitor);
        }

        self::assertSame($admin, get_current_user_id());
    }

    /**
     * 管理画面の要求のnonce(`_wpnonce`・`X-WP-Nonce`)があっても、訪問者として確かめるため「使える」と判定する。nonceと、
     * rest_authentication_errorsのコールバックの順序は変えない。
     */
    public function testRestRouteCheckIgnoresAdminNonceAndKeepsFilterOrder(): void
    {
        $this->bootThumbnail();
        $admin = self::factory()->user->create(['role' => 'administrator']);
        self::assertIsInt($admin);
        wp_set_current_user($admin);
        $afterCookieCheck = static fn(mixed $result): mixed => $result;
        add_filter('rest_authentication_errors', $afterCookieCheck, 100);
        $_REQUEST['_wpnonce'] = 'admin-action-nonce';
        $_SERVER['HTTP_X_WP_NONCE'] = 'admin-rest-nonce';
        $before = $this->callbacksAt('rest_authentication_errors', 100);

        try {
            self::assertTrue((new RestRouteCheck())->isAvailable());
            self::assertSame($before, $this->callbacksAt('rest_authentication_errors', 100));
            self::assertSame('admin-action-nonce', $_REQUEST['_wpnonce']);
            self::assertSame('admin-rest-nonce', $_SERVER['HTTP_X_WP_NONCE']);
            self::assertSame($admin, get_current_user_id());
        } finally {
            unset($_REQUEST['_wpnonce'], $_SERVER['HTTP_X_WP_NONCE']);
            remove_filter('rest_authentication_errors', $afterCookieCheck, 100);
        }
    }

    /**
     * nonceの4つの置き場(`$_GET`・`$_POST`・`$_REQUEST`の`_wpnonce`と`$_SERVER['HTTP_X_WP_NONCE']`)を、確かめる間は
     * 4つとも無い状態にし、終わったら元のとおりに戻す。元に無かったキーは消し、値がnullのキーはnullのまま残す。認証の
     * フィルタがキーを足して例外を投げた場合も、元のとおりに戻す。GET・POSTのnonceを見る認証のフィルタでも「使える」。
     */
    public function testRestRouteCheckHidesAndRestoresAllNonceSlots(): void
    {
        $this->bootThumbnail();
        $admin = self::factory()->user->create(['role' => 'administrator']);
        self::assertIsInt($admin);
        wp_set_current_user($admin);
        $probe = function (mixed $result): mixed {
            $this->nonceSlotsDuringCheck = $this->nonceSlots();

            return $result;
        };
        // GET・POSTのnonceがあれば、照合に失敗したとしてエラーにする認証のフィルタ(管理画面のnonceを残すと誤検知する)
        $checksRequestNonce = static fn(mixed $result): mixed => isset($_GET['_wpnonce']) || isset($_POST['_wpnonce']) ? new WP_Error('nonce_failed', 'Nonce check failed.', ['status' => 403]) : $result;
        add_filter('rest_authentication_errors', $probe, 5);
        add_filter('rest_authentication_errors', $checksRequestNonce, 6);
        $absent = ['get' => [false, null], 'post' => [false, null], 'request' => [false, null], 'server' => [false, null]];

        try {
            foreach (['元に無い' => null, '値がnull' => [null], '値がある' => ['admin-nonce']] as $case => $value) {
                $this->setNonceSlots($value);
                $before = $this->nonceSlots();
                $this->nonceSlotsDuringCheck = null;

                self::assertTrue((new RestRouteCheck())->isAvailable(), $case);
                self::assertSame($absent, $this->nonceSlotsDuringCheck, "{$case}: 確かめる間にnonceが残っています。");
                self::assertSame($before, $this->nonceSlots(), "{$case}: nonceが元に戻っていません。");
                self::assertSame($admin, get_current_user_id());
            }

            $addsAndThrows = static function (mixed $result): mixed {
                $_GET['_wpnonce'] = $_POST['_wpnonce'] = $_REQUEST['_wpnonce'] = $_SERVER['HTTP_X_WP_NONCE'] = 'added-during-check';

                // 前の認証のフィルタが判定しなかった(null)ときに、認証の処理が失敗する
                if ($result === null) {
                    throw new \RuntimeException('authentication failed');
                }

                return $result;
            };
            add_filter('rest_authentication_errors', $addsAndThrows, 7);

            foreach (['元に無い' => null, '値がある' => ['admin-nonce']] as $case => $value) {
                $this->setNonceSlots($value);
                $before = $this->nonceSlots();

                try {
                    (new RestRouteCheck())->isAvailable();
                    self::fail("{$case}: 認証のフィルタの例外が伝わりませんでした。");
                } catch (\RuntimeException $exception) {
                    self::assertSame('authentication failed', $exception->getMessage());
                }

                self::assertSame($before, $this->nonceSlots(), "{$case}: 例外の後にnonceが元に戻っていません。");
                self::assertSame($admin, get_current_user_id());
            }

            remove_filter('rest_authentication_errors', $addsAndThrows, 7);
        } finally {
            $this->setNonceSlots(null);
            remove_filter('rest_authentication_errors', $probe, 5);
            remove_filter('rest_authentication_errors', $checksRequestNonce, 6);
        }
    }

    /**
     * 署名の無い要求と、署名が値に合わない要求(改ざん・別の鍵・URLの生成のときと違う表記の値)は、生成せずに403になる。
     * 署名は受け取ったクエリの生の文字列で照合するため、`w=0100`のように整数としては同じ値でも一致しない。
     */
    public function testUnsignedOrMismatchedRequestIsRejectedWith403(): void
    {
        $this->bootThumbnail();
        $attachment = $this->createAttachment('signed/source.jpg');
        $other = $this->createAttachment('signed/other.jpg');
        $url = tms_thumb($attachment, 100, 80);
        self::assertIsString($url);
        $params = $this->queryParams($url);
        $signature = $params['s'];
        $withoutSignature = $params;
        unset($withoutSignature['s']);
        $cases = [
            '署名が無い' => [$withoutSignature, 'tmswp_thumb_unsigned'],
            '署名が16進の64文字でない' => [[...$params, 's' => 'abc'], 'tmswp_thumb_unsigned'],
            '署名の改ざん' => [[...$params, 's' => substr($signature, 0, -1) . (str_ends_with($signature, '0') ? '1' : '0')], 'tmswp_thumb_bad_signature'],
            '別の鍵の署名' => [[...$params, 's' => (new UrlSigner(UrlSigner::deriveKey('another-secret')))->sign('id:' . $attachment, 100, 80, '')], 'tmswp_thumb_bad_signature'],
            'srcの差し替え' => [[...$params, 'src' => 'id:' . $other], 'tmswp_thumb_bad_signature'],
            'wの別の表記(先頭の0)' => [[...$params, 'w' => '0100'], 'tmswp_thumb_bad_signature'],
            'wの別の表記(+)' => [[...$params, 'w' => '+100'], 'tmswp_thumb_bad_signature'],
            'hの変更' => [[...$params, 'h' => '81'], 'tmswp_thumb_bad_signature'],
            'oの追加' => [[...$params, 'o' => 'quality=90'], 'tmswp_thumb_bad_signature'],
        ];

        foreach ($cases as $case => [$query, $code]) {
            $response = $this->dispatchQuery($query);

            self::assertSame(403, $response->get_status(), $case);
            self::assertSame($code, $response->as_error()?->get_error_code(), $case);
        }

        self::assertSame(0, $this->editorLoads);
        self::assertDirectoryDoesNotExist($this->uploads . '/tmswp-thumb');

        // 同じ値の正しい署名なら生成する(拒否の理由が署名だけである)
        $this->assertRedirect($this->dispatchQuery($params), 'public, max-age=3600');
    }

    /**
     * 署名が正しくても、寸法(maxDimensionの超過・負・両方0)と`o`(qualityの範囲外・許可されないキー・形の誤り)の誤りは、
     * 生成せずに400になる(多層防御)。
     */
    public function testSignedRequestWithInvalidDimensionsOrOptionsIsRejectedWith400(): void
    {
        $this->bootThumbnail();
        $src = 'id:' . $this->createAttachment('invalid/source.jpg');
        $cases = [
            '幅がmaxDimensionを超える' => [2561, 100, ''],
            '高さがmaxDimensionを超える' => [100, 2561, ''],
            '幅が負' => [-1, 100, ''],
            '幅と高さが0' => [0, 0, ''],
            'qualityが0' => [100, 100, 'quality=0'],
            'qualityが101' => [100, 100, 'quality=101'],
            'qualityが整数でない' => [100, 100, 'quality=high'],
            'cropが0・1でない' => [100, 100, 'crop=2'],
            '許可されないキー' => [100, 100, 'format=webp'],
            '許可されたキーに混ぜた許可されないキー' => [100, 100, 'crop=0&format=webp'],
            '=の無い組' => [100, 100, 'crop'],
        ];

        foreach ($cases as $case => [$width, $height, $options]) {
            $response = $this->dispatchQuery(self::signedQuery($src, $width, $height, $options));

            self::assertSame(400, $response->get_status(), $case);
            self::assertSame('tmswp_thumb_bad_request', $response->as_error()?->get_error_code(), $case);
        }

        self::assertSame(0, $this->editorLoads);
        self::assertDirectoryDoesNotExist($this->uploads . '/tmswp-thumb');
    }

    /**
     * 署名が正しくても、uploadsの外を指すsrc(`..`の区間・同じ接頭辞の別のディレクトリ・ほかのホスト・uploadsの外の
     * URL・uploadsの外を指す添付ファイルのメタ・外を指すシンボリックリンク)と、許可されない拡張子・無い添付ファイルは、
     * 生成せずに404になる。
     */
    public function testSignedRequestForSourceOutsideUploadsReturns404(): void
    {
        $this->bootThumbnail();
        $this->createImage('inside/source.jpg', 400, 300);
        // uploadsと同じ接頭辞の別のディレクトリ(uploads-evil)と、その中の実在する画像
        $evil = $this->uploads . '-evil';
        self::assertTrue(mkdir($evil));
        self::assertTrue(copy($this->uploads . '/inside/source.jpg', $evil . '/secret.jpg'));
        self::assertTrue(symlink($evil . '/secret.jpg', $this->uploads . '/inside/link.jpg'));
        self::assertNotFalse(file_put_contents($this->uploads . '/inside/data.php', '<?php'));
        $outsideAttachment = $this->createAttachment('inside/attached.jpg');
        update_post_meta($outsideAttachment, '_wp_attached_file', $evil . '/secret.jpg');

        try {
            $cases = [
                '..の区間' => self::BASE_URL . '/inside/../../' . basename($evil) . '/secret.jpg',
                'エンコードした..の区間' => self::BASE_URL . '/inside/%2E%2E/%2E%2E/' . basename($evil) . '/secret.jpg',
                '同じ接頭辞の別のディレクトリのURL' => self::BASE_URL . '-evil/secret.jpg',
                'ほかのホストのURL' => 'https://evil.example/tmswp-thumbnail-uploads/inside/source.jpg',
                'uploadsの外のURL' => 'http://example.org/wp-content/themes/source.jpg',
                'uploadsの外を指す添付ファイルのメタ' => 'id:' . $outsideAttachment,
                'uploadsの外を指すシンボリックリンク' => self::BASE_URL . '/inside/link.jpg',
                '許可されない拡張子' => self::BASE_URL . '/inside/data.php',
                '無い添付ファイル' => 'id:999999',
            ];

            foreach ($cases as $case => $src) {
                $response = $this->dispatchQuery(self::signedQuery($src, 100, 100, ''));

                self::assertSame(404, $response->get_status(), $case);
                self::assertSame('tmswp_thumb_not_found', $response->as_error()?->get_error_code(), $case);
            }
        } finally {
            unlink($this->uploads . '/inside/link.jpg');
            unlink($evil . '/secret.jpg');
            rmdir($evil);
        }

        self::assertSame(0, $this->editorLoads);
        self::assertSame([], $this->logger->records);
        // 拒んだ要求は、キャッシュのディレクトリを作らない
        self::assertDirectoryDoesNotExist($this->uploads . '/tmswp-thumb');
    }

    /**
     * キャッシュのキーは、元画像の更新日時と、ThumbnailConfigの既定のcrop・qualityで変わる。元画像を更新した後と、
     * 既定値を変えた後は、生成済みのファイルを使わずに、署名付きのRESTのURLを返す(`o`は空のまま)。
     */
    public function testCacheKeyChangesWithSourceModificationTimeAndConfigDefaults(): void
    {
        $this->bootThumbnail();
        $attachment = $this->createAttachment('key/source.jpg');
        $source = $this->uploads . '/key/source.jpg';
        $restUrl = tms_thumb($attachment, 100, 100);
        self::assertIsString($restUrl);
        $location = $this->assertRedirect($this->dispatch($restUrl), 'public, max-age=3600');
        self::assertSame($location, tms_thumb($attachment, 100, 100));

        self::assertTrue(touch($source, (int) filemtime($source) + 60));
        clearstatcache();
        $afterUpdate = tms_thumb($attachment, 100, 100);
        self::assertIsString($afterUpdate);
        self::assertSame(strtok(rest_url('tmswp/v1/thumb'), '?'), strtok($afterUpdate, '?'));
        $updated = $this->assertRedirect($this->dispatch($afterUpdate), 'public, max-age=3600');
        self::assertNotSame($location, $updated);
        self::assertSame(2, $this->editorLoads);
        self::assertSame($updated, tms_thumb($attachment, 100, 100));

        foreach (['defaultQuality' => new ThumbnailConfig(defaultQuality: 60), 'defaultCrop' => new ThumbnailConfig(defaultCrop: false)] as $case => $config) {
            $this->restoreFixtureKernels();
            $this->bootThumbnail(config: $config);
            $url = tms_thumb($attachment, 100, 100);

            self::assertIsString($url, $case);
            self::assertSame(strtok(rest_url('tmswp/v1/thumb'), '?'), strtok($url, '?'), $case);
            self::assertSame('', $this->queryParams($url)['o'], $case);
        }
    }

    /** thumbnailを登録できるKernelはサイトに1つ。2つ目のKernelのbootは例外になり、tms_thumb()は1つ目に従う。 */
    public function testSecondKernelCannotRegisterThumbnail(): void
    {
        $first = $this->bootThumbnail();

        try {
            $this->bootThumbnail(debug: false);
            self::fail('2つ目のKernelへのthumbnailの登録が例外になりませんでした。');
        } catch (LogicException $exception) {
            self::assertStringContainsString('thumbnail is already registered to the kernel', $exception->getMessage());
        }

        self::assertSame($first, Thumbnail::current());
        self::assertTrue($first->kernel()->config()->debug);
    }

    /**
     * 一時ディレクトリのプラグインのKernel(Thumbnailを登録)をinitより前にbootする。
     *
     * @param bool $debug falseなら本番のboot(探索結果ファイルを先に作る)
     * @param ThumbnailConfig|null $config nullなら既定の設定
     */
    private function bootThumbnail(bool $debug = true, ?ThumbnailConfig $config = null): Thumbnail
    {
        $root = $this->createTempDirectory('integration-thumbnail');
        $this->writeFile($root . '/plugin.php', "<?php\n");
        self::assertTrue(mkdir($root . '/app'));
        $logger = $this->logger;
        $thumbnail = new Thumbnail($config ?? new ThumbnailConfig());
        $kernelConfig = KernelConfig::plugin(
            $root . '/plugin.php',
            $root . '/app',
            'TmswpIntegrationThumbnail' . uniqid(),
            $debug,
            [$thumbnail],
            static fn(): array => ['integration' => new LogChannel($logger)],
        );

        if (!$debug) {
            (new DiscoveryFile($kernelConfig))->write((new Discovery($kernelConfig, new DiscoveryFile($kernelConfig)))->scan());
        }

        $this->initCallbacks = $this->bootBeforeInit($kernelConfig);

        return $thumbnail;
    }

    /** uploadsの下に400x300のJPEGを作り、その添付ファイルのIDを返す。 */
    private function createAttachment(string $relative): int
    {
        $path = $this->createImage($relative, 400, 300);
        $attachment = wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => basename($relative), 'post_status' => 'inherit'], $path);
        self::assertIsInt($attachment);
        self::assertGreaterThan(0, $attachment);
        self::assertSame($relative, get_post_meta($attachment, '_wp_attached_file', true));

        return $attachment;
    }

    /**
     * uploadsの下に、単色のJPEGを作る。
     *
     * @param positive-int $width
     * @param positive-int $height
     */
    private function createImage(string $relative, int $width, int $height): string
    {
        $path = $this->uploads . '/' . $relative;

        if (!is_dir(\dirname($path))) {
            self::assertTrue(mkdir(\dirname($path), 0775, true));
        }

        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        self::assertTrue(imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 200, 80, 40)));
        self::assertTrue(imagejpeg($image, $path, 90));

        return $path;
    }

    /**
     * URLのクエリを、PHPが$_GETを作るのと同じ方法で分解する(rest_routeは除く)。
     *
     * @return array<string, string>
     */
    private function queryParams(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY);
        self::assertIsString($query);
        parse_str($query, $parsed);
        $params = [];

        foreach ($parsed as $name => $value) {
            self::assertIsString($value);

            if ($name !== 'rest_route') {
                $params[(string) $name] = $value;
            }
        }

        return $params;
    }

    private function dispatch(string $url): WP_REST_Response
    {
        return $this->dispatchQuery($this->queryParams($url));
    }

    /**
     * RESTのルートに、クエリ(受け取った側の$_GETにあたる、デコード済みの値)を渡して要求する。
     *
     * @param array<string, string> $query
     */
    private function dispatchQuery(array $query): WP_REST_Response
    {
        $request = new WP_REST_Request('GET', self::ROUTE);
        $request->set_query_params($query);

        return rest_do_request($request);
    }

    /**
     * サイトの鍵(wp_salt('auth')から導出したもの)で署名した、RESTのルートのクエリ。
     *
     * @return array<string, string>
     */
    private static function signedQuery(string $src, int $width, int $height, string $options): array
    {
        $salt = wp_salt('auth');
        self::assertNotSame('', $salt);

        return [
            'src' => $src,
            'w' => (string) $width,
            'h' => (string) $height,
            'o' => $options,
            's' => (new UrlSigner(UrlSigner::deriveKey($salt)))->sign($src, $width, $height, $options),
        ];
    }

    /**
     * 302であることと、Cache-Controlを確かめて、Locationを返す。
     */
    private function assertRedirect(WP_REST_Response $response, string $cacheControl): string
    {
        self::assertSame(302, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $headers = $response->get_headers();
        self::assertSame($cacheControl, $headers['Cache-Control'] ?? null);
        $location = $headers['Location'] ?? null;
        self::assertIsString($location);

        return $location;
    }

    /**
     * @return array{0: int, 1: int, mime: string}
     */
    private function imageSize(string $file): array
    {
        self::assertFileExists($file);
        $size = getimagesize($file);
        self::assertIsArray($size);

        return [$size[0], $size[1], 'mime' => $size['mime']];
    }

    /** @return list<string> ディレクトリの中のファイル名(昇順) */
    private function filesIn(string $dir): array
    {
        $files = scandir($dir);
        self::assertIsArray($files);

        return array_values(array_diff($files, ['.', '..']));
    }

    /** upload_dirのフィルタが、RestController::handle()から直接呼んだThumbnail::uploads()の中で動いているか。 */
    private static function calledFromHandle(): bool
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        foreach ($frames as $index => $frame) {
            if (($frame['class'] ?? null) === Thumbnail::class && $frame['function'] === 'uploads') {
                $caller = $frames[$index + 1] ?? null;

                return $caller !== null && ($caller['class'] ?? null) === RestController::class && $caller['function'] === 'handle';
            }
        }

        return false;
    }

    /**
     * nonceの4つの置き場の、キーの有無と値。
     *
     * @return array{get: array{0: bool, 1: mixed}, post: array{0: bool, 1: mixed}, request: array{0: bool, 1: mixed}, server: array{0: bool, 1: mixed}}
     */
    private function nonceSlots(): array
    {
        return [
            'get' => [\array_key_exists('_wpnonce', $_GET), $_GET['_wpnonce'] ?? null],
            'post' => [\array_key_exists('_wpnonce', $_POST), $_POST['_wpnonce'] ?? null],
            'request' => [\array_key_exists('_wpnonce', $_REQUEST), $_REQUEST['_wpnonce'] ?? null],
            'server' => [\array_key_exists('HTTP_X_WP_NONCE', $_SERVER), $_SERVER['HTTP_X_WP_NONCE'] ?? null],
        ];
    }

    /**
     * nonceの4つの置き場を、nullなら無い状態に、`[値]`ならその値(nullを含む)にする。
     *
     * @param array{0: string|null}|null $value
     */
    private function setNonceSlots(?array $value): void
    {
        unset($_GET['_wpnonce'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'], $_SERVER['HTTP_X_WP_NONCE']);

        if ($value !== null) {
            $_GET['_wpnonce'] = $_POST['_wpnonce'] = $_REQUEST['_wpnonce'] = $_SERVER['HTTP_X_WP_NONCE'] = $value[0];
        }
    }

    /**
     * フックのpriorityのコールバックを、登録の順に返す(WordPressのIDの一覧)。
     *
     * @return list<string>
     */
    private function callbacksAt(string $hook, int $priority): array
    {
        $filters = $GLOBALS['wp_filter'];
        self::assertIsArray($filters);
        $wpHook = $filters[$hook] ?? null;
        self::assertInstanceOf(WP_Hook::class, $wpHook);
        $callbacks = $wpHook->callbacks[$priority] ?? [];
        self::assertIsArray($callbacks);

        return array_map(strval(...), array_keys($callbacks));
    }

    /** テストがbootしたKernelをレジストリから外す(1つのテストの中で、thumbnailを登録したKernelをbootし直すため)。 */
    private function restoreFixtureKernels(): void
    {
        $fixtures = array_intersect_key(Kernel::all(), [self::THEME_NAMESPACE => true, self::PLUGIN_NAMESPACE => true]);
        (new ReflectionProperty(Kernel::class, 'kernels'))->setValue(null, $fixtures);
        $GLOBALS['wp_rest_server'] = null;
    }

    private function adminNotices(): string
    {
        ob_start();

        try {
            do_action('admin_notices');
        } finally {
            $output = ob_get_clean();
        }

        self::assertIsString($output);

        return $output;
    }
}
