<?php
/**
 * InfinityTime 图片分享插件
 *
 * @package InfinityTime
 * @author InfinityTime
 * @version 1.4.4
 * @link https://github.com/infinitytime/infinitytime
 */

namespace TypechoPlugin\InfinityTime;

use Typecho\Db;
use Utils\Helper;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Number;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Text;
use TypechoPlugin\InfinityTime\Lib\ImageRepository;
use TypechoPlugin\InfinityTime\Lib\MediaProcessor;

/**
 * 插件主体。
 */
class Plugin
{
    public const VERSION = '1.4.4';
    public const MENU_NAME = 'InfinityTime';

    /**
     * 激活插件：建表、挂菜单/面板。
     */
    public static function activate(): string
    {
        self::createTables();

        $data = json_encode(MediaProcessor::detectTools(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::setOption('infinitytimeTools', $data);

        $index = Helper::addMenu(self::MENU_NAME);
        Helper::addPanel(
            $index,
            'InfinityTime/panel.php',
            _t('照片与图集'),
            _t('上传、整理图片并发布图集'),
            'contributor',
            false
        );

        return _t('InfinityTime 已启用。上传图片将自动转换成 WebP 并生成缩略图（需服务器 php-gd，HEIC 需 imagemagick/libheif 或 heif-convert）。');
    }

    /**
     * 停用插件：移除菜单/面板。
     */
    public static function deactivate(): string
    {
        Helper::removeMenu(self::MENU_NAME);
        return _t('InfinityTime 已停用（数据表与文件保留）。');
    }

    /**
     * 插件设置项。
     */
    public static function config(Form $form)
    {
        $quality = new Number('infinitytimeQuality', _t('WebP 质量（0-100）'), self::opt('infinitytimeQuality', 82));
        $quality->input->setAttribute('min', '1')->setAttribute('max', '100');
        $form->addInput($quality->addRule('range', _t('质量需在 1-100 之间'), [1, 100]));

        $thumbMax = new Number('infinitytimeThumbMax', _t('缩略图最长边（px）'), self::opt('infinitytimeThumbMax', 1280));
        $thumbMax->input->setAttribute('min', '200')->setAttribute('max', '4096');
        $form->addInput($thumbMax->addRule('range', _t('缩略图尺寸需在 200-4096 之间'), [200, 4096]));

        $maxWidth = new Number('infinitytimeMaxWidth', _t('全图最长边上限（px，0=不裁剪）'), self::opt('infinitytimeMaxWidth', 0));
        $maxWidth->input->setAttribute('min', '0')->setAttribute('max', '20000');
        $form->addInput($maxWidth->addRule('range', _t('上限需在 0-20000 之间'), [0, 20000]));

        $keep = new Radio('infinitytimeKeepOriginal', _t('是否保留原始文件'),
            [
                '1' => _t('保留原始上传（original/）'),
                '0' => _t('不保留（省空间）'),
            ],
            (string)self::opt('infinitytimeKeepOriginal', '1'),
            _t('关闭后上传完原始文件即删除，只留 full/ + thumb/。')
        );
        $form->addInput($keep);

        $heif = new Text('infinitytimeHeif', _t('heif-convert 路径（可选）'),
            self::opt('infinitytimeHeif', MediaProcessor::detectTools()['heif'] ?: ''),
            _t('如 iamgemagick 不可用，可填服务器上 heif-convert 的绝对路径，用于 HEIC 解码。')
        );
        $form->addInput($heif);

        $submit = new Text('infinitytimeSubmitNote', _t('后台发布页说明'),
            self::opt('infinitytimeSubmitNote', '在「照片与图集」页面上传图片并填写地址即可发布。'),
            _t('自定义后台发布页的顶部说明文本。')
        );
        $form->addInput($submit);
    }

    /**
     * 全局上传钩子：把任何上传的图片自动转成 WebP 全图 + 缩略图，并保留原图。
     *
     * @param array $file $_FILES 单项
     * @return array|false 返回 Typecho 上传结果；非图片/失败则回退默认处理
     */
    public static function uploadHandle(array $file)
    {
        $name = $file['name'] ?? '';
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (!in_array($ext, MediaProcessor::supportedExtensions(), true)) {
            return self::defaultUploadHandle($file);
        }

        try {
            $meta = ImageRepository::ingest($file, [
                'quality' => (int)self::opt('infinitytimeQuality', 82),
                'thumb_max' => (int)self::opt('infinitytimeThumbMax', 1280),
                'max_width' => (int)self::opt('infinitytimeMaxWidth', 0),
                'keep_original' => (bool)self::opt('infinitytimeKeepOriginal', '1'),
            ]);
        } catch (\Throwable $e) {
            self::log('uploadHandle: ' . $e->getMessage());
            $meta = null;
        }

        if (!$meta) {
            return self::defaultUploadHandle($file);
        }

        // 记录为“未归档”图片（cid=0），清理时不会误删
        try {
            ImageRepository::insertRow(0, $meta, 0);
        } catch (\Throwable $e) {
            self::log('insertRow(cid=0): ' . $e->getMessage());
        }

        return [
            'name' => $name,
            'path' => $meta['full'],
            'size' => $meta['size'],
            'type' => 'webp',
            'mime' => 'image/webp',
        ];
    }

    /**
     * Typecho 默认上传逻辑的等价实现（当我们的钩子无法处理时回退）。
     */
    private static function defaultUploadHandle(array $file): ?array
    {
        if (empty($file['name'])) {
            return false;
        }

        $options = Helper::options();
        $ext = self::safeName($file['name']);
        if (!in_array($ext, $options->allowedAttachmentTypes ?? [], true)) {
            return false;
        }

        $date = new \Typecho\Date();
        $root = ImageRepository::uploadRoot();
        $path = $root . '/' . $date->year . '/' . $date->month;
        if (!is_dir($path) && !@mkdir($path, 0755, true) && !is_dir($path)) {
            return false;
        }

        $fileName = sprintf('%u', crc32(uniqid())) . '.' . $ext;
        $path = $path . '/' . $fileName;

        if (isset($file['tmp_name'])) {
            if (!@move_uploaded_file($file['tmp_name'], $path)) {
                return false;
            }
        } elseif (isset($file['bytes'])) {
            if (@file_put_contents($path, $file['bytes']) === false) {
                return false;
            }
        } else {
            return false;
        }

        if (!isset($file['size'])) {
            $file['size'] = filesize($path);
        }

        return [
            'name' => $file['name'],
            'path' => ImageRepository::toWeb($path),
            'size' => $file['size'],
            'type' => $ext,
            'mime' => mime_content_type($path),
        ];
    }

    /** 创建插件数据表。 */
    private static function createTables(): void
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        $table = $prefix . 'infinitytime_images';
        $real = $db->getAdapterName();

        $isMysql = stripos($real, 'mysql') !== false;
        $pk = $isMysql
            ? '`id` int unsigned NOT NULL AUTO_INCREMENT'
            : '"id" integer NOT NULL PRIMARY KEY AUTOINCREMENT';
        $q = $isMysql ? '`' : '"';

        $sql = "CREATE TABLE IF NOT EXISTS {$q}{$table}{$q} (
            {$pk},
            {$q}cid{$q} integer NOT NULL DEFAULT 0,
            {$q}original{$q} text,
            {$q}full{$q} text,
            {$q}thumb{$q} text,
            {$q}width{$q} integer DEFAULT 0,
            {$q}height{$q} integer DEFAULT 0,
            {$q}size{$q} integer DEFAULT 0,
            {$q}sort{$q} integer DEFAULT 0,
            {$q}hash{$q} varchar(64) DEFAULT '',
            {$q}exif{$q} text,
            {$q}gps_lat{$q} real,
            {$q}gps_lng{$q} real,
            {$q}address{$q} text,
            {$q}title{$q} text,
            {$q}desc{$q} text,
            {$q}created{$q} integer DEFAULT 0
        )";
        if ($isMysql) {
            $sql .= " engine=InnoDB DEFAULT CHARSET=utf8mb4";
        }
        $db->query($sql);

        // 兼容已存在的旧表：补充 title/desc 列
        foreach (['title', 'desc'] as $col) {
            try {
                $db->query("ALTER TABLE {$q}{$table}{$q} ADD COLUMN {$q}{$col}{$q} text");
            } catch (\Throwable $e) {
                // 列已存在则忽略
            }
        }

        // 索引（跨库兼容：SQLite 支持 IF NOT EXISTS；MySQL/MariaDB 需 try/catch 忽略已存在）
        try {
            if ($isMysql) {
                $db->query("CREATE INDEX idx_{$table}_cid ON {$q}{$table}{$q} (`cid`)");
            } else {
                $db->query("CREATE INDEX idx_cid ON {$q}{$table}{$q} ({$q}cid{$q})");
            }
        } catch (\Throwable $e) {
            // 已存在或不受支持时忽略
        }
    }

