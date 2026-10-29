<?php

declare(strict_types=1);

namespace TMSWP\Thumbnail;

use Throwable;
use TMSWP\Core\Exception\RuntimeException;

/**
 * キャッシュのキー単位の排他ロック(ThumbCache::lock()が取る、ロックのファイルへのflock)。
 *
 * @internal ThumbCacheが作り、RestControllerが生成の後にrelease()で手放す
 */
final class ThumbLock
{
    /**
     * @param resource $handle flock(LOCK_EX)を取ったロックのファイルのハンドル
     */
    public function __construct(private $handle, private readonly string $path) {}

    /**
     * ロックを持ったまま、ロックのファイルを消してから、ロックを手放す。
     *
     * 消す前に別のプロセスがこのファイルを開いていて、手放した後にそのロックを取っても、ThumbCache::lock()が
     * パスの今のファイルと違うことを確かめて取り直すため、2つのプロセスが同時にロックを持つことは無い。
     *
     * @param Throwable|null $failure ロックの中の処理が投げた例外。ロックのファイルを消せないときに、previousに入れる
     *
     * @throws RuntimeException ロックのファイルを消せない場合
     */
    public function release(?Throwable $failure = null): void
    {
        $removed = !is_file($this->path) || unlink($this->path);
        fclose($this->handle);

        if (!$removed) {
            throw new RuntimeException("failed to remove the thumbnail lock file: {$this->path}", previous: $failure);
        }
    }
}
