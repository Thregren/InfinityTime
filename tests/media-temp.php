<?php
/** 外部工具使用隔离 PHP fixture，真实启动子进程，验证临时 JPEG 的所有退出路径。 */
declare(strict_types=1);
namespace TypechoPlugin\InfinityTime { class Plugin { public static array $logs = []; public static function log($message): void { self::$logs[] = $message; } } }
namespace TypechoPlugin\InfinityTime\Lib {
    function class_exists($class, $autoload = true): bool { return $class === '\\Imagick' ? false : \class_exists($class, $autoload); }
    function is_executable($path): bool { return false; }
    function exec($command, &$output, &$code) {
        global $fixtureRoot;
        $tool = strpos($command, 'heif-convert') !== false ? 'heif' : (strpos($command, 'magick') !== false ? 'magick' : '');
        $output = $tool ? [$fixtureRoot . '/' . $tool . '.php'] : []; $code = $tool ? 0 : 1;
        return $output[0] ?? '';
    }
    function proc_open($command, $descriptors, &$pipes, $cwd = null, $env = null, $options = []) {
        global $producedDirs, $privateModes;
        $directory = dirname($command[count($command) - 1]);
        $producedDirs[] = $directory; $privateModes[] = fileperms($directory) & 0777;
        return \proc_open(array_merge([PHP_BINARY], $command), $descriptors, $pipes, $cwd, $env, $options);
    }
    function microtime($float = false) {
        global $ticks;
        return \microtime(true) + (getenv('IT_CODEC_CASE') === 'timeout' ? ($ticks++ ? 31 : 0) : 0);
    }
}
namespace {
    use TypechoPlugin\InfinityTime\Lib\MediaProcessor;
    require __DIR__ . '/../usr/plugins/InfinityTime/Lib/MediaProcessor.php';
    $fixtureRoot = sys_get_temp_dir() . '/infinity-codec-fixture-' . bin2hex(random_bytes(4));
    mkdir($fixtureRoot, 0700);
    $checks = 0; $producedDirs = []; $privateModes = []; $ticks = 0;
    function tempCheck(bool $ok, string $message): void { global $checks; if (!$ok) { throw new RuntimeException($message); } $checks++; }
    $image = imagecreatetruecolor(3, 2); ob_start(); imagejpeg($image); $jpeg = ob_get_clean(); unset($image);
    $sof = strpos($jpeg, "\xff\xc0");
    if ($sof === false) { throw new RuntimeException('JPEG测试夹具缺少基线尺寸头'); }
    $oversized = substr_replace($jpeg, pack('nn', 9000, 9000), $sof + 5, 4);
    $script = '<?php $mode=getenv("IT_CODEC_CASE");$out=$argv[count($argv)-1];'
        . 'if($mode==="timeout"){usleep(2000000);}'
        . 'if($mode==="fallback" && strpos($argv[0],"magick")!==false){file_put_contents($out,"partial");exit(3);}'
        . 'if($mode==="failure"){file_put_contents($out,"failed");exit(9);}'
        . 'if($mode==="invalid"){file_put_contents($out,"invalid");exit(0);}'
        . 'if($mode==="oversized"){file_put_contents($out,base64_decode(' . var_export(base64_encode($oversized), true) . '));exit(0);}'
        . 'if($mode==="cleanup"){mkdir(dirname($out)."/nested",0700);file_put_contents(dirname($out)."/nested/retained","private");}'
        . 'file_put_contents($out,base64_decode(' . var_export(base64_encode($jpeg), true) . '));'
        . 'file_put_contents(dirname($out)."/unused-frame.jpg","private unused frame");';
    file_put_contents($fixtureRoot . '/magick.php', $script); file_put_contents($fixtureRoot . '/heif.php', $script);
    $source = $fixtureRoot . '/原图 空格.heic'; file_put_contents($source, 'private original fixture');
    try {
        foreach (['decodeHeic', 'decodeAvif'] as $name) {
            $method = new ReflectionMethod(MediaProcessor::class, $name); $method->setAccessible(true);
            foreach (['success', 'fallback', 'failure', 'invalid', 'timeout', 'oversized', 'cleanup'] as $mode) {
                if ($name === 'decodeAvif' && $mode === 'fallback') { continue; }
                putenv('IT_CODEC_CASE=' . $mode); $ticks = 0; $before = count($producedDirs); $thrown = false; $error = ''; $logsBefore = count(\TypechoPlugin\InfinityTime\Plugin::$logs);
                try { $decoded = $method->invoke(null, $source); tempCheck(imagesx($decoded) === 3 && imagesy($decoded) === 2, '有效JPEG由GD正常读取'); unset($decoded); }
                catch (RuntimeException $e) { $thrown = true; $error = $e->getMessage(); }
                tempCheck($thrown === in_array($mode, ['failure','invalid','timeout','oversized'], true), $name . ' 的成功/失败契约正确：' . $mode);
                tempCheck(count($producedDirs) > $before, '实际执行外部工具fixture');
                if ($mode === 'oversized') { tempCheck(strpos($error, '上限') !== false, '转换JPEG超过60MP时在GD解码之前明确拒绝'); }
                foreach (array_slice($producedDirs, $before) as $directory) {
                    if ($mode === 'cleanup') {
                        tempCheck(is_dir($directory) && count(array_filter(glob($directory . '/*'), 'is_file')) === 0, '清理受阻只保留私有目录，不把成功转换误报失败');
                        tempCheck(count(\TypechoPlugin\InfinityTime\Plugin::$logs) > $logsBefore, '临时目录清理受阻必须有日志');
                        unlink($directory . '/nested/retained'); rmdir($directory . '/nested'); rmdir($directory);
                    } else { tempCheck(!is_dir($directory), '成功、异常、超时和多帧输出均清理整个私有目录'); }
                }
                tempCheck(file_get_contents($source) === 'private original fixture', '不会修改或删除源文件');
            }
        }
        if (PHP_OS_FAMILY !== 'Windows') { foreach ($privateModes as $mode) { tempCheck($mode === 0700, 'POSIX转换目录仅当前用户可读写'); } }
        echo '转换临时资源：' . $checks . " 项检查通过\n";
    } finally {
        putenv('IT_CODEC_CASE');
        foreach (glob($fixtureRoot . '/*') as $file) { unlink($file); }
        rmdir($fixtureRoot);
    }
}
