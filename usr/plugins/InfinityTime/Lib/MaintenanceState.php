<?php
namespace TypechoPlugin\InfinityTime\Lib;

/** 维护任务检查点。调用方必须持有媒体写锁，清单与进度按任务标识配对。 */
class MaintenanceState
{
    public static function read(string $file): array
    {
        if (!is_file($file)) { return []; }
        $json = @file_get_contents($file);
        $data = $json === false ? null : json_decode($json, true);
        if (!is_array($data)) { throw new \RuntimeException('维护任务状态损坏或无法读取，请检查磁盘与目录权限'); }
        return $data;
    }

    public static function write(string $file, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) { throw new \RuntimeException('维护任务状态编码失败'); }
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(8));
        if (@file_put_contents($tmp, $json, LOCK_EX) !== strlen($json) || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('无法保存维护任务进度，请检查磁盘空间与目录权限后重试');
        }
    }

    /** start 在未完成时沿用任务；完成后明确新开。resume/poll 绝不隐式创建。 */
    public static function open(string $dir, string $job, string $mode, string $id, callable $collect): array
    {
        if (!in_array($job, ['rebuild', 'cleanup', 'resync'], true)
            || !in_array($mode, ['start', 'resume', 'poll'], true)) {
            throw new \RuntimeException('无效的维护任务请求');
        }
        $file = $dir . '/job.json';
        $state = self::read($file);
        $same = ($state['job'] ?? '') === $job;
        if ($mode !== 'start' && (!$same || ($id !== '' && $id !== ($state['job_id'] ?? '')))) {
            throw new \RuntimeException('维护任务已更新，请刷新页面查看最新进度');
        }
        if ($mode === 'poll' && $id === '') { throw new \RuntimeException('缺少维护任务标识，请刷新页面'); }
        if ($same && (empty($state['finished']) || $mode !== 'start')) {
            if (!empty($state['finished'])) { return [$state, []]; }
            $oldId = (string)($state['job_id'] ?? '');
            $listFile = $dir . '/' . $job . ($oldId !== '' ? '_' . $oldId : '') . '_list.json';
            if (!is_file($listFile)) { throw new \RuntimeException('维护清单缺失，不能安全继续，请恢复任务数据'); }
            $list = self::read($listFile);
            // 升级前的未完成任务沿用旧清单与偏移，再赋予身份以拒绝迟到请求。
            if ($oldId === '') {
                $state['job_id'] = bin2hex(random_bytes(16));
                self::write(self::listFile($dir, $state), $list);
                self::write($file, $state);
            }
            return [$state, $list];
        }
        if ($mode !== 'start') { throw new \RuntimeException('没有可继续的维护任务'); }
        $list = array_values($collect());
        $state = ['job' => $job, 'job_id' => bin2hex(random_bytes(16)), 'total' => count($list),
            'done' => 0, 'current' => '', 'failed' => 0, 'finished' => false];
        self::write(self::listFile($dir, $state), $list);
        self::write($file, $state);
        return [$state, $list];
    }

    public static function listFile(string $dir, array $state): string
    {
        $job = (string)($state['job'] ?? '');
        $id = (string)($state['job_id'] ?? '');
        if (!in_array($job, ['rebuild', 'cleanup', 'resync'], true) || !preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new \RuntimeException('无效的维护任务状态');
        }
        return $dir . '/' . $job . '_' . $id . '_list.json';
    }

    public static function save(string $dir, array $state): void
    {
        self::write($dir . '/job.json', $state);
        // 先保存终态；即使清单清理失败，后续新任务仍会生成全新快照。
        if (!empty($state['finished'])) { @unlink(self::listFile($dir, $state)); }
    }
}
