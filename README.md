# tmswp/thumbnail

TMStack WordPress Framework(TMSWP)の拡張パッケージで、署名付きのURLでサムネイルを生成します。元画像はuploadsの下の画像(添付ファイルのIDかURL)だけで、外部のURLは扱いません。生成した画像は`uploads/tmswp-thumb/`にキャッシュし、2回目からは静的なファイルとして返します。

## 動作環境

- PHP 8.3〜8.5(`ext-gd`。composer.jsonの`require`)、WordPress 7.0以上
- tmswp/core `^0.9`(composer.jsonの`require`)
- **REST APIが使えること**。未生成のサムネイルのURLは、RESTのルート`/wp-json/tmswp/v1/thumb`を指します。REST以外の経路はありません。ログインしていない訪問者からこのルートが使えないと(REST APIを無効化するプラグイン等)、未生成のサムネイルは表示されません。thumbnailは管理画面の表示のたびにこれを確かめ、使えなければ`manage_options`の権限のユーザーに通知します。Webサーバーの設定による`/wp-json/`の遮断は検出できません。
- **uploadsがローカルのファイルシステムにあること**。外部ストレージ(オブジェクトストレージへのオフロード等)には対応しません。キャッシュの書き込みに、同じディレクトリの中の`rename()`と`flock()`を使います。
- **thumbnailを登録できるKernelは、サイトに1つ**です。テーマとプラグインの両方に登録すると、2つ目のKernelの起動が`LogicException`で止まります。

## 提供するもの

| 名前 | 役割 |
|---|---|
| `TMSWP\Thumbnail\Thumbnail` | `KernelConfig`の`extensions`に渡す拡張。RESTのルート・管理画面の通知・WP-CLIのコマンドを登録する |
| `TMSWP\Thumbnail\ThumbnailConfig` | 寸法の上限・既定の品質・既定の切り抜き・キャッシュのディレクトリ名・302のキャッシュの秒数 |
| `tms_thumb()` | サムネイルのURLを返すグローバル関数(Composerの`autoload.files`で定義) |
| `wp tmswp thumb clear\|status` | キャッシュの削除と集計 |

## 導入

1. `composer require tmswp/thumbnail`を実行します。
2. テーマ・プラグインのどちらか1つの`tmswp-kernel.php`で、`extensions`に`Thumbnail`を渡します。

```php
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Thumbnail\Thumbnail;
use TMSWP\Thumbnail\ThumbnailConfig;

return KernelConfig::theme(
    __DIR__ . '/app',
    'App',
    extensions: [new Thumbnail(new ThumbnailConfig(defaultQuality: 80))],
);
```

## 設定

`ThumbnailConfig`の項目は、`maxDimension`(幅・高さの上限。既定2560)、`defaultQuality`(既定82)、`defaultCrop`(既定true)、`cacheDirName`(uploadsの直下の1つの名前。英数字で始まり、英数字・`.`・`_`・`-`だけ。既定`tmswp-thumb`。uploadsの既存のディレクトリ(年のディレクトリ等)と重ならない名前にする)、`redirectMaxAge`(生成済みのファイルへの302をキャッシュさせる秒数。既定3600)です。範囲・形の外の値は例外になります。

キャッシュのディレクトリには、初回の作成のときに印のファイル`.tmswp-thumb-cache`を置きます。印の無い空でない既存のディレクトリは、キャッシュとして使わず、生成・`wp tmswp thumb clear`・`status`が例外になります(`cacheDirName`がuploadsの既存のディレクトリと重なったときに、そこにあるファイルを消さないためです)。

## 使い方

```php
<?php $thumb = tms_thumb((int) get_post_thumbnail_id(), 400, 300); ?>
<?php if ($thumb !== null) : ?>
<img src="<?php echo esc_url($thumb); ?>" alt="">
<?php endif; ?>
```

- `tms_thumb($src, $width, $height, $options)`の`$src`は、添付ファイルのIDか、uploadsの下の画像のURLです。`$options`は`crop`(bool)と`quality`(int 1〜100)だけです。
- 画像が無いとき(アイキャッチの未設定、添付ファイルやその実体が無い)は`null`を返します。
- 引数とオプションの誤り(uploadsの外のURL、許可されない拡張子、寸法、オプション)は、例外になります。
- 実行時の失敗は、登録したKernelがdebugなら例外、本番ならそのKernelのロガーに記録して`null`を返します。
- 元画像より大きい寸法は、元画像の寸法で頭打ちになります(拡大しません)。
- RESTのルートはログインを求めず、URLの署名(鍵は`wp_salt('auth')`から導出)で要求を確かめます。saltを変えると、生成前のURLは403になります。

## キャッシュの削除(WP-CLI)

```sh
wp tmswp thumb clear --days=30   # 最後にREST経由で返してから30日より前のファイルを削除
wp tmswp thumb clear             # すべて削除
wp tmswp thumb status            # 件数・合計サイズ・扱えなかった元画像の数
```

`--days`は正の整数で、`redirectMaxAge`より長くなければなりません。ページキャッシュを使うサイトでは、ページキャッシュの寿命より長くしてください(HTMLに残った静的なURLへのアクセスは、削除の判定に使われないため)。削除は自動では実行されません。サーバーのcron等で定期的に実行してください。

## レシピ

- サムネイルのURLを作る(引数・設定・キャッシュ・WP-CLI・RESTとセキュリティの注意): `vendor/tmswp/thumbnail/rules/thumbnail.md`
- 拡張の登録の仕方と制約: `vendor/tmswp/core/rules/extensions.md`

設計の正本は、TMSWPのmonorepo([tmstack-io/tmswp](https://github.com/tmstack-io/tmswp))の`docs/08-extensions.md`の§1です。

## ライセンス

GPL-2.0-or-later(`LICENSE`)
