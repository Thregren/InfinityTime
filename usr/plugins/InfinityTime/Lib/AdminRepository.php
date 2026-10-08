<?php
namespace TypechoPlugin\InfinityTime\Lib;

use Typecho\Db;

/** 分页读取后台图集；摘要列表不加载全部图片数据。 */
final class AdminRepository
{
    public const PAGE_SIZE = 20;
    public const MARKER = 'infinitytime_album';
    public const OPERATION = 'infinitytime_upload';

    private static function table(string $name): string
    {
        $db = Db::get();
        $q = stripos($db->getAdapterName(), 'mysql') !== false ? '`' : '"';
        return $q . str_replace($q, $q . $q, $db->getPrefix() . $name) . $q;
    }

    private static function authorColumn(): string
    {
        return stripos(Db::get()->getAdapterName(), 'mysql') !== false ? 'c.`authorId`' : 'c."authorId"';
    }

    public static function readRow($query, bool $writer = false): array
    {
        $db = Db::get();
        if ($writer && is_object($query) && method_exists($query, 'prepare')) { $query = $query->prepare((string)$query); }
        return $db->fetchRow($writer ? $db->query($query, Db::WRITE) : $query) ?: [];
    }

    public static function readAll($query, bool $writer = false): array
    {
        $db = Db::get();
        if ($writer && is_object($query) && method_exists($query, 'prepare')) { $query = $query->prepare((string)$query); }
        return $db->fetchAll($writer ? $db->query($query, Db::WRITE) : $query);
    }

    /** 所有参数均由 Typecho 适配器转义；不直接拼接用户输入。 */
    public static function sql(string $sql, array $values = []): string
    {
        $query = Db::get()->select();
        foreach ($values as $value) {
            $pos = strpos($sql, '?');
            if ($pos === false) { throw new \LogicException('SQL 参数数量不匹配'); }
            $sql = substr_replace($sql, $query->quoteValue($value), $pos, 1);
        }
        return $query->prepare($sql);
    }

    private static function identity(): string
    {
        $fields = self::table('fields');
        $images = self::table('infinitytime_images');
        return "(EXISTS (SELECT 1 FROM $images i WHERE i.cid = c.cid)"
            . " OR EXISTS (SELECT 1 FROM $fields f WHERE f.cid = c.cid"
            . " AND ((f.name = 'img' AND f.str_value <> '')"
            . " OR (f.name = 'infinitytime_album' AND f.str_value = '1'))))";
    }

    public static function album(int $cid, int $uid, bool $admin, bool $writer = false): ?array
    {
        if ($cid <= 0 || $uid <= 0) { return null; }
        $sql = 'SELECT c.cid, c.title, ' . self::authorColumn() . ', c.status, c.created FROM ' . self::table('contents')
            . " c WHERE c.cid = ? AND c.type = 'post' AND c.status IN ('draft','publish') AND " . self::identity();
        $values = [$cid];
        if (!$admin) { $sql .= ' AND ' . self::authorColumn() . ' = ?'; $values[] = $uid; }
        $row = self::readRow(self::sql($sql . ' LIMIT 1', $values), $writer);
        return $row ?: null;
    }

