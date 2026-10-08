<?php
namespace TypechoPlugin\InfinityTime\Lib;

use Typecho\Db;

/** 显式主库入口：保持 Typecho 查询返回值，同时避开 Mysqli 的内部连接缓存。 */
final class Database
{
    public static function query($query)
    {
        $db = Db::get();
        $action = is_object($query) && method_exists($query, 'prepare') && method_exists($query, 'getAttribute')
            ? $query->getAttribute('action') : null;
        $mysqli = strcasecmp($db->getAdapterName(), 'Mysqli') === 0;
        if (!$mysqli) {
            // Typecho 对 SELECT Query 强制改用 READ，即使传入 WRITE 也会覆盖。
            if ($action === 'SELECT') { $query = $query->prepare((string)$query); }
            return $db->query($query, Db::WRITE);
        }

        // 与 Db::query 一样，已经执行的结果句柄原样返回。
        if (!is_string($query) && !(is_object($query) && method_exists($query, 'prepare'))) { return $query; }

        $handle = $db->selectDb(Db::WRITE);
        // Typecho Mysqli::query 忽略 handle，改用最近 connect 的内部对象。
        // 不能通过 flushPool 修复，否则会丢失调用方正在执行的事务。
        $sql = is_string($query) ? $query : $query->prepare((string)$query);
        $result = $handle->query($sql);
        if ($result === false) { throw new \RuntimeException($handle->error, $handle->errno); }
        if ($action === 'INSERT') { return $handle->insert_id; }
        if ($action === 'UPDATE' || $action === 'DELETE') { return $handle->affected_rows; }
        return $result;
    }
}
