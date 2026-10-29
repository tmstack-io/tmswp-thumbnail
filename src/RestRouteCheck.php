<?php

declare(strict_types=1);

namespace TMSWP\Thumbnail;

use WP_Error;
use WP_REST_Request;

/**
 * thumbnailのRESTのルートが、訪問者(ログインしていないユーザー)から使えるかを確かめ、使えなければ管理画面で管理者に
 * 知らせる。thumbnailはREST API以外の経路を持たないため、ルートが使えないと未生成のサムネイルが表示されない。
 *
 * 確かめるのは、PHPの中で検出できる無効化に限る。
 * - `rest_authentication_errors`のフィルタが、訪問者の要求をエラーにする(REST APIを無効化するプラグインの多く)
 * - ルートが無い、または`rest_pre_dispatch`等で、ルートのコールバックまで届かない(`rest_endpoints`のフィルタによる削除等)
 *
 * Webサーバーの設定による`/wp-json/`の遮断は、PHPに届かないため検出しない。
 */
final class RestRouteCheck
{
    private const string NOTICE_CAPABILITY = 'manage_options';

    /**
     * 管理画面の通知(admin_noticesで呼ぶ)。通知を見せる権限のあるユーザーのときだけ確かめる。
     */
    public function notice(): void
    {
        if (!current_user_can(self::NOTICE_CAPABILITY) || $this->isAvailable()) {
            return;
        }

        $route = '/' . RestController::NAMESPACE . RestController::ROUTE;
        wp_admin_notice(
            \sprintf(
                /* translators: %s: REST API route */
                __('TMSWP thumbnails cannot be generated because the REST API route %s is not available to visitors. Allow the REST API for this route.', 'tmswp'),
                '<code>' . esc_html($route) . '</code>',
            ),
            ['type' => 'error'],
        );
    }

    /**
     * 訪問者として、REST APIの認証のフィルタを通り、ルートのコールバックまで届くか。
     *
     * 確かめる間だけ、現在のユーザーを未ログインにし、要求のnonce(`$_GET`・`$_POST`・`$_REQUEST`の`_wpnonce`と、
     * `$_SERVER['HTTP_X_WP_NONCE']`)を4つとも無い状態にする。訪問者の要求にはnonceが無い。WordPressのcookieの認証の
     * 検査(rest_cookie_check_errors)は、未ログインでもnonceがあれば照合してエラーを返すため、管理画面の要求のnonceを
     * 残すと誤って「使えない」と判定する。フィルタは外さないので、登録の順序は変わらない。
     *
     * 終わったら(例外のときも含めて)、ユーザーを戻し、nonceは元にあったキーを元の値(nullを含む)に戻し、元に無かった
     * キー(確かめる間に足されたもの)を消す。
     */
    public function isAvailable(): bool
    {
        $userId = get_current_user_id();
        $saved = [
            'get' => self::saved($_GET, '_wpnonce'),
            'post' => self::saved($_POST, '_wpnonce'),
            'request' => self::saved($_REQUEST, '_wpnonce'),
            'server' => self::saved($_SERVER, 'HTTP_X_WP_NONCE'),
        ];

        try {
            unset($_GET['_wpnonce'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'], $_SERVER['HTTP_X_WP_NONCE']);
            wp_set_current_user(0);

            if (is_wp_error(apply_filters('rest_authentication_errors', null))) {
                return false;
            }

            // 署名の無い要求は、ルートのコールバックがtmswp_thumb_unsigned(403)で返す
            $error = rest_do_request(new WP_REST_Request('GET', '/' . RestController::NAMESPACE . RestController::ROUTE))->as_error();

            return $error instanceof WP_Error && $error->get_error_code() === 'tmswp_thumb_unsigned';
        } finally {
            self::restore($_GET, '_wpnonce', $saved['get']);
            self::restore($_POST, '_wpnonce', $saved['post']);
            self::restore($_REQUEST, '_wpnonce', $saved['request']);
            self::restore($_SERVER, 'HTTP_X_WP_NONCE', $saved['server']);
            wp_set_current_user($userId);
        }
    }

    /**
     * キーの有無と値を控える。
     *
     * @param array<mixed> $values
     *
     * @return array{exists: bool, value: mixed}
     */
    private static function saved(array $values, string $key): array
    {
        return ['exists' => \array_key_exists($key, $values), 'value' => $values[$key] ?? null];
    }

    /**
     * 控えたとおりに戻す。元にあったキーは元の値に、元に無かったキーは消す。
     *
     * @param array<mixed> $values
     * @param array{exists: bool, value: mixed} $saved
     */
    private static function restore(array &$values, string $key, array $saved): void
    {
        if ($saved['exists']) {
            $values[$key] = $saved['value'];
        } else {
            unset($values[$key]);
        }
    }
}
