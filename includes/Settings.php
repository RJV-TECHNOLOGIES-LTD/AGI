<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge;

class Settings {
    public static function get(string $k, $d = '') { return get_option('rjv_agi_' . $k, $d); }
    public static function set(string $k, $v): bool { return update_option('rjv_agi_' . $k, $v); }
    public static function all(): array {
        return ['api_key'=>self::get('api_key'),'openai_key'=>self::get('openai_key')?'***set***':'',
            'anthropic_key'=>self::get('anthropic_key')?'***set***':'','default_model'=>self::get('default_model','anthropic'),
            'openai_model'=>self::get('openai_model','gpt-4.1-mini'),'anthropic_model'=>self::get('anthropic_model','claude-sonnet-4-20250514'),
            'rate_limit'=>(int)self::get('rate_limit',600),'audit_enabled'=>self::get('audit_enabled','1'),
            'allowed_ips'=>self::get('allowed_ips','')];
    }
}
