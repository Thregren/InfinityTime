<?php
/** SQLite 路径投影基准：10,000 行，每行约 5KB EXIF；不设不稳定的墙钟阈值。 */
declare(strict_types=1);
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE images (id INTEGER PRIMARY KEY, cid INTEGER, original TEXT, full TEXT, thumb TEXT, mid TEXT, avif TEXT, mid_avif TEXT, exif TEXT, title TEXT, width INTEGER, height INTEGER)');
$insert = $pdo->prepare('INSERT INTO images VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
$exif = json_encode(['camera' => 'fixture', 'notes' => str_repeat('x', 5000)]);
$pdo->beginTransaction();
for ($id = 1; $id <= 10000; $id++) {
    $insert->execute([$id, (int)ceil($id / 20), "/original/$id.jpg", "/full/$id.webp", "/thumb/$id.webp",
        "/mid/$id.webp", "/full/$id.avif", "/mid/$id.avif", $exif, "Photo $id", 2400, 1600]);
}
$pdo->commit();
$paths = ['original', 'full', 'thumb', 'mid', 'avif', 'mid_avif'];
function measure(PDO $pdo, string $columns, array $paths): array {
    $start = microtime(true);
    $rows = $pdo->query("SELECT $columns FROM images ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $elapsed = (microtime(true) - $start) * 1000;
    $bytes = 0;
    $digest = hash_init('sha256');
    foreach ($rows as $row) {
        $bytes += strlen(json_encode($row, JSON_UNESCAPED_SLASHES));
        foreach ($paths as $path) { hash_update($digest, (string)$row[$path] . "\n"); }
    }
    return ['ms' => $elapsed, 'bytes' => $bytes, 'digest' => hash_final($digest), 'count' => count($rows)];
}
$all = [];
$projected = [];
for ($pass = 0; $pass < 5; $pass++) {
    // 交替顺序减少首次查询与缓存偏差；耗时仅包含查询和 fetchAll。
    if ($pass % 2 === 0) {
        $all[] = measure($pdo, '*', $paths);
        $projected[] = measure($pdo, implode(',', $paths), $paths);
    } else {
        $projected[] = measure($pdo, implode(',', $paths), $paths);
        $all[] = measure($pdo, '*', $paths);
    }
    if ($all[$pass]['digest'] !== $projected[$pass]['digest'] || $projected[$pass]['count'] !== 10000) {
        throw new RuntimeException('路径投影改变了引用路径');
    }
}
$fullTimes = array_column($all, 'ms');
$pathTimes = array_column($projected, 'ms');
sort($fullTimes);
sort($pathTimes);
if ($projected[0]['bytes'] >= $all[0]['bytes']) { throw new RuntimeException('投影必须降低返回数据量'); }
printf("10,000 rows, 5KB EXIF: SELECT * median %.2f ms, %d JSON bytes; six-path projection median %.2f ms, %d JSON bytes; all path digests identical (5 runs each)\n",
    $fullTimes[2], $all[0]['bytes'], $pathTimes[2], $projected[0]['bytes']);
