# サムネイルのURLを作る(tmswp/thumbnail)

`tms_thumb()`で、uploadsの画像から指定した寸法のサムネイルのURLを作る。URLは署名付きで、初回の表示で生成し、2回目からはuploadsの下にキャッシュした静的なファイルを返す。キャッシュの削除はWP-CLIの`wp tmswp thumb clear`で行う。
例の名前空間`App\`は、プロジェクトの名前空間(`tmswp-kernel.php`の`KernelConfig`の`appNamespace`)に読み替える。

- 元画像は、添付ファイルのIDか、uploadsの下の画像のURLだけである(拡張子は`jpg`・`jpeg`・`png`・`gif`・`webp`・`avif`)。外部のURLは扱わない。
- 使う前提: 訪問者(ログインしていないユーザー)がREST APIのルート`/wp-json/tmswp/v1/thumb`を使えること、uploadsがローカルのファイルシステムにあること、PHPの`ext-gd`があること。
- thumbnailを登録できるKernelは、サイトに1つだけである。テーマとプラグインの両方でTMSWPを使うときは、どちらか一方の`tmswp-kernel.php`にだけ`new Thumbnail()`を渡す。`tms_thumb()`は、どのテーマ・プラグインのテンプレートからも呼べる。

## 手順

1. `composer require tmswp/thumbnail`を実行する。
2. `tmswp-kernel.php`の`KernelConfig::theme()` / `KernelConfig::plugin()`の`extensions`に`new Thumbnail()`を渡す。品質等を変えるときは`new Thumbnail(new ThumbnailConfig(...))`にする(下の「設定」)。
3. テンプレートで`tms_thumb($src, $width, $height, $options)`を呼び、`null`でないときだけ`<img>`を出す(下の「テンプレートでの使い方」)。
4. 本番のサーバーで、`wp tmswp thumb clear --days=<日数>`を定期的に実行する(下の「キャッシュの削除」)。
5. 管理画面に、REST APIのルートを使えないことの通知が出ていないことを確かめる。

## 完全な実装例

`tmswp-kernel.php`(テーマ):

```php
<?php

declare(strict_types=1);

use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Thumbnail\Thumbnail;
use TMSWP\Thumbnail\ThumbnailConfig;

return KernelConfig::theme(
    __DIR__ . '/app',
    'App',
    extensions: [
        new Thumbnail(new ThumbnailConfig(defaultQuality: 80)),
    ],
);
```

`parts/news-card.php`(`Template::part('parts/news-card', ['post' => $post])`で読むテンプレートパーツ):

```php
<?php

declare(strict_types=1);

use TMSWP\Core\Exception\LogicException;

// Template::part()のテンプレートから見える変数は$argsだけ。型を確かめてから使う
$post = $args['post'] ?? null;

if (!$post instanceof WP_Post) {
    throw new LogicException("parts/news-card.php requires a WP_Post in \$args['post']");
}

// get_post_thumbnail_id()は投稿が無いとfalseを返すので、intにしてから渡す(0は「画像が無い」)
$imageId = (int) get_post_thumbnail_id($post);
$thumb = tms_thumb($imageId, 480, 320);
$thumb2x = tms_thumb($imageId, 960, 640);
?>
<article class="news-card">
    <a href="<?php echo esc_url((string) get_permalink($post)); ?>">
        <?php if ($thumb !== null) : ?>
            <img
                src="<?php echo esc_url($thumb); ?>"
                <?php if ($thumb2x !== null) : ?>srcset="<?php echo esc_url($thumb2x); ?> 2x"<?php endif; ?>
                alt=""
                width="480"
                height="320"
                loading="lazy"
            >
        <?php endif; ?>
        <span class="news-card__title"><?php echo esc_html(get_the_title($post)); ?></span>
    </a>
