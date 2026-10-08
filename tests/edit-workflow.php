<?php
/** 由官方 Typecho 集成测试载入；全部数据为随机前缀下的一次性 fixture。 */
use Typecho\Db;
use TypechoPlugin\InfinityTime\Lib\AdminRepository;
use TypechoPlugin\InfinityTime\Lib\AdminWorkflow;
use TypechoPlugin\InfinityTime\Lib\Database;
use TypechoPlugin\InfinityTime\Lib\ImageRepository;

(function () use ($db, $prefix, $dialect): void {
    $draft = AdminWorkflow::draft(7, ['title' => '编辑原子性', 'device' => '旧设备', 'tags' => '旧标签', 'address' => '旧地点'], 'draft-edit-workflow');
    $cid = (int)$draft['cid'];
    $content = static function (int $id) use ($db): array {
        return AdminRepository::readRow($db->select()->from('table.contents')->where('cid = ?', $id), true);
    };
    $snapshot = fields($cid);
    // 复现旧 setField 的 DELETE/INSERT 分散写：插入失败后旧值确实丢失。
    // 仅操作一次性 fixture，不调用或修改生产实现。
    failureTrigger(true);
    try {
        injectedFailure(static function () use ($db, $cid): void {
            Database::query($db->delete('table.fields')->where('cid = ?', $cid)->where('name = ?', 'tags'));
            Database::query($db->insert('table.fields')->rows(['cid' => $cid, 'name' => 'tags', 'type' => 'str', 'str_value' => '失败新值']));
        }, '旧分散字段写入的插入故障能够复现');
    } finally { failureTrigger(false); }
    check(AdminRepository::field($cid, 'tags') === '', '旧 DELETE/INSERT 流程故障导致旧标签丢失');
    AdminRepository::setField($cid, 'tags', '旧标签');
    check(fields($cid) === $snapshot, '复现后恢复 fixture 基线');

    failureTrigger(true);
    try {
        injectedFailure(static function () use ($cid): void { AdminRepository::setField($cid, 'tags', '新标签'); }, '原子单字段插入故障');
        check(fields($cid) === $snapshot, '原子单字段失败保留全部旧字段');
        injectedFailure(static function () use ($cid): void {
            AdminRepository::setFields($cid, ['device' => '新设备', 'tags' => '新标签', 'location' => '新地点']);
        }, '多字段第二次插入故障');
        check(fields($cid) === $snapshot, '多字段中途失败恢复此前字段与待替换字段');
        $oldContent = $content($cid);
        injectedFailure(static function () use ($cid): void {
            AdminWorkflow::updateAlbum($cid, 7, false, false, ['title' => '新标题', 'device' => '新设备', 'tags' => '新标签', 'address' => '新地点']);
        }, '图集标题更新后字段故障');
        check($content($cid) === $oldContent && fields($cid) === $snapshot, '标题、修改时间及三字段必须共同回滚');
    } finally { failureTrigger(false); }
    rejected(static function () use ($cid): void {
        AdminWorkflow::updateAlbum($cid, 8, false, true, ['title' => '越权标题']);
    }, \DomainException::class, '图集编辑拒绝其他作者');
    check($content($cid) === $oldContent && fields($cid) === $snapshot, '越权图集编辑不改动记录');

    // BEGIN 及调用方 SAVEPOINT：成功与失败均不可提交调用方事务。
    foreach (['BEGIN', 'SAVEPOINT caller_edit'] as $outer) {
        Database::query('BEGIN');
        if ($outer !== 'BEGIN') { Database::query($outer); }
        try {
            AdminRepository::setField($cid, 'device', '外层设备');
            $nested = AdminWorkflow::draft(7, ['title' => '外层草稿', 'tags' => 'outer'], 'draft-nested-edit-' . ($outer === 'BEGIN' ? 'begin' : 'savepoint'));
            check($content((int)$nested['cid']) !== [], '外层事务内草稿可见');
            check($db->fetchRow($db->select()->from('table.contents')->where('cid = ?', (int)$nested['cid'])) === null, '嵌套草稿不得提交到读连接');
            check(AdminRepository::draftForOperation(7, 'draft-nested-edit-' . ($outer === 'BEGIN' ? 'begin' : 'savepoint')) !== null, '嵌套草稿操作标识从主库可读');
            AdminWorkflow::updateAlbum($cid, 7, false, false, ['title' => '未提交标题', 'device' => '未提交设备', 'tags' => '未提交标签', 'address' => '未提交地点']);
            check($content($cid)['title'] === '未提交标题', '嵌套图集编辑在主库可见');
            check(AdminRepository::album($cid, 7, false)['title'] === $oldContent['title'], '嵌套图集编辑不提前提交');
            if ($outer !== 'BEGIN') {
                Database::query('ROLLBACK TO SAVEPOINT caller_edit');
                check($content((int)$nested['cid']) === [] && $content($cid) === $oldContent, '调用方保存点回滚撤销草稿和编辑');
                Database::query('RELEASE SAVEPOINT caller_edit');
            }
        } finally { Database::query('ROLLBACK'); }
        check($content((int)$nested['cid']) === [] && fields($cid) === $snapshot && $content($cid) === $oldContent, '外层回滚撤销全部嵌套草稿和编辑');
    }
    if ($dialect === 'SQLite') {
        // SQLite 可以由 SAVEPOINT 自身开启事务；内层不能 RELEASE 掉调用方边界。
        Database::query('SAVEPOINT caller_standalone');
        try {
            $standalone = AdminWorkflow::draft(7, ['title' => '独立保存点草稿'], 'draft-standalone-savepoint');
            check($content((int)$standalone['cid']) !== [], '独立保存点内创建草稿可见');
            check($db->fetchRow($db->select()->from('table.contents')->where('cid = ?', (int)$standalone['cid'])) === null, '独立保存点草稿不得提前提交');
            Database::query('ROLLBACK TO SAVEPOINT caller_standalone');
            check($content((int)$standalone['cid']) === [], '调用方独立保存点仍能回滚草稿');
        } finally { Database::query('RELEASE SAVEPOINT caller_standalone'); }
    }
    failureTrigger(true);
    Database::query('BEGIN');
    try {
        AdminRepository::setField($cid, 'device', '外层故障前写入');
        injectedFailure(static function (): void { AdminWorkflow::draft(7, ['title' => '嵌套失败草稿', 'tags' => 'fail'], 'draft-nested-edit-failure'); }, '嵌套草稿字段故障');
        check(AdminRepository::field($cid, 'device', true) === '外层故障前写入', '失败草稿不得回滚调用方既有写入');
        check(AdminRepository::draftForOperation(7, 'draft-nested-edit-failure') === null, '嵌套失败草稿无操作标识残留');
        injectedFailure(static function () use ($cid): void {
            AdminWorkflow::updateAlbum($cid, 7, false, false, ['title' => '失败标题', 'device' => '失败设备', 'tags' => 'fail']);
        }, '嵌套图集编辑字段故障');
        check($content($cid) === $oldContent && AdminRepository::field($cid, 'device', true) === '外层故障前写入', '嵌套编辑失败恢复自身修改而保留调用方写入');
    } finally { Database::query('ROLLBACK'); failureTrigger(false); }
    check(fields($cid) === $snapshot, '失败嵌套工作流后外层仍可回滚');

    $one = photo($cid, ['title' => '第一张', 'full' => '/fixture/one.webp'], 10);
    $two = photo($cid, ['title' => '第二张', 'full' => '/fixture/two.webp'], 20);
    $three = photo($cid, ['title' => '第三张', 'full' => '/fixture/three.webp'], 30);
    $other = AdminWorkflow::draft(8, ['title' => '排序隔离'], 'draft-edit-other-album');
    $otherId = photo((int)$other['cid']);
    ImageRepository::syncPostFields($cid);
    $images = ImageRepository::rowsFor($cid);
    $snapshot = fields($cid);
    check(!ImageRepository::editImage($one, (int)$other['cid'], '错误图集', '错描述', '错地点'), '编辑图片必须匹配图集 ID');
    check(ImageRepository::rowsFor($cid) === $images && fields($cid) === $snapshot, '错误图集不能改变图片与公共字段');
    failureTrigger(true, 'titles');
    try {
        injectedFailure(static function () use ($one, $cid): void { ImageRepository::editImage($one, $cid, '新图片标题', '新描述', '新地点'); }, '图片更新后公共字段插入失败');
        check(ImageRepository::rowsFor($cid) === $images && fields($cid) === $snapshot, '图片更新及公共字段全部回滚');
        injectedFailure(static function () use ($one, $two, $three, $cid): void { ImageRepository::sortImages($cid, [$three, $one, $two]); }, '排序后公共字段插入失败');
        check(ImageRepository::rowsFor($cid) === $images && fields($cid) === $snapshot, '公共字段故障恢复全部图片顺序');
    } finally { failureTrigger(false); }
    foreach ([[$one, $two], [$one, $two, $two], [$one, $two, $otherId], [$one, $two, $three, $otherId], [], [$one, $two, (string)$three], [$one, $two, 0]] as $invalid) {
        rejected(static function () use ($cid, $invalid): void { ImageRepository::sortImages($cid, $invalid); }, \DomainException::class, '排序拒绝不完整、重复、跨图集或非法 ID 清单');
        check(ImageRepository::rowsFor($cid) === $images && fields($cid) === $snapshot, '非法排序不改动任何行及公共字段');
    }

    // 在反向排序的第二个 UPDATE 注入失败，确保此前第一行 UPDATE 也撤销。
    $updateTrigger = static function (bool $enabled) use ($prefix, $dialect, $two): void {
        $name = $prefix . 'reject_edit_update';
        $fn = $prefix . 'reject_edit_update_fn';
        if (!$enabled) {
            Database::query('DROP TRIGGER IF EXISTS ' . $name . ($dialect === 'Pgsql' ? ' ON ' . table('infinitytime_images') : ''));
            if ($dialect === 'Pgsql') { Database::query('DROP FUNCTION IF EXISTS ' . $fn . '()'); }
            return;
        }
        if ($dialect === 'SQLite') {
            $sql = 'CREATE TRIGGER ' . $name . ' BEFORE UPDATE ON ' . table('infinitytime_images') . " WHEN NEW.id = $two BEGIN SELECT RAISE(ABORT, 'Integration failure'); END";
        } elseif ($dialect === 'Mysql') {
            $sql = 'CREATE TRIGGER ' . $name . ' BEFORE UPDATE ON ' . table('infinitytime_images') . " FOR EACH ROW BEGIN IF NEW.id = $two THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Integration failure'; END IF; END";
        } else {
            Database::query('CREATE FUNCTION ' . $fn . "() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.id = $two THEN RAISE EXCEPTION 'Integration failure'; END IF; RETURN NEW; END $$");
            $sql = 'CREATE TRIGGER ' . $name . ' BEFORE UPDATE ON ' . table('infinitytime_images') . ' FOR EACH ROW EXECUTE PROCEDURE ' . $fn . '()';
        }
        Database::query($sql);
    };
    $updateTrigger(true);
    try {
        injectedFailure(static function () use ($cid, $one, $two, $three): void { ImageRepository::sortImages($cid, [$three, $two, $one]); }, '排序第二行 UPDATE 故障');
        check(ImageRepository::rowsFor($cid) === $images && fields($cid) === $snapshot, '排序 UPDATE 中途失败撤销此前顺序写入');
    } finally { $updateTrigger(false); }
    Database::query('BEGIN');
    try {
        AdminRepository::setField($cid, 'device', '图片编辑外层设备');
        check(ImageRepository::editImage($one, $cid, '嵌套图片标题', '嵌套描述', '嵌套地点'), '嵌套图片编辑成功');
        ImageRepository::sortImages($cid, [$three, $one, $two]);
        check(json_decode(AdminRepository::field($cid, 'photo_ids', true), true) === [$three, $one, $two], '嵌套排序与公共 ID 对齐');
        check(json_decode(AdminRepository::field($cid, 'titles', true), true)[1] === '嵌套图片标题', '嵌套排序保留已编辑标题');
        check(AdminRepository::field($cid, 'device') === '旧设备', '图片编辑与排序不得提交外层修改');
    } finally { Database::query('ROLLBACK'); }
    check(ImageRepository::rowsFor($cid) === $images && fields($cid) === $snapshot, '外层回滚恢复图片编辑、排序及公共字段');
    $writerImages = static function (int $albumId) use ($db): array {
        return AdminRepository::readAll($db->select()->from(ImageRepository::table())->where('cid = ?', $albumId)
            ->order('sort', Db::SORT_ASC)->order('id', Db::SORT_ASC), true);
    };
    $writerFields = static function (int $albumId) use ($db): array {
        return AdminRepository::readAll($db->select()->from('table.fields')->where('cid = ?', $albumId)->order('name', Db::SORT_ASC), true);
    };
    $imagesOnWriter = $writerImages($cid);
    failureTrigger(true, 'titles');
    Database::query('BEGIN');
    try {
        AdminRepository::setField($cid, 'device', '图片失败前设备');
        $fieldsOnWriter = $writerFields($cid);
        injectedFailure(static function () use ($one, $cid): void { ImageRepository::editImage($one, $cid, '失败图片', '失败描述', '失败地点'); }, '嵌套图片编辑故障');
        check($writerImages($cid) === $imagesOnWriter && $writerFields($cid) === $fieldsOnWriter, '图片编辑失败后主库已恢复自身写入，不依赖外层回滚遮盖');
        injectedFailure(static function () use ($cid, $one, $two, $three): void { ImageRepository::sortImages($cid, [$three, $two, $one]); }, '嵌套图片排序故障');
        check($writerImages($cid) === $imagesOnWriter && $writerFields($cid) === $fieldsOnWriter, '排序失败后主库已恢复自身写入，保留调用方字段');
        check(ImageRepository::rowsFor($cid) === $images && AdminRepository::field($cid, 'device', true) === '图片失败前设备', '嵌套图片操作失败保留调用方事务');
    } finally { Database::query('ROLLBACK'); failureTrigger(false); }
    check(fields($cid) === $snapshot, '图片失败后外层事务仍可完整回滚');

    if ($dialect === 'Mysql') {
        foreach (['fields', 'contents', 'infinitytime_images'] as $nonTransactional) {
            Database::query('ALTER TABLE ' . table($nonTransactional) . ' ENGINE=MyISAM');
            try {
                $operations = [];
                if ($nonTransactional !== 'infinitytime_images') {
                    $operations[] = static function () use ($cid): void { AdminWorkflow::updateAlbum($cid, 7, false, false, ['title' => '不应写入', 'tags' => '不应写入']); };
                    $operations[] = static function (): void { AdminWorkflow::draft(7, ['title' => '不应创建', 'tags' => '不应写入'], 'draft-edit-myisam-fail'); };
                }
                if ($nonTransactional === 'fields') {
                    $operations[] = static function () use ($cid): void { AdminRepository::setField($cid, 'tags', '不应写入'); };
                    $operations[] = static function () use ($cid): void { AdminRepository::setFields($cid, ['device' => '不应写入', 'tags' => '不应写入']); };
                }
                if ($nonTransactional !== 'contents') {
                    $operations[] = static function () use ($cid, $one): void { ImageRepository::editImage($one, $cid, '不应写入', '', ''); };
                    $operations[] = static function () use ($cid, $one, $two, $three): void { ImageRepository::sortImages($cid, [$three, $two, $one]); };
                }
                foreach ($operations as $operation) {
                    $beforeCounts = [rowCount('contents'), rowCount('fields'), rowCount('infinitytime_images')];
                    rejected($operation, \RuntimeException::class, $nonTransactional . ' 为 MyISAM 时写前拒绝');
                    check($content($cid) === $oldContent && fields($cid) === $snapshot && ImageRepository::rowsFor($cid) === $images, 'MyISAM 拒绝后现有图集三表完全不变');
                    check(AdminRepository::draftForOperation(7, 'draft-edit-myisam-fail') === null, 'MyISAM 拒绝后不产生草稿');
                    check([rowCount('contents'), rowCount('fields'), rowCount('infinitytime_images')] === $beforeCounts, 'MyISAM 拒绝后无孤立文章或字段残留');
                }
            } finally { Database::query('ALTER TABLE ' . table($nonTransactional) . ' ENGINE=InnoDB'); }
        }
    }
    AdminWorkflow::updateAlbum($cid, 7, false, false, ['title' => '成功标题', 'device' => '成功设备', 'tags' => '成功标签', 'address' => '成功地点']);
    check($content($cid)['title'] === '成功标题' && AdminRepository::field($cid, 'device') === '成功设备'
        && AdminRepository::field($cid, 'tags') === '成功标签' && AdminRepository::field($cid, 'location') === '成功地点', '正常图集编辑共同提交标题及三字段');
    check(ImageRepository::editImage($one, $cid, "照片 O'Reilly ? 雨", '成功描述', '成功地点'), '正常图片编辑返回成功');
    ImageRepository::sortImages($cid, [$three, $one, $two]);
    $edited = ImageRepository::rowsFor($cid)[1];
    check($edited['title'] === "照片 O'Reilly ? 雨" && $edited['desc'] === '成功描述' && $edited['address'] === '成功地点', '编辑成功保存图片行三项元数据');
    check(json_decode(AdminRepository::field($cid, 'descs'), true)[1] === '成功描述' && json_decode(AdminRepository::field($cid, 'addresses'), true)[1] === '成功地点', '编辑描述和地点也与公共字段对齐');
    check(array_map(static function (array $row): int { return (int)$row['id']; }, ImageRepository::rowsFor($cid)) === [$three, $one, $two], '完整清单正常提交精确顺序');
    check(json_decode(AdminRepository::field($cid, 'photo_ids'), true) === [$three, $one, $two]
        && json_decode(AdminRepository::field($cid, 'titles'), true)[1] === "照片 O'Reilly ? 雨", '提交后的公共字段与新图片及顺序对齐');
})();
