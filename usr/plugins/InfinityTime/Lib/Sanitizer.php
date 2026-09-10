<?php
namespace TypechoPlugin\InfinityTime\Lib;

/**
 * 轻量 HTML / URL 清洗：
 *  - 后台「关于介绍」允许 HTML，但保存前去掉脚本、事件属性与危险协议
 *  - 头像等 URL 只接受 http(s) 绝对地址或站内相对路径
 *
 * 独立成类是为了能在 CI 里脱离 Typecho 直接单测。
 */
class Sanitizer
{
    /** 白名单标签 → 允许保留的属性（其它标签与属性一律去掉）。 */
    private const ALLOWED = [
        'p' => [], 'br' => [], 'strong' => [], 'em' => [], 'b' => [], 'i' => [],
        'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [],
        'h2' => [], 'h3' => [], 'h4' => [],
        'a' => ['href', 'title'],
    ];

    /** 这些标签连同内容一起删除（不是 unwrap）。 */
    private const DROP = [
        'script', 'style', 'iframe', 'object', 'embed', 'link', 'meta', 'base',
        'svg', 'math', 'form', 'input', 'button', 'select', 'option', 'textarea',
        'video', 'audio', 'source', 'track', 'img', 'picture', 'canvas', 'noscript',
    ];

    /**
     * 清洗 HTML（白名单 + DOM 解析）。
     *
     * 之前用正则黑名单，实测可被 `<svg/onload=…>`、HTML 实体编码的
     * `javascript:`、`java\nscript:`、`<form action=…>` 等绕过；改为：
     *  - DOM 解析后只保留白名单标签；
     *  - 每条允许的标签只保留白名单属性（`on*`、`style` 等一律删除）；
     *  - 只保留白名单标签，其它标签 unwrap（保留文字）；
     *  - `<a href>` 走 safeLink() 协议校验。
     */
    public static function sanitize(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        if (!class_exists('\DOMDocument')) {
            // 兜底：无 DOM 扩展时用标签白名单，至少挡住脚本标签
            return trim(strip_tags($html, '<p><br><strong><em><b><i><ul><ol><li><blockquote><h2><h3><h4><a>'));
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadHTML(
            '<?xml encoding="UTF-8"><div id="pp-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) {
            return '';
        }
        $root = $doc->getElementsByTagName('div')->item(0);
        if (!$root) {
            return '';
        }
        $allowed = self::ALLOWED;
        $drop = self::DROP;
        $walk = function (\DOMNode $node) use (&$walk, $allowed, $drop): void {
            foreach (iterator_to_array($node->childNodes) as $child) {
                if ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                    $node->removeChild($child);
                    continue;
                }
                if ($child->nodeType !== XML_ELEMENT_NODE) {
                    continue;
                }
                $tag = strtolower($child->nodeName);
                if (in_array($tag, $drop, true)) {
                    $node->removeChild($child);
                    continue;
                }
                if (!isset($allowed[$tag])) {
                    // 非白名单标签：unwrap——把子节点提到当前节点前，保留文字内容
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }
                foreach (iterator_to_array($child->attributes) as $attr) {
                    if (!in_array(strtolower($attr->nodeName), $allowed[$tag], true)) {
                        $child->removeAttribute($attr->nodeName);
                    }
                }
                if ($tag === 'a') {
                    $href = (string)$child->getAttribute('href');
                    if ($href !== '' && self::safeLink($href) === '') {
                        $child->removeAttribute('href');
                    }
                }
                $walk($child);
            }
        };
        $walk($root);
        $out = '';
        foreach (iterator_to_array($root->childNodes) as $c) {
            $out .= $doc->saveHTML($c);
        }
        return trim($out);
    }

    /**
     * 校验链接协议：允许 http(s) / mailto / tel / 相对路径 / 锚点 / 协议相对；
     * 其余（javascript:、data:、vbscript: 等）返回空串。
     * 会先剔除空白与控制字符并解码 HTML 实体，防止 `java\nscript:`、`&#x6a;avascript:` 绕过。
     */
    public static function safeLink(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $probe = (string)preg_replace('/[\x00-\x20\x7f]+/', '', $url);
        $probe = html_entity_decode($probe, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $probe, $m)) {
            $scheme = strtolower($m[1]);
            return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true) ? $url : '';
        }
        return $url;
    }

    /** 只允许 http(s) 绝对地址或站内相对路径。 */
    public static function validUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^(https?:)?//#i', $url) || (strpos($url, '/') === 0 && strpos($url, '//') !== 0)) {
            return $url;
        }
        return '';
    }
}
