<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;
use RJV_AGI_Bridge\Auth;

class Database extends Base {
    public function register_routes(): void {
        register_rest_route($this->namespace,'/database/tables',[['methods'=>'GET','callback'=>[$this,'tables'],'permission_callback'=>[Auth::class,'tier1']]]);
        register_rest_route($this->namespace,'/database/query',[['methods'=>'POST','callback'=>[$this,'query'],'permission_callback'=>[Auth::class,'tier3']]]);
        register_rest_route($this->namespace,'/database/optimize',[['methods'=>'POST','callback'=>[$this,'optimize'],'permission_callback'=>[Auth::class,'tier2']]]);
    }
    public function tables(\WP_REST_Request $r): \WP_REST_Response { global $wpdb;return $this->success(array_map(fn($t)=>['name'=>$t['Name'],'rows'=>(int)$t['Rows'],'size_mb'=>round(((int)$t['Data_length']+(int)$t['Index_length'])/1048576,2)],$wpdb->get_results("SHOW TABLE STATUS",ARRAY_A))); }
    public function query(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $sql=$r->get_json_params()['sql']??'';$up=strtoupper(trim($sql));
        if(!str_starts_with($up,'SELECT'))return $this->error('Only SELECT allowed',403);
        foreach(['DROP','DELETE','UPDATE','INSERT','ALTER','TRUNCATE','GRANT','CREATE'] as $kw) if(str_contains($up,$kw))return $this->error("Blocked: {$kw}",403);
        global $wpdb;$this->log('db_query','database',0,['len'=>strlen($sql)],3);return $this->success($wpdb->get_results($sql,ARRAY_A));
    }
    public function optimize(\WP_REST_Request $r): \WP_REST_Response {
        global $wpdb;$tables=$wpdb->get_col("SHOW TABLES");foreach($tables as $t)$wpdb->query("OPTIMIZE TABLE `{$t}`");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value<UNIX_TIMESTAMP()");
        $this->log('optimize','database',0,['tables'=>count($tables)],2);return $this->success(['tables'=>count($tables)]);
    }
}
