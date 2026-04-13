<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;
use RJV_AGI_Bridge\Auth;
use RJV_AGI_Bridge\AI\Router;
use RJV_AGI_Bridge\AuditLog;

class SiteHealth extends Base {
    public function register_routes(): void {
        register_rest_route($this->namespace,'/health',[['methods'=>'GET','callback'=>[$this,'health'],'permission_callback'=>[Auth::class,'tier1']]]);
        register_rest_route($this->namespace,'/health/stats',[['methods'=>'GET','callback'=>[$this,'stats'],'permission_callback'=>[Auth::class,'tier1']]]);
        register_rest_route($this->namespace,'/audit-log',[['methods'=>'GET','callback'=>[$this,'audit'],'permission_callback'=>[Auth::class,'tier1']]]);
    }
    public function health(\WP_REST_Request $r): \WP_REST_Response {
        global $wp_version,$wpdb;$ai=new Router();
        return $this->success(['status'=>'healthy','version'=>RJV_AGI_VERSION,'wordpress'=>$wp_version,'php'=>phpversion(),'mysql'=>$wpdb->db_version(),'memory'=>ini_get('memory_limit'),'ssl'=>is_ssl(),'theme'=>get_stylesheet(),'plugins'=>count(get_option('active_plugins',[])),
            'ai'=>$ai->status(),'posts'=>(int)wp_count_posts()->publish,'pages'=>(int)wp_count_posts('page')->publish,'users'=>(int)count_users()['total_users'],'comments'=>(int)wp_count_comments()->approved]);
    }
    public function stats(\WP_REST_Request $r): \WP_REST_Response {
        global $wpdb;$t=$wpdb->prefix.RJV_AGI_LOG_TABLE;$today=gmdate('Y-m-d 00:00:00');
        return $this->success(['total'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t}"),'today'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE timestamp>=%s",$today)),'errors_today'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE status='error' AND timestamp>=%s",$today)),'ai_calls_today'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE action LIKE %s AND timestamp>=%s",'ai_%',$today)),'tokens_today'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(tokens_used),0) FROM {$t} WHERE timestamp>=%s",$today))]);
    }
    public function audit(\WP_REST_Request $r): \WP_REST_Response { return $this->success(AuditLog::query(['action'=>$r['action']??'','agent_id'=>$r['agent_id']??'','tier'=>$r['tier']??'','since'=>$r['since']??'','per_page'=>$r['per_page']??50,'page'=>$r['page']??1])); }
}
