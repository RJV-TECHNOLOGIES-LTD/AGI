<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge;

class Auth {
    public static function check(\WP_REST_Request $r): bool {
        $provided = $r->get_header('X-RJV-AGI-Key');
        if (empty($provided)) return false;
        if (!hash_equals((string)get_option('rjv_agi_api_key',''), $provided)) return false;
        $allowed = trim((string)get_option('rjv_agi_allowed_ips',''));
        if (!empty($allowed)) {
            $ip = self::ip();
            if (!in_array($ip, array_map('trim', explode("\n", $allowed)), true)) {
                AuditLog::log('auth_ip_denied','auth',0,['ip'=>$ip],1,'error');
                return false;
            }
        }
        return true;
    }
    public static function tier1(\WP_REST_Request $r): bool { return self::check($r); }
    public static function tier2(\WP_REST_Request $r): bool { return self::check($r); }
    public static function tier3(\WP_REST_Request $r): bool { return self::check($r); }
    private static function ip(): string {
        foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $h) {
            if (!empty($_SERVER[$h])) { $ip = explode(',', sanitize_text_field(wp_unslash($_SERVER[$h])))[0];
                if (filter_var(trim($ip), FILTER_VALIDATE_IP)) return trim($ip); }
        }
        return '0.0.0.0';
    }
}