    private static function safeName(string $name): string
    {
        $info = pathinfo($name);
        return strtolower(preg_replace("/[^a-zA-Z0-9]+/", '', $info['extension'] ?? ''));
    }

    /** 读取插件选项（带默认值）。 */
    public static function opt(string $name, $default = null)
    {
        $options = Helper::options();
        $value = $options->{$name} ?? null;
        if ($value !== null && $value !== '') {
            return $value;
        }
        return $default;
    }

    /** 写插件选项。 */
    public static function setOption(string $name, $value): void
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        $val = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string)$value;
        $exists = $db->fetchRow(
            $db->select('name')->from($prefix . 'options')->where('name = ?', $name)->limit(1)
        );
        if ($exists) {
            $db->query($db->update($prefix . 'options')->rows(['value' => $val])->where('name = ?', $name));
        } else {
            $db->query($db->insert($prefix . 'options')->rows(['name' => $name, 'user' => 0, 'value' => $val]));
        }
        $options = Helper::options();
        $options->{$name} = $value;
    }

    public static function log(string $msg): void
    {
        error_log('[InfinityTime] ' . $msg);
    }
}

// 全局上传钩子注册（放在文件顶层，插件加载时执行）
\Typecho\Plugin::factory('Widget_Upload')->uploadHandle = 'TypechoPlugin\\InfinityTime\\Plugin::uploadHandle';