</article>
```

### `tms_thumb()`の引数と戻り値

`tms_thumb(int|string $src, ?int $width = null, ?int $height = null, array $options = []): ?string`

| 引数 | 意味 |
|---|---|
| `$src` | 添付ファイルのID(`(int) get_post_thumbnail_id()`等)か、uploadsの下の画像のURL。`0`と`''`は「画像が無い」。負のIDは`InvalidThumbSourceException` |
| `$width`・`$height` | 出力の幅・高さ(px)。片方を`null`か`0`にすると、もう片方と元画像の縦横比で決まる。両方を`0`(`null`)にはできない。`ThumbnailConfig`の`maxDimension`(既定2560)を超えられない |
| `$options` | `crop`(bool。既定は`defaultCrop`の`true`。幅と高さを両方指定したときに切り抜くか)と`quality`(1〜100のint。既定は`defaultQuality`)だけ |

- 戻り値は、生成済みならキャッシュの静的なURL(`/wp-content/uploads/tmswp-thumb/{2文字}/{キー}.{拡張子}`)、未生成なら署名付きのRESTのURL(`/wp-json/tmswp/v1/thumb?src=…&w=…&h=…&o=…&s=…`。パーマリンクの設定が基本なら`/?rest_route=/tmswp/v1/thumb&…`)である。RESTのURLを開くと生成して、キャッシュのファイルへ302でリダイレクトする。
- 画像が無いとき(アイキャッチの未設定、添付ファイルやその実体が無い)は`null`を返す。
- 元画像より大きい寸法は、元画像の寸法で頭打ちになる(拡大しない)。
- 引数とオプションの誤り(uploadsの外・許可されない拡張子のURL、負の値・上限を超える寸法、`crop`・`quality`以外のキー、型・範囲の違う値)は、debugに関係なく例外(`InvalidThumbSourceException`・`InvalidThumbOptionException`)になる。
- 実行時の失敗(元画像の更新日時が読めない等)は、thumbnailを登録したKernelがdebugなら例外、本番ならそのKernelのロガーにerrorで記録して`null`を返す。
- thumbnailを登録したKernelが無い状態で呼ぶと、`LogicException`になる。

### 設定

`new ThumbnailConfig(...)`の引数(名前付き引数で必要なものだけを渡す)。範囲・形の外の値は、`tmswp-kernel.php`を読んだ時点で`InvalidArgumentException`になる。

| 引数 | 既定 | 意味 |
|---|---|---|
| `maxDimension` | `2560` | 幅・高さの上限(px。1以上) |
| `defaultQuality` | `82` | `quality`を指定しないときの品質(1〜100) |
| `defaultCrop` | `true` | `crop`を指定しないときに切り抜くか |
| `cacheDirName` | `tmswp-thumb` | uploadsの直下のキャッシュのディレクトリ名。英数字で始まり、英数字・`.`・`_`・`-`だけの1つの名前 |
| `redirectMaxAge` | `3600` | RESTのURLからキャッシュのファイルへの302を、ブラウザ等にキャッシュさせる秒数(1以上) |

- `defaultQuality`・`defaultCrop`を変えると、オプションを省いた呼び出しのキャッシュのキーも変わり、次の表示で作り直す。古いファイルは`wp tmswp thumb clear --days`で消える。
- 元画像を差し替えると(更新日時が変わると)、別のキーになり作り直す。

### キャッシュの置き場と目印のファイル

- キャッシュは`{uploads}/{cacheDirName}/{キーの先頭2文字}/`に置く。生成したサムネイルが`{キー}.{拡張子}`、生成できない元画像の印が`{キー}.failed`、生成中のロックが`{キー}.lock`である。各ディレクトリに空の`index.html`を置く。
- キャッシュのディレクトリを作るときに、目印のファイル`.tmswp-thumb-cache`を置く。目印の無い空でない既存のディレクトリは、キャッシュとして使わない。生成・`wp tmswp thumb clear`・`status`が例外になり、そのディレクトリのファイルを消さない(`cacheDirName`をuploadsの既存のディレクトリ(年のディレクトリ等)と同じ名前にしたときに、ファイルを消さないため)。
- キャッシュのディレクトリとその下のディレクトリが、シンボリックリンク等でuploadsの外を指していると、例外になる。
- 画像エディタが読めない・リサイズできない元画像は、`{キー}.failed`を書き、以後は生成を試さずに元画像へ302する(キーが変わるか、印が消えると1回だけ試し直す)。

### キャッシュの削除(WP-CLI)

```sh
wp tmswp thumb clear --days=30   # 最後にREST経由で返して(生成して)から30日より前のファイルを消す
wp tmswp thumb clear             # キャッシュのファイルをすべて消す
wp tmswp thumb status            # サムネイルの件数(files)・合計のバイト数(bytes)・生成できなかった元画像の数(failed)
```

- RESTのURLがキャッシュのファイルを返すたびに、そのファイルの更新日時を今にする。`--days=<n>`は、更新日時がn日より前のファイル(サムネイル・失敗の印・残った一時ファイル)だけを消す。`index.html`と目印のファイルは消さない。生成中のファイルは飛ばす。
- `--days`は正の整数で、n日が`redirectMaxAge`秒より長くなければならない。違えば何も消さずに`Error:`を出し、終了コード1で終わる。値の無い`--days`もエラーになる。
- 静的なURLへの直接のアクセスは、更新日時を変えない。ページキャッシュ(WP Super Cache等)を使うサイトでは、HTMLに残った静的なURLが消したファイルを指さないよう、`--days`をページキャッシュの寿命より長くする。消したファイルは、次の`tms_thumb()`がRESTのURLを返して作り直す。
- thumbnailは削除を自動で実行しない(WP-Cronの予約を持たない)。サーバーのcron等で定期的に実行する。例(crontab。毎日4時。パスはサイトに合わせる):

```sh
0 4 * * * cd /var/www/html && wp tmswp thumb clear --days=30 --quiet
```

### RESTのルートとセキュリティ

- RESTのルート`GET /wp-json/tmswp/v1/thumb`は、ログインを求めない(`permission_callback`は常に許可)。要求の正しさは、URLの署名(HMAC-SHA256)で確かめる。nonce・cookieの認証は使わない。署名が無い・合わないURLは403、署名の後の寸法・オプション(`w`・`h`・`o`)の誤りは400、元画像が無い・`src`がuploadsの外等を指すときは404を返し、画像を生成しない。本番で元画像のURLを決める前に失敗したときは500を返す。
- 署名の鍵は`wp_salt('auth')`から導出する。`wp-config.php`の`AUTH_KEY`・`AUTH_SALT`を変えると、生成前のRESTのURL(ページキャッシュに残ったHTMLの中のもの等)はすべて403になる。saltを変えたら、ページキャッシュも消す。生成済みのキャッシュのファイルの配信には影響しない。
- 管理画面の表示ごとに、訪問者としてRESTのルートが使えるかを確かめ、使えなければ`manage_options`の権限のユーザーに「REST APIのルート…を訪問者が使えないため、TMSWPのサムネイルを生成できません。」と通知する。REST APIを無効にするプラグイン等が原因なら、このルートを許可する。Webサーバーの設定による`/wp-json/`の遮断は検出できない。
- 生成したサムネイルは、uploadsの下の静的なファイルで、URLを知っていれば誰でも読める。
- 生成が失敗したとき・同じキーを別の要求が生成中のとき・本番の実行時の失敗のときは、元画像のURLへ、キャッシュさせない302を返す。

## テンプレートでの使い方

- `tms_thumb()`の戻り値は、`null`を分岐してから`esc_url()`で出力する(上の「完全な実装例」)。
- アイキャッチは`(int) get_post_thumbnail_id($post)`を渡す。添付ファイルのIDが分かっているなら、`wp_get_attachment_url()`のURLではなくIDを渡す。
- 寸法は、テンプレートに書いた固定の値にする。表示に使う寸法の組み合わせの数だけ、元画像ごとに生成が起きる。
- `<img>`の`width`・`height`・`srcset`を出すヘルパは無い。2倍の寸法を別の`tms_thumb()`で作り、`srcset`は自分で組み立てる(上の例)。

## してはいけないこと

- `$_GET`・`$_POST`・ブロックの属性等、利用者が変えられる値を`$src`・寸法・`$options`にそのまま渡さない。引数の誤りは本番でも例外になる。寸法を利用者に選ばせると、任意の寸法の署名付きURLを作れることになり、生成の負荷とキャッシュの量が増える。
- RESTのURL(`/wp-json/tmswp/v1/thumb?...`)や署名`s`を自分で組み立てない。`tms_thumb()`だけで作る。
- RESTのルート`tmswp/v1/thumb`に、認証・nonce・ログインの条件を足さない。訪問者からこのルートを塞がない(REST APIを無効にするプラグインや`rest_authentication_errors`のフィルタで、まとめて止めない)。
- 戻り値を`null`の分岐なしに出力しない。`esc_url()`を通さずに出力しない。
- `crop`・`quality`以外のキーを`$options`に渡さない。
- thumbnailを2つ以上のKernelに登録しない(2つ目のKernelのbootが`LogicException`で止まる)。
- `cacheDirName`を、uploadsの既存のディレクトリ(`2025`等の年のディレクトリ、ほかのプラグインのディレクトリ)と同じ名前にしない。
- キャッシュのディレクトリの中に、ほかのファイルを置かない。キャッシュの削除は`rm`ではなく`wp tmswp thumb clear`で行う。
- `--days`を、`redirectMaxAge`以下やページキャッシュの寿命より短くしない。
- uploadsをオブジェクトストレージに移すプラグイン(メディアのオフロード)と組み合わせない。
- 閲覧を制限したい画像(会員限定の画像等)を元画像にしない。

## 確認方法

1. `composer qa`が通ること。
2. アイキャッチのある投稿の一覧を開き、`<img>`の`src`が、初回はRESTのURL(`/wp-json/tmswp/v1/thumb?...`)であること。そのURLを開くと、`/wp-content/uploads/tmswp-thumb/`の下のファイルへ302でリダイレクトし、指定した寸法の画像が表示されること。
3. ページを再読み込みし、`src`がキャッシュの静的なURLに変わっていること。
4. アイキャッチの無い投稿で、`<img>`が出ないこと。
5. RESTのURLの`w`を書き換えて開くと403(`tmswp_thumb_bad_signature`)になること。
6. `wp tmswp thumb status`で`files`が1以上になり、`wp tmswp thumb clear`の後に0になること。
7. 管理者で管理画面を開き、RESTのルートを使えないことの通知が出ていないこと。

## 発展

- 拡張の登録の仕方と制約: vendor/tmswp/core/rules/extensions.md
- アイキャッチが無いときの代わりの画像(`tmswp/post/thumbnail_fallback`のフィルタ): vendor/tmswp/core/rules/post-type.md
- テンプレートパーツ(`Template::part()`)の使い方: vendor/tmswp/core/rules/view.md
- 設計の正本: TMSWPのmonorepoの[docs/08-extensions.md](https://github.com/tmstack-io/tmswp/blob/v0.9.1/docs/08-extensions.md)の「tmswp/thumbnail — 署名付きURLサムネイル」の節
