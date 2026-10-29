<?php

declare(strict_types=1);

namespace TMSWP\Thumbnail;

use TMSWP\Thumbnail\Exception\InvalidThumbSourceException;

/**
 * サムネイルの元画像の`src`(`id:{添付ファイルのID}`か、uploadsの下の画像のURL)を、uploadsの下の実パスに解決する。
 */
final class SourceResolver
{
    private const array ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

    /**
     * 実パスの封じ込め(realpathをuploadsの実パス+区切りで前方一致させる)を、どちらの形の`src`にも最後に適用する。
     *
     * ファイルの有無を見る前に、パスの文字列の上でuploadsの外を指していないか(`..`の区間・絶対パス等)を確かめる。外を
     * 指すsrcは、相手のファイルの有無に関係なく例外になる。
     *
     * @throws InvalidThumbSourceException `src`の形の誤り(`id:{n}`でもuploadsの下のURLでもない)、許可されない拡張子、
     *                                     uploadsの外を指すパス(`../`・絶対パス・シンボリックリンク・uploadsの外を指す添付
     *                                     ファイルのメタ)
     * @throws \TMSWP\Core\Exception\RuntimeException wp_upload_dir()がbasedir・baseurlを返さない場合
     *
     * @return non-empty-string|null 実パス。ファイルが無い(添付ファイルが無い・実体が無い)ときはnull
     */
    public function resolve(string $src): ?string
    {
        $uploads = Thumbnail::uploads();
        $path = $this->pathFromSource($src, $uploads['basedir'], $uploads['baseurl']);

        if ($path === null) {
            return null;
        }

        self::assertInsideByName($path, $uploads['basedir'], $src);
        self::assertAllowedExtension($path, $src);

        if (!is_file($path)) {
            return null;
        }

        $base = realpath($uploads['basedir']);
        $real = realpath($path);

        if ($base === false || $real === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            throw new InvalidThumbSourceException("thumbnail source must be inside the uploads directory: {$src}");
        }

        // シンボリックリンクの名前だけでなく、実体の拡張子も確かめる
        self::assertAllowedExtension($real, $src);

        return $real;
    }

    /**
     * パスの文字列が、uploadsのディレクトリ+`/`で始まり、その後に空の区間(`//`)・`.`・`..`の区間・NULを含まないか。
     *
     * uploadsのディレクトリの直後の区切りの重なりは許す。get_attached_file()は`basedir . "/$file"`でパスを作るため、
     * basedirの末尾が`/`のサイトでは`//`になる。URLの形の`src`の、デコードした相対パスの先頭の`/`は、pathFromSource()が
     * 先に例外にする。
     *
     * @throws InvalidThumbSourceException 含む場合
     */
    private static function assertInsideByName(string $path, string $baseDir, string $src): void
    {
        $prefix = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $relative = str_starts_with($path, $prefix) ? ltrim(substr($path, \strlen($prefix)), DIRECTORY_SEPARATOR) : '';
        $segments = explode(DIRECTORY_SEPARATOR, $relative);

        if ($relative === '' || str_contains($relative, "\0") || array_intersect($segments, ['', '.', '..']) !== []) {
            throw new InvalidThumbSourceException("thumbnail source must be inside the uploads directory: {$src}");
        }
    }

    /**
     * @throws InvalidThumbSourceException 拡張子が許可リストに無い場合
     */
    private static function assertAllowedExtension(string $path, string $src): void
    {
        if (!\in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::ALLOWED_EXTENSIONS, true)) {
            throw new InvalidThumbSourceException("thumbnail source must be a jpg, png, gif, webp or avif image: {$src}");
        }
    }

    /**
     * @throws InvalidThumbSourceException `src`の形の誤り(uploadsのURLの外、デコードした相対パスが空・`/`で始まる・NULを含む)
     */
    private function pathFromSource(string $src, string $baseDir, string $baseUrl): ?string
    {
        if (preg_match('/\Aid:([1-9][0-9]*)\z/', $src, $matches) === 1) {
            $file = get_attached_file((int) $matches[1]);

            return \is_string($file) && $file !== '' ? $file : null;
        }

        $prefix = rtrim($baseUrl, '/') . '/';

        // baseurlの末尾が`/`のサイトでは、wp_get_attachment_url()が`baseurl . "/$file"`でURLを作るため`//`になる。
        // そのときだけ、接頭辞の直後の`/`を1つ余分に受け入れる(エンコードした`/`や、それ以上の重なりは下で例外にする)
        if (str_ends_with($baseUrl, '/') && str_starts_with($src, $prefix . '/')) {
            $prefix .= '/';
        }

        if (!str_starts_with($src, $prefix)) {
            throw new InvalidThumbSourceException("thumbnail source must be an attachment ID or a URL under the uploads directory ({$prefix}): {$src}");
        }

        $relative = rawurldecode(substr($src, \strlen($prefix)));

        if ($relative === '' || str_contains($relative, "\0")) {
            throw new InvalidThumbSourceException("thumbnail source URL does not name a file: {$src}");
        }

        if (str_starts_with($relative, '/')) {
            throw new InvalidThumbSourceException("thumbnail source must be inside the uploads directory: {$src}");
        }

        return rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
}
