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
    /** 清洗 HTML：保留常规排版标签，去掉脚本/事件/危险协议。 */
    public static function sanitize(string $html): string
    {
        $html = (string)preg_replace('#<(script|style|iframe|object|embed|link|meta)\b[^>]*>.*?</\1>#is', '', $html);
        $html = (string)preg_replace('#<(script|style|iframe|object|embed|link|meta)\b[^>]*/?>#is', '', $html);
        $html = (string)preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#is', '', $html);
        // 危险协议：同时覆盖带引号和不带引号的写法
        $html = (string)preg_replace(
            '#(href|src)\s*=\s*(?:"\s*(?:javascript|data):[^"]*"|\'\s*(?:javascript|data):[^\']*\'|(?:javascript|data):[^\s>]+)#is',
            '$1="#"',
            $html
        );
        return trim($html);
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
