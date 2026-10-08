<?php
/**
 * 真实隔离 SQLite 的大相册读取基准，PHP 7.4+；无需 Typecho 或网络数据库。
 * 比较旧聚合字段读取与当前公开入口的返回量，耗时不设机器相关阈值。
 * 字节数为 fetchAll 结果的 JSON 大小，不代表数据库网络协议或线上响应耗时。
 */
declare(strict_types=1);
namespace Typecho {
    class Query {
        private array $columns, $where = [], $order = [];
        private string $table = '', $joins = '';
        private int $maximum = 0, $start = 0;
        public function __construct(array $columns) { $this->columns = $columns; }
        public function from(string $table): self { $this->table = $table; return $this; }
        public function where(string $sql, ...$values): self {
            foreach ($values as $value) { $sql = substr_replace($sql, Db::$instance->pdo->quote((string)$value), (int)strpos($sql, '?'), 1); }
            $this->where[] = '(' . $sql . ')'; return $this;
        }
        public function order(string $column, string $direction): self { $this->order[] = $column . ' ' . $direction; return $this; }
        public function limit(int $n): self { $this->maximum = $n; return $this; }
        public function offset(int $n): self { $this->start = $n; return $this; }
        public function join(string $table, string $condition): self { $this->joins .= ' INNER JOIN ' . $table . ' ON ' . $condition; return $this; }
        public function __toString(): string {
            $columns = array_map(static function ($column) { return implode('.', array_map(static function ($part) { return '"' . $part . '"'; }, explode('.', $column))); }, $this->columns);
            return 'SELECT ' . implode(',', $columns) . ' FROM ' . $this->table . $this->joins
                . ($this->where ? ' WHERE ' . implode(' AND ', $this->where) : '')
                . ($this->order ? ' ORDER BY ' . implode(',', $this->order) : '')
                . ($this->maximum ? ' LIMIT ' . $this->maximum . ' OFFSET ' . $this->start : '');
        }
    }
    class Db {
        public const SORT_ASC = 'ASC', SORT_DESC = 'DESC';
        public static self $instance;
        public \PDO $pdo;
        public bool $track = false;
        public array $reads = [];
        public function __construct() { $this->pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]); }
        public static function get(): self { return self::$instance; }
        public function getPrefix(): string { return 'perf_'; }
        public function select(...$columns): Query { return new Query($columns); }
        public function fetchAll(Query $query): array {
            $rows = $this->pdo->query((string)$query)->fetchAll(\PDO::FETCH_ASSOC);
            if ($this->track) { $this->reads[] = ['rows' => count($rows), 'bytes' => strlen(json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))]; }
            return $rows;
        }
    }
}
namespace {
    require __DIR__ . '/../usr/themes/InfinityTime/lib/gallery.php';
    $db = \Typecho\Db::$instance = new \Typecho\Db(); $pdo = $db->pdo;
    $pdo->exec('CREATE TABLE perf_contents (cid INTEGER PRIMARY KEY,title TEXT,text TEXT,created INTEGER,status TEXT,type TEXT,password TEXT)');
    $pdo->exec('CREATE TABLE perf_fields (cid INTEGER,name TEXT,str_value TEXT,PRIMARY KEY(cid,name))');
    $pdo->exec('CREATE TABLE perf_relationships (cid INTEGER,mid INTEGER)');
    $pdo->exec('CREATE TABLE perf_metas (mid INTEGER,name TEXT,slug TEXT,type TEXT)');
    $pdo->exec('CREATE TABLE perf_infinitytime_images (id INTEGER PRIMARY KEY,cid INTEGER,sort INTEGER,full TEXT,thumb TEXT,mid TEXT,avif TEXT,mid_avif TEXT,width INTEGER,height INTEGER,exif TEXT,title TEXT,desc TEXT,address TEXT,created INTEGER,original TEXT,gps_lat REAL,gps_lng REAL,hash TEXT)');
    $pdo->exec('CREATE INDEX perf_album_images ON perf_infinitytime_images(cid,sort,id)');
    $pdo->exec("INSERT INTO perf_contents VALUES (1,'Fixture album','',1704067200,'publish','post','')");
    $photoInsert = $pdo->prepare('INSERT INTO perf_infinitytime_images VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $fieldInsert = $pdo->prepare('INSERT INTO perf_fields VALUES (1,?,?)');
    $baseColumns = ['id','cid','full','thumb','width','height','exif','title','desc','address','created'];
    $base = 'https://example.test/'; $request = pp_gallery_request(['limit' => 1]);
    // 保存旧路径的有界照片查询及字段变体关联，作为数据与耗时比较基线。
    $baseline = static function () use ($db, $request, $base, $baseColumns): array {
        return pp_gallery_page($request, static function ($upper, $take, $r) use ($db): array {
            $query = $db->select('cid','title','text','created','status','type','password')->from('perf_contents')
                ->where('type = ?', 'post')->where('status = ?', 'publish')->where('(password IS NULL OR password = ?)', '')
                ->where('created <= ?', time())->order('cid', \Typecho\Db::SORT_DESC)->limit($take);
            if ($upper > 0) { $query->where('cid <= ?', $upper); }
            if ($r['album'] > 0) { $query->where('cid = ?', $r['album']); }
            $rows = $db->fetchAll($query);
            if (!$rows) { return []; }
            $cids = array_column($rows, 'cid'); $ph = implode(',', array_fill(0, count($cids), '?'));
            $names = implode(',', array_fill(0, count(PP_GALLERY_FIELD_NAMES), '?'));
            $fields = $db->fetchAll($db->select('cid','name','str_value')->from('perf_fields')
                ->where('cid IN (' . $ph . ')', ...$cids)->where('name IN (' . $names . ')', ...PP_GALLERY_FIELD_NAMES));
            // 与旧相册入口相同的第三条批量分类查询；基准夹具没有原生分类。
            $db->fetchAll($db->select('perf_relationships.cid','perf_metas.name','perf_metas.slug','perf_metas.type')
                ->from('perf_relationships')->join('perf_metas', 'perf_relationships.mid = perf_metas.mid')
                ->where('perf_relationships.cid IN (' . $ph . ')', ...$cids)->where('perf_metas.type IN (?, ?)', 'tag', 'category'));
            $grouped = []; foreach ($fields as $field) { $grouped[(int)$field['cid']][$field['name']] = $field['str_value']; }
            foreach ($rows as &$row) { $row['fields'] = $grouped[(int)$row['cid']] ?? []; } unset($row);
            return $rows;
        }, static function ($album, $offset, $take, $target) use ($db, $baseColumns): array {
            $rows = $db->fetchAll($db->select(...$baseColumns)->from('perf_infinitytime_images')->where('cid = ?', (int)$album['cid'])
                ->order('sort', \Typecho\Db::SORT_ASC)->order('id', \Typecho\Db::SORT_ASC)->limit($take + 1)->offset($offset));
            $variants = pp_gallery_json_array($album['fields']['variants'] ?? '');
            $ids = pp_gallery_json_array($album['fields']['photo_ids'] ?? '');
            $byId = []; $byUrl = [];
            foreach ($ids as $index => $id) { $byId[(int)$id] = $variants[$index] ?? []; }
            foreach (preg_split('/\r?\n/', (string)($album['fields']['img'] ?? '')) ?: [] as $index => $url) {
                $url = pp_gallery_media_url(trim($url)); if ($url !== '') { $byUrl[$url] = $variants[$index] ?? []; }
            }
            $photos = [];
            foreach (array_slice($rows, 0, $take) as $row) {
                $row['variants'] = $byId[(int)$row['id']] ?? $byUrl[$row['full']] ?? [];
                $photo = pp_gallery_photo($row, (int)$album['cid'], (int)$album['created']); if ($photo) { $photos[] = $photo; }
            }
            return ['photos' => $photos, 'has_more' => count($rows) > $take, 'consumed' => min($take, count($rows))];
        }, $base);
    };
    $projected = static function () use ($request, $base): array { return pp_gallery_read($request, $base); };
    $lastBytes = 0; $boundedBytes = null;
    foreach ([100, 1000, 5000] as $size) {
        $pdo->exec('DELETE FROM perf_infinitytime_images'); $pdo->exec('DELETE FROM perf_fields');
        $lists = array_fill_keys(['img','thumb','addresses','titles','descs','panos','dims','variants','exif','photo_ids','months'], []);
        $pdo->beginTransaction();
        for ($id = 1; $id <= $size; $id++) {
            $exif = ['make' => 'Camera', 'model' => 'Fixture', 'datetime' => '2026:01:02 12:34:56', 'note' => str_repeat('x', 1000), '_infinity_media_version' => str_repeat('a', 32)];
            $full = '/full/' . $id . '.webp'; $thumb = '/thumb/' . $id . '.webp'; $mid = '/mid/' . $id . '.webp';
            $avif = '/full/' . $id . '.avif'; $midAvif = '/mid/' . $id . '.avif'; $suffix = '?itv=' . str_repeat('a', 32);
            $photoInsert->execute([$id,1,$id,$full,$thumb,$mid,$avif,$midAvif,2400,1600,json_encode($exif),"Photo $id",'Description','Location',1704067200,'/original/private.jpg',51.5,-0.1,'private']);
            $lists['img'][] = $full . $suffix; $lists['thumb'][] = $thumb . $suffix;
            $lists['addresses'][] = 'Location'; $lists['titles'][] = "Photo $id"; $lists['descs'][] = 'Description';
            $lists['panos'][] = 0; $lists['dims'][] = '2400x1600'; $lists['photo_ids'][] = $id; $lists['months'][] = '2026-01';
            unset($exif['_infinity_media_version']); $lists['exif'][] = $exif;
            $lists['variants'][] = ['w' => 2400, 'webp' => [$full . $suffix, $mid . $suffix], 'avif' => [$avif . $suffix, $midAvif . $suffix]];
        }
        foreach ($lists as $name => $values) { $fieldInsert->execute([$name, in_array($name, ['img','thumb'], true) ? implode("\n", $values) : json_encode($values, JSON_UNESCAPED_SLASHES)]); }
        foreach (['tags' => 'Fixture', 'device' => 'Camera', 'location' => 'Location'] as $name => $value) { $fieldInsert->execute([$name, $value]); }
        $pdo->commit(); unset($lists);
        $times = ['baseline' => [], 'projected' => []];
        for ($pass = 0; $pass < 5; $pass++) {
            foreach ($pass % 2 === 0 ? ['baseline','projected'] : ['projected','baseline'] as $name) {
                $start = microtime(true); $result = ${$name}(); $times[$name][] = (microtime(true) - $start) * 1000;
            }
        }
        $db->track = true; $db->reads = []; $before = $baseline(); $beforeReads = $db->reads;
        $db->reads = []; $after = $projected(); $afterReads = $db->reads; $db->track = false;
        if ($before !== $after || count($after['albums'][0]['photos']) !== 60) { throw new \RuntimeException('投影改变了照片、元数据、变体或分页结果'); }
        if (preg_match('/original|gps|private|note|_infinity_media_version/', json_encode($after))) { throw new \RuntimeException('公开响应泄漏私有字段'); }
        $beforeBytes = array_sum(array_column($beforeReads, 'bytes')); $afterBytes = array_sum(array_column($afterReads, 'bytes'));
        if ($beforeBytes <= $lastBytes || ($boundedBytes !== null && $boundedBytes !== $afterBytes) || $afterBytes >= $beforeBytes) { throw new \RuntimeException('现代仓库返回量必须不随相册聚合字段增长'); }
        $lastBytes = $beforeBytes; $boundedBytes = $afterBytes;
        foreach ($times as &$values) { sort($values); } unset($values);
        printf("%d photos, 1KB EXIF note: old reads %d JSON bytes, projected %d; page median %.2f / %.2f ms; identical 60-photo response\n", $size, $beforeBytes, $afterBytes, $times['baseline'][2], $times['projected'][2]);
    }
    // 使用真实 SQLite 缺列/缺表错误验证旧安装回退；不迁移表、不触及文件。
    $pdo->exec('CREATE TABLE legacy_images AS SELECT id,cid,sort,full,thumb,width,height,exif,title,desc,address,created FROM perf_infinitytime_images');
    $pdo->exec('DROP TABLE perf_infinitytime_images'); $pdo->exec('ALTER TABLE legacy_images RENAME TO perf_infinitytime_images');
    if ($projected() !== $before) { throw new \RuntimeException('缺少变体列的旧表改变公开输出'); }
    $pdo->exec('DROP TABLE perf_infinitytime_images');
    if ($projected() !== $before) { throw new \RuntimeException('无插件表的字段相册改变公开输出'); }
    echo "Real SQLite old-schema and field-only fallback outputs identical; no timing thresholds.\n";
}
