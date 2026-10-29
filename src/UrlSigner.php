<?php

declare(strict_types=1);

namespace TMSWP\Thumbnail;

/**
 * サムネイルのURLの署名(HMAC-SHA256)。
 *
 * 署名の対象は、`src`・`w`・`h`・`o`の4つの文字列を順に並べた配列の`serialize()`である。各値の長さが入るため、
 * 値の中の区切りの文字で別の組み合わせと同じ文字列になることが無い。
 */
final class UrlSigner
{
    /** 鍵の導出(HKDF)の用途の文字列。wp_salt('auth')を、ほかの用途と同じ鍵のまま使わないため */
    private const string KEY_INFO = 'tmswp/thumbnail/url-signature';

    /**
     * @param string $key 署名の鍵。deriveKey()で導出したもの
     */
    public function __construct(private readonly string $key) {}

    /**
     * 秘密の値(wp_salt('auth'))から、URLの署名の用途だけに使う鍵をHKDF(SHA-256)で導出する。
     *
     * @param non-empty-string $secret
     */
    public static function deriveKey(string $secret): string
    {
        return hash_hkdf('sha256', $secret, 32, self::KEY_INFO);
    }

    /**
     * URLを生成するときの署名。整数は10進の最短の表記で署名する。
     */
    public function sign(string $src, int $width, int $height, string $options): string
    {
        return hash_hmac('sha256', self::payload($src, (string) $width, (string) $height, $options), $this->key);
    }

    /**
     * 受け取ったクエリの生の文字列のまま(整数への変換・trim・正規化をせずに)署名を照合する。
     */
    public function verify(string $src, string $width, string $height, string $options, string $signature): bool
    {
        return hash_equals(hash_hmac('sha256', self::payload($src, $width, $height, $options), $this->key), $signature);
    }

    private static function payload(string $src, string $width, string $height, string $options): string
    {
        return serialize([$src, $width, $height, $options]);
    }
}