    public static function page(int $uid, bool $admin, int $page = 1, string $search = ''): array
    {
        $page = max(1, min(100000, $page));
        $search = trim(function_exists('mb_substr') ? mb_substr($search, 0, 100) : substr($search, 0, 100));
        $where = "c.type = 'post' AND c.status IN ('draft','publish') AND " . self::identity();
        $values = [];
        if (!$admin) { $where .= ' AND ' . self::authorColumn() . ' = ?'; $values[] = $uid; }
        if ($search !== '') {
            // 按字面子串搜索；统一使用 ! 转义通配符，兼容各数据库适配器。
            $where .= " AND c.title LIKE ? ESCAPE '!'";
            $values[] = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        }
        $from = ' FROM ' . self::table('contents') . ' c WHERE ' . $where;
        $total = Db::get()->fetchRow(self::sql('SELECT COUNT(*) AS total' . $from, $values));
        $total = (int)($total['total'] ?? 0);
        $pages = max(1, (int)ceil($total / self::PAGE_SIZE));
        $page = min($page, $pages);
        $rows = Db::get()->fetchAll(self::sql('SELECT c.cid, c.title, c.status, c.created' . $from
            . ' ORDER BY c.created DESC, c.cid DESC LIMIT ' . self::PAGE_SIZE
            . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE), $values));
        if ($rows) {
            $cids = array_map(static function ($row) { return (int)$row['cid']; }, $rows);
            $counts = self::counts($cids);
            $fields = Db::get()->fetchAll('SELECT cid, name, str_value FROM ' . self::table('fields')
                . ' WHERE cid IN (' . implode(',', $cids) . ") AND name IN ('device','tags','location')");
            $meta = [];
            foreach ($fields as $field) { $meta[(int)$field['cid']][$field['name']] = $field['str_value']; }
            foreach ($rows as &$row) {
                $cid = (int)$row['cid'];
                $row['img_count'] = $counts[$cid] ?? 0;
                foreach (['device', 'tags', 'location'] as $name) { $row[$name] = (string)($meta[$cid][$name] ?? ''); }
            }
            unset($row);
        }
        return ['items' => $rows, 'page' => $page, 'pages' => $pages, 'total' => $total, 'query' => $search];
    }

    public static function counts(array $cids, bool $writer = false): array
    {
        $cids = array_values(array_filter(array_unique(array_map('intval', $cids)), static function ($cid) { return $cid > 0; }));
        if (!$cids) { return []; }
        $ids = implode(',', $cids);
        $counts = [];
        foreach (self::readAll('SELECT cid, COUNT(*) AS image_count FROM ' . self::table('infinitytime_images')
            . " WHERE cid IN ($ids) GROUP BY cid", $writer) as $row) {
            $counts[(int)$row['cid']] = (int)$row['image_count'];
        }
        // 仅有旧字段的历史图集：在 SQL 内聚合计数，不回传图片列表字段。
        $newline = stripos(Db::get()->getAdapterName(), 'pgsql') !== false || stripos(Db::get()->getAdapterName(), 'postgres') !== false ? 'CHR(10)' : 'CHAR(10)';
        foreach (self::readAll('SELECT cid, LENGTH(str_value) - LENGTH(REPLACE(str_value, ' . $newline . ", '')) + 1 AS image_count FROM "
            . self::table('fields') . " WHERE cid IN ($ids) AND name = 'img' AND str_value <> ''", $writer) as $row) {
            $cid = (int)$row['cid'];
            if (!isset($counts[$cid])) { $counts[$cid] = (int)$row['image_count']; }
        }
        return $counts;
    }

    public static function field(int $cid, string $name, bool $writer = false): string
    {
        $db = Db::get();
        $row = self::readRow($db->select('str_value')->from($db->getPrefix() . 'fields')
            ->where('cid = ?', $cid)->where('name = ?', $name)->limit(1), $writer);
        return (string)($row['str_value'] ?? '');
    }

    public static function setField(int $cid, string $name, string $value): void
    {
        $db = Db::get();
        $table = $db->getPrefix() . 'fields';
        $db->query($db->delete($table)->where('cid = ?', $cid)->where('name = ?', $name));
        if ($value !== '') {
            $db->query($db->insert($table)->rows(['cid' => $cid, 'name' => $name, 'type' => 'str', 'str_value' => $value]));
        }
    }

    public static function draftForOperation(int $uid, string $key): ?array
    {
        $sql = 'SELECT c.cid, c.title, c.status FROM ' . self::table('contents') . ' c WHERE ' . self::authorColumn() . ' = ?'
            . " AND c.type = 'post' AND c.status IN ('draft','publish') AND " . self::identity()
            . ' AND EXISTS (SELECT 1 FROM ' . self::table('fields')
            . " f WHERE f.cid = c.cid AND f.name = 'infinitytime_upload' AND f.str_value = ?) LIMIT 1";
        $row = self::readRow(self::sql($sql, [$uid, $key]), true);
        return $row ?: null;
    }
}
