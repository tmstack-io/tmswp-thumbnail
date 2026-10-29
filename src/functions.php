<?php

declare(strict_types=1);

/**
 * サイトに登録したThumbnail(KernelConfigのextensionsに渡したもの)で、サムネイルのURLを返す。
 * Thumbnail::current()->url()への委譲。
 *
 * 同じ名前の関数が先に定義されていると、PHPの関数の再宣言のエラーで止まる(別の実装を黙って使わない)。
 *
 * @param int|string $src 添付ファイルのID、またはuploadsの下の画像のURL。0と空文字は「画像が無い」
 * @param array<string, mixed> $options `crop`(bool)・`quality`(int 1〜100)
 *
 * @throws TMSWP\Core\Exception\LogicException thumbnailを登録したKernelが無い場合
 * @throws TMSWP\Thumbnail\Exception\InvalidThumbSourceException srcの誤り(uploadsの外・許可されない拡張子等)・寸法の誤り
 * @throws TMSWP\Thumbnail\Exception\InvalidThumbOptionException オプションの誤り
 * @throws TMSWP\Core\Exception\RuntimeException debugのときの、実行時の失敗(元画像の更新日時が読めない等)
 *
 * @return string|null サムネイルのURL。画像が無い(アイキャッチの未設定、添付ファイルの実体が無い等)ときはnull
 */
function tms_thumb(int|string $src, ?int $width = null, ?int $height = null, array $options = []): ?string
{
    return TMSWP\Thumbnail\Thumbnail::current()->url($src, $width, $height, $options);
}
