<?php

declare(strict_types=1);

namespace TMSWP\Thumbnail\Tests\Support;

use TMSWP\Core\Kernel\Discovery;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Testing\RecordingLogger;
use TMSWP\Thumbnail\Thumbnail;

/**
 * ユニットテストで、一時ディレクトリのアプリ(空のapp/)にThumbnailを登録したKernel(テーマ形態)をbootする。ログは
 * RecordingLoggerに記録する。
 *
 * 使うテストはUnitTestCaseを継承し、setUpで`$GLOBALS['wp_version']`(7.0以上)と`$GLOBALS['wp_actions']`を置き、
 * tearDownでKernel::reset()を呼ぶ。
 */
trait BootsThumbnailKernel
{
    /**
     * @param bool $debug falseなら本番のboot(探索結果ファイルを先に作る)
     *
     * @return array{kernel: Kernel, logger: RecordingLogger}
     */
    private function bootThumbnailKernel(string $suffix, Thumbnail $thumbnail, bool $debug = true): array
    {
        $root = $this->createTempDirectory('thumbnail-' . strtolower($suffix));
        self::assertTrue(mkdir($root . '/app'));
        $logger = new RecordingLogger();
        $config = KernelConfig::theme(
            $root . '/app',
            'Example\Tests\Thumbnail' . $suffix . uniqid(),
            $debug,
            [$thumbnail],
            static fn(): array => ['test' => new LogChannel($logger)],
        );

        if (!$debug) {
            (new DiscoveryFile($config))->write((new Discovery($config, new DiscoveryFile($config)))->scan());
        }

        return ['kernel' => Kernel::boot($config), 'logger' => $logger];
    }
}
