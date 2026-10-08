<?php
namespace TypechoPlugin\InfinityTime\Lib;

/** 仅携带预先定义的可公开维护提示。 */
final class MaintenanceException extends \RuntimeException {}

/** 维护任务检查点。调用方必须持有媒体写锁，清单与进度按任务标识配对。 */
class MaintenanceState
{
    public const FAILURE_PAGE_SIZE = 50;

    public static function read(string $file): array
    {
        if (!is_file($file)) { return []; }
        $json = @file_get_contents($file);
        $data = $json === false ? null : json_decode($json, true);
        if (!is_array($data)) { throw new MaintenanceException('维护任务状态损坏或无法读取，请检查磁盘与目录权限'); }
        return $data;
    }

    public static function write(string $file, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) { throw new MaintenanceException('维护任务状态编码失败'); }
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(8));
        if (@file_put_contents($tmp, $json, LOCK_EX) !== strlen($json) || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new MaintenanceException('无法保存维护任务进度，请检查磁盘空间与目录权限后重试');
        }
    }

    /** start 沿用未完成任务；retry 仅从指定终态的失败项建立新快照。 */
    public static function open(string $dir, string $job, string $mode, string $id, callable $collect): array
    {
        if (!in_array($job, ['rebuild', 'cleanup', 'resync'], true)
            || !in_array($mode, ['start', 'resume', 'poll', 'retry', 'errors'], true)) {
            throw new MaintenanceException('无效的维护任务请求');
        }
        $file = $dir . '/job.json';
        $state = self::read($file);
        $same = ($state['job'] ?? '') === $job;
        if ($mode === 'resume' && $id === '' && !empty($state['job_id'])) {
            throw new MaintenanceException('缺少维护任务标识，请刷新页面');
        }
        if (in_array($mode, ['poll', 'retry', 'errors'], true) && !preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new MaintenanceException('缺少或无效的维护任务标识，请刷新页面');
        }
        // 只保存直接父任务身份。重复 retry（包括首批已完成但响应丢失）返回同一子任务。
        $repeatedRetry = $mode === 'retry' && $same && ($state['retry_of'] ?? '') === $id;
        if ($mode !== 'start' && !$repeatedRetry && (!$same || ($id !== '' && $id !== ($state['job_id'] ?? '')))) {
            throw new MaintenanceException('维护任务已更新，请刷新页面查看最新进度');
        }
        if ($mode === 'errors') { return [$state, []]; }
        if ($mode === 'retry' && !$repeatedRetry) {
            if (empty($state['finished'])) { throw new MaintenanceException('维护任务尚未完成，请先继续当前任务'); }
            $list = [];
            foreach (($state['failures'] ?? []) as $failure) {
                if (!is_array($failure) || !in_array($failure['task'] ?? '', ['rebuild', 'resync'], true) || (int)($failure['id'] ?? 0) <= 0) {
                    throw new MaintenanceException('失败清单损坏，不能安全重试');
                }
                $list[] = ['task' => $failure['task'], 'id' => (int)$failure['id'], 'cid' => (int)($failure['cid'] ?? 0)];
            }
            if (!$list) { throw new MaintenanceException('没有可单独重试的失败项'); }
            $unlisted = max(0, (int)($state['failed'] ?? 0) - count($state['failures'] ?? []));
            $state = self::newState($job, count($list), $id);
            $state['unlisted_failed'] = $unlisted;
            $state['failed'] = $unlisted;
            self::write(self::listFile($dir, $state), $list);
            self::write($file, $state);
            return [$state, $list];
        }
        // 任务身份比短期租约更持久，不能以另一个种类覆盖未完成快照。
        if ($state && !$same && empty($state['finished'])) {
            throw new MaintenanceException('另一个维护任务尚未完成，请先继续该任务');
        }
        if ($same && (empty($state['finished']) || $mode !== 'start')) {
            if (!empty($state['finished'])) { return [$state, []]; }
            $oldId = (string)($state['job_id'] ?? '');
            if ($oldId !== '') { self::listFile($dir, $state); }
            $listFile = $dir . '/' . $job . ($oldId !== '' ? '_' . $oldId : '') . '_list.json';
            if (!is_file($listFile)) { throw new MaintenanceException('维护清单缺失，不能安全继续，请恢复任务数据'); }
            $list = self::read($listFile);
            // 升级前的未完成任务沿用旧清单与偏移，再赋予身份以拒绝迟到请求。
            if ($oldId === '') {
                $state['job_id'] = bin2hex(random_bytes(16));
                self::write(self::listFile($dir, $state), $list);
                self::write($file, $state);
            }
            return [$state, $list];
        }
        if ($mode !== 'start') { throw new MaintenanceException('没有可继续的维护任务'); }
        $list = array_values($collect());
        $state = self::newState($job, count($list));
        self::write(self::listFile($dir, $state), $list);
        self::write($file, $state);
        return [$state, $list];
    }

    private static function newState(string $job, int $total, string $retryOf = ''): array
    {
        return ['job' => $job, 'job_id' => bin2hex(random_bytes(16)), 'retry_of' => $retryOf, 'total' => $total,
            'done' => 0, 'current' => '', 'failed' => 0, 'failures' => [], 'finished' => false];
    }

    /** 原因来自固定枚举；不将编码器、数据库异常或服务器路径送到客户端。 */
    public static function failure(array &$state, string $task, int $id, int $cid, string $code): void
    {
        $reasons = ['missing_original' => '原图缺失，恢复原图后重试',
            'conversion' => '图片编码失败，请检查原图与转换工具',
            'image_record' => '图片记录保存失败，请检查数据库后重试',
            'field_sync' => '图集字段同步失败；重试时只同步字段'];
        if (!in_array($task, ['rebuild', 'resync'], true) || $id <= 0 || !isset($reasons[$code])) {
            throw new MaintenanceException('无效的维护失败记录');
        }
        self::prepareFailures($state);
        $state['failures'][$task . ':' . $id] = ['task' => $task, 'id' => $id, 'cid' => $cid, 'code' => $code, 'reason' => $reasons[$code]];
        $state['failed'] = count($state['failures']) + $state['unlisted_failed'];
    }

    public static function clearFailure(array &$state, string $task, int $id): void
    {
        self::prepareFailures($state);
        unset($state['failures'][$task . ':' . $id]);
        $state['failed'] = count($state['failures']) + $state['unlisted_failed'];
    }

    private static function prepareFailures(array &$state): void
    {
        if (!isset($state['failures'])) { $state['failures'] = []; }
        if (!isset($state['unlisted_failed'])) { $state['unlisted_failed'] = max(0, (int)($state['failed'] ?? 0) - count($state['failures'])); }
    }

    /** 每个响应最多 50 条，所有记录均可用同一个任务身份翻页查看。 */
    public static function response(array $state, int $offset = 0): array
    {
        $failures = array_values($state['failures'] ?? []);
        $total = count($failures);
        $offset = max(0, min($offset, max(0, $total - 1)));
        $offset = intdiv($offset, self::FAILURE_PAGE_SIZE) * self::FAILURE_PAGE_SIZE;
        $out = array_intersect_key($state, array_flip(['job', 'job_id', 'retry_of', 'total', 'done', 'current', 'failed', 'finished']));
        $out['failures'] = array_slice($failures, $offset, self::FAILURE_PAGE_SIZE);
        $out['failure_total'] = $total;
        $out['failure_offset'] = $offset;
        $out['failure_next'] = $offset + self::FAILURE_PAGE_SIZE < $total ? $offset + self::FAILURE_PAGE_SIZE : null;
        $out['unlisted_failed'] = max(0, (int)($state['failed'] ?? 0) - $total);
        return $out;
    }

    public static function listFile(string $dir, array $state): string
    {
        $job = (string)($state['job'] ?? '');
        $id = (string)($state['job_id'] ?? '');
        if (!in_array($job, ['rebuild', 'cleanup', 'resync'], true) || !preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new MaintenanceException('无效的维护任务状态');
        }
        return $dir . '/' . $job . '_' . $id . '_list.json';
    }

    public static function save(string $dir, array $state): void
    {
        self::write($dir . '/job.json', $state);
        // 失败项留在原子检查点中，完成后可重试；无需保留全库候选清单。
        if (!empty($state['finished'])) { @unlink(self::listFile($dir, $state)); }
    }
}
