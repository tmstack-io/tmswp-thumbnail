<?php

declare(strict_types=1);

namespace TMSWP\Thumbnail\Exception;

use TMSWP\Core\Exception\RuntimeException;

/**
 * 画像エディタが元画像を読めない、またはリサイズできない(元画像と指定が変わらない限り、何度試しても同じ結果になる)。
 * RestControllerは、これをネガティブキャッシュに記録して、同じ要求での再生成を止める。
 */
final class UnprocessableImageException extends RuntimeException {}
