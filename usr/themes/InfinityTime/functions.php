<?php
error_reporting(0);

/**
 * 读取 InfinityTime 站点信息；优先取 typecho_options 里的插件值，为空时回退默认值。
 * $options 传主题的 $this->options 即可。
 */
function pp_opt($name, $default = '', $options = null)
{
    if ($options !== null && isset($options->{$name}) && $options->{$name} !== '') {
        return $options->{$name};
    }
    return $default;
}

/**
 * 把 EXIF 的时间字符串（如 2026:06:21 17:46:53）转为中文格式（2026年06月21日 17:46:53）。
 */
function pp_date_cn($s)
{
    if (preg_match('/^(\d{4}):(\d{2}):(\d{2})[ T]?(\d{2}:\d{2}(?::\d{2})?)?/', (string)$s, $m)) {
        return $m[1] . '年' . $m[2] . '月' . $m[3] . '日' . ($m[4] ? ' ' . $m[4] : '');
    }
    return (string)$s;
}

/** 镜头标签：直接使用 EXIF 读取到的镜头描述；无则不显示（不做推测）。 */
function pp_exif_lens(array $exif): string
{
    return !empty($exif['lens']) ? (string)$exif['lens'] : '';
}

function themeConfig($form)
{
  $theme_info = get_theme_info();
  $selfmessage = $theme_info['version'] ?? '0.0';

  echo '<p style="margin:0 0 1em;color:#666">InfinityTime 主题 · 当前版本 v' . htmlspecialchars($selfmessage) . '</p>';
  //首页名称
  $IndexName = new Typecho_Widget_Helper_Form_Element_Text('IndexName', NULL, '无限时光', _t('首页的名称(必填)'), _t('输入你的首页显示的名称'));
  $form->addInput($IndexName);
  //网站图标
  $IconUrl = new Typecho_Widget_Helper_Form_Element_Text('IconUrl', NULL, 'https://bu.dusays.com/2024/04/23/662770aaee40e.webp', _t('网站图标地址'), _t('输入网站的图标（建议200px宽度png）'));
  $form->addInput($IconUrl);
  //Apple网站图标
  $AppleIcon = new Typecho_Widget_Helper_Form_Element_Text('AppleIcon', NULL, '', _t('兼容Apple设备的图标'), _t('建议使用有背景无圆角矩形图标，在被iOS添加到书签或桌面后显示此图标（建议200px宽度png）'));
  $form->addInput($AppleIcon);
  //首页名称后缀（必填）
  $Indexdict = new Typecho_Widget_Helper_Form_Element_Text('Indexdict', NULL, '采用无限时光主题。', _t('首页的名称后缀(必填)'), _t('输入你的首页显示的名称后缀'));
  $form->addInput($Indexdict);
  $zmkiabout = new Typecho_Widget_Helper_Form_Element_Text('zmkiabout', NULL, '无限时光', _t('自定义底栏前缀'), _t('输入你的首页底部栏前缀'));
  $form->addInput($zmkiabout);
  $zmkiabouts = new Typecho_Widget_Helper_Form_Element_Text('zmkiabouts', NULL, '采用无限时光主题', _t('自定义底栏后缀'), _t('输入你的首页底部栏后缀'));
  $form->addInput($zmkiabouts);
  //大logo
  $Biglogo = new Typecho_Widget_Helper_Form_Element_Text('Biglogo', NULL, '欢迎使用InfinityTime，这里填写你的介绍。', _t('关于-详细介绍'), _t('底栏展开后的详细介绍，可以使用html标签'));
  $form->addInput($Biglogo);
  $zmki_ys = new Typecho_Widget_Helper_Form_Element_Text('zmki_ys', NULL, '', _t('缩略图-图片处理规则名称-(优化选项,选填)'), _t('需要带自定义分隔符;使用oss图片处理生成小缩略图可优化页面打开速度; 使用帮助:https://www.zmki.cn/4956.html'));
  $form->addInput($zmki_ys);
  $zmki_sy = new Typecho_Widget_Helper_Form_Element_Text('zmki_sy', NULL, '', _t('图片版权水印-图片处理规则名称-(优化选项,选填)'), _t('需要带自定义分隔符;此处可填写oss水印规则名称，默认对全部图片生效; 使用帮助:https://www.zmki.cn/4956.html'));
  $form->addInput($zmki_sy);
  $xxhome = new Typecho_Widget_Helper_Form_Element_Text('xxhome', NULL, '', _t('Home'), _t('填写你的主页链接 http(s)://'));
  $form->addInput($xxhome);
  $xxweibo = new Typecho_Widget_Helper_Form_Element_Text('xxweibo', NULL, '', _t('Weibo'), _t('填写你的weibo链接  http(s)://'));
  $form->addInput($xxweibo);
  $xxgithub = new Typecho_Widget_Helper_Form_Element_Text('xxgithub', NULL, '', _t('GitHub'), _t('填写你的GitHub链接  http(s)://'));
  $form->addInput($xxgithub);
  $police = new Typecho_Widget_Helper_Form_Element_Text('police', NULL, '', _t('公安备案号'), _t('如果你在国内有公安备案，可在此处填写'));
  $form->addInput($police);
  $icp = new Typecho_Widget_Helper_Form_Element_Text('icp', NULL, '', _t('ICP备案号'), _t('如果你在国内有备案，可在此处填写'));
  $form->addInput($icp);
  $cnzz = new Typecho_Widget_Helper_Form_Element_Text('cnzz', NULL, '', _t('统计代码'), _t('cnzz或百度..统计代码。可在此处填写处'));
  $form->addInput($cnzz);
}
//输出导航
function themeFields($layout)
{
  $img = new Typecho_Widget_Helper_Form_Element_Textarea('img', NULL, NULL, _t('图片链接'), _t('请输入要展示的图片链接，每行一个链接。为了保证良好的体验，建议同一个文章下图片尺寸一致。'));
  $img->input->setAttribute('class', 'w-100 custom-textarea');
  $img->input->setAttribute('style', 'height: 200px');
  $layout->addItem($img);
  
  $device = new Typecho_Widget_Helper_Form_Element_Text('device', NULL, NULL, _t('设备信息'), _t('请输入拍摄设备信息'));
  $device->input->setAttribute('class', 'w-100');
  $layout->addItem($device);

  $location = new Typecho_Widget_Helper_Form_Element_Text('location', NULL, NULL, _t('拍摄地点'), _t('请输入拍摄地点信息'));
  $location->input->setAttribute('class', 'w-100');
  $layout->addItem($location);
}

// 添加获取主题信息的函数
function get_theme_info() {
    $index_file = __DIR__ . '/index.php';
    if (!file_exists($index_file)) {
        return ['version' => '0.0'];
    }
    
    $content = file_get_contents($index_file);
    preg_match('/@version\s+(.*)/', $content, $matches);
    
    return [
        'version' => isset($matches[1]) ? trim($matches[1]) : '0.0'
    ];
}
