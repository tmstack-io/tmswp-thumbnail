<?php

declare(strict_types=1);

namespace TMSWP\Thumbnail;

use TMSWP\Core\Exception\RuntimeException;
use TMSWP\Thumbnail\Exception\UnprocessableImageException;

/**
 * 元画像から、WordPressの画像エディタ(wp_get_image_editor())でサムネイルを作って書き込む。
 *
 * 元画像より大きい寸法は、元画像の寸法で頭打ちにする(拡大しない)。切り抜きでは、指定の縦横比を保ったまま元画像に
 * 収まる大きさに縮める。
 */
final class ThumbGenerator
{
    /**
     * @param int $width 幅(px)。0なら高さと元画像の縦横比で決める
     * @param int $height 高さ(px)。0なら幅と元画像の縦横比で決める
     *
     * @throws UnprocessableImageException 画像エディタが元画像を読めない、またはリサイズできない場合
     * @throws RuntimeException 品質の設定・保存に失敗した場合
     *
     * @return string 書き込んだファイルのパス(save()が返したpath。出力形式のフィルタ(image_editor_output_format)で、
     *                $targetと拡張子が違いうる)
     */
    public function generate(string $source, string $target, int $width, int $height, bool $crop, int $quality): string
    {
        $editor = wp_get_image_editor($source);

        if (is_wp_error($editor)) {
            throw new UnprocessableImageException('the image editor could not load the thumbnail source: ' . $editor->get_error_message());
        }

        $size = $editor->get_size();
        $resize = self::fit($width, $height, $size['width'], $size['height'], $crop);

        if ($resize !== null) {
            $resized = $editor->resize($resize[0] > 0 ? $resize[0] : null, $resize[1] > 0 ? $resize[1] : null, $crop);

            if (is_wp_error($resized)) {
                throw new UnprocessableImageException('the image editor could not resize the thumbnail source: ' . $resized->get_error_message());
            }
        }

        $quality = $editor->set_quality($quality);

        if (is_wp_error($quality)) {
            throw new RuntimeException('the image editor rejected the thumbnail quality: ' . $quality->get_error_message());
        }

        $saved = $editor->save($target);

        if (is_wp_error($saved)) {
            throw new RuntimeException('the image editor could not save the thumbnail: ' . $saved->get_error_message());
        }

        if (!\is_string($saved['path'] ?? null)) {
            throw new RuntimeException('the image editor did not return the path of the saved thumbnail');
        }

        return $saved['path'];
    }

    /**
     * リサイズに渡す幅・高さ。元画像の寸法を超える指定は頭打ちにし、元画像の寸法のままでよい(縮める必要が無い)ときは
     * nullを返す(WordPressの画像エディタは、拡大と同じ寸法へのリサイズをエラーにするため)。
     *
     * - 幅と高さの両方を指定した切り抜き: 指定の縦横比のまま、元画像に収まる大きさに縮める
     * - それ以外(切り抜かない、または片方が0): 指定の枠に収まるよう、元画像の縦横比で縮める
     *
     * どちらも、縮めた結果が元画像と1px以内(WordPressがリサイズを拒む差)ならnull。
     *
     * @return array{0: int, 1: int}|null
     */
    public static function fit(int $width, int $height, int $sourceWidth, int $sourceHeight, bool $crop): ?array
    {
        if ($crop && $width > 0 && $height > 0) {
            $factor = min(1, $sourceWidth / $width, $sourceHeight / $height);
            $fitted = [max(1, (int) round($width * $factor)), max(1, (int) round($height * $factor))];

            return self::nearlySource($fitted[0], $fitted[1], $sourceWidth, $sourceHeight) ? null : $fitted;
        }

        $scale = min(
            $width > 0 ? $width / $sourceWidth : INF,
            $height > 0 ? $height / $sourceHeight : INF,
        );

        if ($scale >= 1 || self::nearlySource((int) round($sourceWidth * $scale), (int) round($sourceHeight * $scale), $sourceWidth, $sourceHeight)) {
            return null;
        }

        return [$width, $height];
    }

    private static function nearlySource(int $width, int $height, int $sourceWidth, int $sourceHeight): bool
    {
        return $sourceWidth - $width <= 1 && $sourceHeight - $height <= 1;
    }
}
