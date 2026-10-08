<?php
/** 真实文件检查点回归：新任务、断点恢复、迟到请求与磁盘失败。 */
require __DIR__ . '/../usr/plugins/InfinityTime/Lib/MaintenanceState.php';
use TypechoPlugin\InfinityTime\Lib\MaintenanceState as State;
$dir = sys_get_temp_dir() . '/infinity-state-' . bin2hex(random_bytes(5));
mkdir($dir, 0700);
$checks = 0;
function verify($yes, string $message): void { global $checks; if (!$yes) { throw new RuntimeException($message); } $checks++; }
function rejected(callable $fn, string $message): void { $thrown = false; try { $fn(); } catch (RuntimeException $e) { $thrown = true; } verify($thrown, $message); }
try {
    $collect = static function () { return [11, 12, 13, 14]; };
    [$a, $list] = State::open($dir, 'rebuild', 'start', '', $collect);
    verify($list === [11, 12, 13, 14] && $a['total'] === 4 && $a['done'] === 0, '首次开始应建立完整快照');
    $a['done'] = 3; $a['current'] = '13.webp'; State::save($dir, $a);
    [$b, $list] = State::open($dir, 'rebuild', 'start', '', static function () { throw new RuntimeException('不应重新扫描'); });
    verify($b === $a && count($list) === 4, '开始响应丢失后重试应继续同一快照');
    [$b, $list] = State::open($dir, 'rebuild', 'resume', $a['job_id'], $collect);
    verify($b['done'] === 3 && $b['job_id'] === $a['job_id'], '继续任务应保留断点');
    $a['done'] = 4; $a['finished'] = true; State::save($dir, $a);
    verify(!is_file(State::listFile($dir, $a)), '完成后清理该任务清单');
    [$b, $list] = State::open($dir, 'rebuild', 'poll', $a['job_id'], $collect);
    verify($b === $a && $list === [], '完成响应丢失的轮询应返回相同终态');
    [$c, $list] = State::open($dir, 'rebuild', 'start', '', static function () { return [11, 12, 13, 14, 15]; });
    verify($c['total'] === 5 && $list[4] === 15 && $c['job_id'] !== $a['job_id'], '第二次重建必须包括新增图片');
    $before = file_get_contents($dir . '/job.json');
    rejected(static function () use ($dir, $a, $collect) { State::open($dir, 'rebuild', 'poll', $a['job_id'], $collect); }, '旧轮询必须拒绝');
    verify(file_get_contents($dir . '/job.json') === $before, '旧轮询不可改写新任务状态');
    rejected(static function () use ($dir, $collect) { State::open($dir, 'rebuild', 'poll', '', $collect); }, '轮询必须携带身份');
    rejected(static function () use ($dir, $collect) { State::open($dir, 'resync', 'resume', '', $collect); }, '不能将旧任务进度用于其他任务');
    // 模拟新清单已写、状态尚未替换就中断，孤立文件不应改变现有清单与进度。
    State::write($dir . '/rebuild_' . str_repeat('a', 32) . '_list.json', [99]);
    [$d, $list] = State::open($dir, 'rebuild', 'resume', $c['job_id'], $collect);
    verify($d === $c && $list === [11, 12, 13, 14, 15], '孤立清单不可配错旧偏移');
    unlink(State::listFile($dir, $c));
    rejected(static function () use ($dir, $c, $collect) { State::open($dir, 'rebuild', 'resume', $c['job_id'], $collect); }, '丢失快照应停止而不是跳过项目');
    // 升级前的未完成检查点保留其原有次序和 done，完成的旧检查点则生成新任务。
    State::write($dir . '/job.json', ['job' => 'rebuild', 'total' => 2, 'done' => 1]);
    State::write($dir . '/rebuild_list.json', [[21, 'old1'], [22, 'old2']]);
    [$legacy, $list] = State::open($dir, 'rebuild', 'resume', '', $collect);
    verify($legacy['done'] === 1 && strlen($legacy['job_id']) === 32 && $list[1][0] === 22, '旧断点必须平滑保留');
    State::write($dir . '/job.json', ['job' => 'rebuild', 'total' => 0, 'done' => 0, 'finished' => true]);
    [$fresh, $list] = State::open($dir, 'rebuild', 'start', '', $collect);
    verify($list === [11, 12, 13, 14], '旧版本完成状态不得复用旧清单');
    // rename 到目录必失败，不能以成功返回。
    mkdir($dir . '/cannot-replace');
    rejected(static function () use ($dir) { State::write($dir . '/cannot-replace', ['done' => 1]); }, 'rename失败必须可见');
    verify(count(glob($dir . '/cannot-replace.tmp.*')) === 0, '失败写入必须清理临时文件');
    file_put_contents($dir . '/broken.json', '{');
    rejected(static function () use ($dir) { State::read($dir . '/broken.json'); }, '损坏状态不可静默当新任务');
    echo '维护检查点：' . $checks . " 项检查通过\n";
} finally {
    foreach (glob($dir . '/*') as $file) { if (is_dir($file)) { rmdir($file); } else { unlink($file); } }
    rmdir($dir);
}
