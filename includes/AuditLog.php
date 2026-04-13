<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge;

class AuditLog {
    public static function log(string $action, string $res_type='', int $res_id=0, array $details=[], int $tier=1, string $status='success', ?int $ms=null, ?int $tokens=null, ?string $model=null): void {
        if (get_option('rjv_agi_audit_enabled','1') !== '1') return;
        global $wpdb;
        $wpdb->insert($wpdb->prefix . RJV_AGI_LOG_TABLE, [
            'timestamp'=>current_time('mysql',true), 'agent_id'=>$details['agent_id']??'system',
            'action'=>sanitize_text_field($action), 'resource_type'=>sanitize_text_field($res_type),
            'resource_id'=>$res_id, 'details'=>wp_json_encode($details),
            'ip_address'=>(($ip=sanitize_text_field($_SERVER['REMOTE_ADDR']??''))&&filter_var($ip,FILTER_VALIDATE_IP))?$ip:'0.0.0.0', 'tier'=>$tier, 'status'=>$status,
            'execution_time_ms'=>$ms, 'tokens_used'=>$tokens, 'model_used'=>$model,
        ]);
    }
    public static function query(array $a=[]): array {
        global $wpdb; $t=$wpdb->prefix.RJV_AGI_LOG_TABLE;
        $w=['1=1']; $p=[];
        if(!empty($a['action'])){$w[]='action=%s';$p[]=$a['action'];}
        if(!empty($a['agent_id'])){$w[]='agent_id=%s';$p[]=$a['agent_id'];}
        if(!empty($a['tier'])){$w[]='tier=%d';$p[]=(int)$a['tier'];}
        if(!empty($a['since'])){$w[]='timestamp>=%s';$p[]=$a['since'];}
        $lim=min((int)($a['per_page']??50),200); $off=max(0,((int)($a['page']??1)-1)*$lim);
        $ws=implode(' AND ',$w); $sql="SELECT * FROM {$t} WHERE {$ws} ORDER BY id DESC LIMIT %d OFFSET %d";
        $p[]=$lim; $p[]=$off;
        return $wpdb->get_results($wpdb->prepare($sql,...$p), ARRAY_A)?:[];
    }
}
