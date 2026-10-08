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
    // 原子终态保留全部失败原因，分页输出，重试只使用失败身份。
    $fresh['done'] = $fresh['total']; $fresh['finished'] = true;
    for ($id = 1; $id <= 123; $id++) { State::failure($fresh, 'rebuild', $id, 10, 'conversion'); }
    State::failure($fresh, 'resync', 10, 10, 'field_sync');
    State::failure($fresh, 'resync', 10, 10, 'field_sync');
    verify($fresh['failed'] === 124, '同一图集的跨批次同步失败只能列出一次');
    State::save($dir, $fresh);
    $saved = State::read($dir . '/job.json');
    verify($saved === $fresh && count($saved['failures']) === 124, '刷新必须能读回完整失败明细');
    $first = State::response($saved); $second = State::response($saved, 50); $last = State::response($saved, 100);
    verify(count($first['failures']) === 50 && $first['failure_next'] === 50 && $first['failure_total'] === 124, '首屏输出必须有界并给出总数和下一页');
    verify(count($second['failures']) === 50 && count($last['failures']) === 24 && $last['failure_next'] === null, '最后一条失败也必须可以翻页查看');
    verify(count(array_unique(array_column(array_merge($first['failures'], $second['failures'], $last['failures']), 'id'))) === 123, '翻页不得遗漏任何图片身份');
    $before = file_get_contents($dir . '/job.json');
    $neverCollect = static function () { throw new RuntimeException('定向重试不得扫描全库'); };
    rejected(static function () use ($dir, $neverCollect) { State::open($dir, 'rebuild', 'retry', '', $neverCollect); }, '空重试身份必须拒绝');
    rejected(static function () use ($dir, $neverCollect) { State::open($dir, 'rebuild', 'retry', str_repeat('c', 32), $neverCollect); }, '陈旧重试身份必须拒绝');
    verify(file_get_contents($dir . '/job.json') === $before, '拒绝重试不能改写原状态');
    [$retry, $retryList] = State::open($dir, 'rebuild', 'retry', $fresh['job_id'], $neverCollect);
    verify($retry['job_id'] !== $fresh['job_id'] && $retry['retry_of'] === $fresh['job_id'] && $retry['total'] === 124, '失败重试必须新建独立任务并记住原身份');
    verify($retryList[0] === ['task' => 'rebuild', 'id' => 1, 'cid' => 10] && $retryList[123] === ['task' => 'resync', 'id' => 10, 'cid' => 10], '编码与字段失败必须分开计划');
    $retry['done'] = 3; State::save($dir, $retry);
    [$again, $againList] = State::open($dir, 'rebuild', 'retry', $fresh['job_id'], $neverCollect);
    verify($again === $retry && $againList === $retryList, '丢失开始重试响应后仍继续同一快照与偏移');
    $before = file_get_contents($dir . '/job.json');
    rejected(static function () use ($dir, $retry, $neverCollect) { State::open($dir, 'rebuild', 'retry', $retry['job_id'], $neverCollect); }, '未完成任务不可再开启失败重试');
    rejected(static function () use ($dir, $neverCollect) { State::open($dir, 'cleanup', 'start', '', $neverCollect); }, '租约过期也不能覆盖异类未完成任务');
    rejected(static function () use ($dir, $neverCollect) { State::open($dir, 'rebuild', 'resume', '', $neverCollect); }, '有身份的任务不能空身份续跑');
    verify(file_get_contents($dir . '/job.json') === $before, '拒绝覆盖不能改变未完成快照');
    $retry['done'] = $retry['total']; $retry['finished'] = true;
    State::failure($retry, 'resync', 10, 10, 'field_sync');
    State::clearFailure($retry, 'resync', 10);
    verify($retry['failed'] === 0 && $retry['failures'] === [], '后续批次成功同步必须消除已恢复的字段失败');
    State::save($dir, $retry);
    [$again, $againList] = State::open($dir, 'rebuild', 'retry', $fresh['job_id'], $neverCollect);
    verify($again === $retry && $againList === [], '首批重试已完成但响应丢失时不得再次新开');
    rejected(static function () use ($dir, $retry, $neverCollect) { State::open($dir, 'rebuild', 'retry', $retry['job_id'], $neverCollect); }, '没有失败项时拒绝空重试');
    [$visible] = State::open($dir, 'rebuild', 'errors', $retry['job_id'], $neverCollect);
    verify($visible === $retry, '查看失败明细不触发候选扫描或处理');
    $legacyFailures = State::response(['failed' => 2, 'finished' => true]);
    verify($legacyFailures['unlisted_failed'] === 2 && $legacyFailures['failure_total'] === 0, '旧版没有明细的失败必须明确告知，不能静默遗漏');
    $mixed = $retry;
    $mixed['failed'] = 2; $mixed['failures'] = []; unset($mixed['unlisted_failed']);
    State::failure($mixed, 'resync', 10, 10, 'field_sync');
    State::save($dir, $mixed);
    [$child, $childList] = State::open($dir, 'rebuild', 'retry', $mixed['job_id'], $neverCollect);
    verify($child['failed'] === 2 && $child['unlisted_failed'] === 2 && count($childList) === 1, '定向重试必须继承旧任务无法重试的失败数');
    State::clearFailure($child, 'resync', 10);
    $child['finished'] = true; $child['done'] = 1; State::save($dir, $child);
    verify(State::response($child)['unlisted_failed'] === 2 && $child['failed'] === 2, '有明细项恢复后不能掩盖旧失败');
    [$full] = State::open($dir, 'rebuild', 'start', '', $collect);
    verify($full['failed'] === 0, '只有明确全量新任务清空旧失败基线');
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
