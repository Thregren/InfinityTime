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

    /**
     * 在主库保存点中执行受控写入，保留调用方事务边界。
     * $tables 仅由内部调用点给出真实表名，用于写入前验证 MySQL 事务引擎。
     * 返回 true 仅表示本方法创建且成功提交了事务；文件清理须以此为依据。
     */
    public static function transaction(callable $operation, array $tables): bool
    {
        $db = Db::get();
        $adapter = strtolower($db->getAdapterName());
        $handle = $db->selectDb(Db::WRITE);
        $write = static function ($query) {
            // 所有同步语句编译后固定走主库；不需要 INSERT 的生成主键。
            return self::query(is_string($query) ? $query : $query->prepare((string)$query));
        };
        $savepoint = 'infinitytime_fields_' . bin2hex(random_bytes(8));
        $owned = false;
        $mysql = strpos($adapter, 'mysql') !== false;
        if ($mysql) {
            // MyISAM 等表忽略回滚，不能宣称字段替换是原子的；不自动 ALTER 用户表。
            foreach ($tables as $name) {
                $query = $db->select();
                $sql = $query->prepare('SHOW TABLE STATUS WHERE Name = ' . $query->quoteValue($name));
                $table = $db->fetchRow($write($sql));
                if (strcasecmp((string)($table['Engine'] ?? ''), 'InnoDB') !== 0) {
                    throw new \RuntimeException('此操作需要相关表使用 InnoDB 事务引擎，请先备份并由管理员迁移表引擎');
                }
            }
            // MySQL 在 autocommit 下静默忽略 SAVEPOINT；必须在写入前验证它实际存在。
            // 不用 BEGIN 探测：它会隐式提交已有事务。也兼容调用方 SET autocommit=0。
            $write('SAVEPOINT ' . $savepoint);
            try {
                // 直接检查驱动错误码，避免 Typecho PDO 包装丢失 MySQL errno。
                $result = $handle->query('ROLLBACK TO SAVEPOINT ' . $savepoint);
                if ($result === false) {
                    $code = $handle instanceof \PDO ? (int)($handle->errorInfo()[1] ?? 0) : (int)$handle->errno;
                    throw new \RuntimeException('无法验证字段同步保存点', $code);
                }
            } catch (\Throwable $e) {
                $code = $e instanceof \PDOException ? (int)($e->errorInfo[1] ?? 0) : (int)$e->getCode();
                if ($code !== 1305) { throw $e; }
                $write('BEGIN');
                $owned = true;
            }
        } elseif (strpos($adapter, 'pgsql') !== false || strpos($adapter, 'postgres') !== false) {
            if ($handle instanceof \PDO) {
                $active = $handle->inTransaction();
            } elseif (function_exists('pg_transaction_status')) {
                $status = pg_transaction_status($handle);
                if (!in_array($status, [PGSQL_TRANSACTION_IDLE, PGSQL_TRANSACTION_INTRANS], true)) {
                    throw new \RuntimeException('数据库事务不可用，请先结束当前失败的事务');
                }
                $active = $status === PGSQL_TRANSACTION_INTRANS;
            } else {
                throw new \RuntimeException('无法安全检测数据库事务状态');
            }
            if (!$active) { $write('BEGIN'); $owned = true; }
        } elseif (strpos($adapter, 'sqlite') !== false) {
            // SQLite BEGIN 不会隐式提交已有事务。PDO 可直接检测；原生 SQLite3
            // 没有跨版本可用的状态 API，只接受明确的“已有事务”错误，其余错误上抛。
            if (!($handle instanceof \PDO) || !$handle->inTransaction()) {
                try { @$write('BEGIN'); $owned = true; }
                catch (\Throwable $e) {
                    if (strpos($e->getMessage(), 'cannot start a transaction within a transaction') === false) { throw $e; }
                }
            }
        } else {
            throw new \RuntimeException('当前数据库适配器不支持安全的字段同步事务');
        }
        // 嵌套 RELEASE 不会提交外层 BEGIN/SAVEPOINT；只有 owned 才能删除实体文件。
        $started = false;
        try {
            $write('SAVEPOINT ' . $savepoint);
            $started = true;
            $operation($write);
            $write('RELEASE SAVEPOINT ' . $savepoint);
            if ($owned) { $write('COMMIT'); }
        } catch (\Throwable $e) {
            try {
                if ($owned) { $write('ROLLBACK'); }
                elseif ($started) {
                    $write('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $write('RELEASE SAVEPOINT ' . $savepoint);
                }
            } catch (\Throwable $rollbackError) {
                // 保留最初的错误，同时明确记录连接丢失等导致无法确认回滚的情况。
                \TypechoPlugin\InfinityTime\Plugin::log('transaction rollback failed: ' . $rollbackError->getMessage());
            }
            throw $e;
        }
        return $owned;
    }
}
