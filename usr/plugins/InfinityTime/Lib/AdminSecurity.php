<?php
namespace TypechoPlugin\InfinityTime\Lib;

/** 原生表单与 JavaScript 写操作共用；校验缺失时默认拒绝。 */
final class AdminSecurity
{
    public static function validMutation(string $method, string $expectedToken, $providedToken): bool
    {
        return $method === 'POST' && $expectedToken !== '' && is_string($providedToken)
            && hash_equals($expectedToken, $providedToken);
    }

    public static function validKey($value): bool
    {
        return is_string($value) && preg_match('/\A[a-zA-Z0-9_-]{16,64}\z/D', $value) === 1;
    }
}
