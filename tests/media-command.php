<?php
/** 真实子进程回归：退出码、精确参数、大输出与超时后实际子进程停止。 */
declare(strict_types=1);
require __DIR__ . '/../usr/plugins/InfinityTime/Lib/MediaProcessor.php';
$method = new ReflectionMethod('TypechoPlugin\\InfinityTime\\Lib\\MediaProcessor', 'runCmd');
$method->setAccessible(true);
$root = sys_get_temp_dir() . '/infinity-command-' . bin2hex(random_bytes(5));
mkdir($root, 0700);
$checks = 0;
function commandCheck(bool $ok, string $message): void { global $checks; if (!$ok) { throw new RuntimeException($message); } $checks++; }
try {
    commandCheck($method->invoke(null, [PHP_BINARY, '-r', 'exit(0);'], 5) === 0, '保留成功退出码');
    commandCheck($method->invoke(null, [PHP_BINARY, '-r', 'exit(7);'], 5) === 7, '保留失败退出码');
    $args = ['带 空格/路径', '"quoted"', "O'Reilly", '$HOME;echo nope', 'back\\slash', '[0]', '百分号%COMSPEC%'];
    $record = $root . '/argv.json';
    $cmd = [PHP_BINARY, '-r', 'file_put_contents($argv[1],json_encode(array_slice($argv,2),JSON_UNESCAPED_UNICODE));', $record];
    commandCheck($method->invoke(null, array_merge($cmd, $args), 5) === 0, '包含引号和特殊字符的命令成功');
    commandCheck(json_decode(file_get_contents($record), true) === $args, 'argv不经shell解释或破坏');
    $memory = memory_get_peak_usage(true);
    $started = microtime(true);
    commandCheck($method->invoke(null, [PHP_BINARY, '-r', 'for($i=0;$i<512;$i++){fwrite(STDOUT,str_repeat("a",16384));fwrite(STDERR,str_repeat("b",16384));}'], 5) === 0, '16MB双通道输出不会管道阻塞');
    commandCheck(memory_get_peak_usage(true) - $memory < 8 * 1024 * 1024, '子进程日志不累积到PHP内存');
    $marker = $root . '/late-marker';
    $started = microtime(true); $timedOut = false;
    try { $method->invoke(null, [PHP_BINARY, '-r', 'usleep(2000000);file_put_contents($argv[1],"alive");', $marker], 1); }
    catch (RuntimeException $e) {
        $timedOut = strpos($e->getMessage(), '超时') !== false;
        commandCheck(strpos($e->getMessage(), $marker) === false, '错误不暴露参数或私有临时路径');
    }
    commandCheck($timedOut, '超时必须抛出明确错误');
    commandCheck(microtime(true) - $started < 3, '超时结束等待');
    usleep(1300000);
    commandCheck(!is_file($marker), '实际工具子进程已停止，不会在父流程返回后继续写入');
    foreach ([[], [PHP_BINARY, "invalid\0argument"]] as $invalid) {
        $rejected = false; try { $method->invoke(null, $invalid, 1); } catch (InvalidArgumentException $e) { $rejected = true; }
        commandCheck($rejected, '非法命令在启动前拒绝');
    }
    if (PHP_OS_FAMILY === 'Windows') {
        foreach (['tool.bat', 'tool.cmd'] as $batch) {
            $rejected = false; try { $method->invoke(null, [$batch, 'argument'], 1); } catch (RuntimeException $e) { $rejected = true; }
            commandCheck($rejected, 'Windows批处理包装不进入隐式命令解释器');
        }
    }
    echo '真实转换子进程：' . $checks . ' 项检查通过（PHP ' . PHP_VERSION . ' / ' . PHP_OS_FAMILY . ")\n";
} finally {
    foreach (glob($root . '/*') as $file) { unlink($file); }
    rmdir($root);
}
