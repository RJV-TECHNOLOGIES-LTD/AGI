<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;
use RJV_AGI_Bridge\Auth;

class Options extends Base {
    private array $rw=['blogname','blogdescription','timezone_string','date_format','time_format','posts_per_page','page_on_front','page_for_posts','show_on_front','blog_public'];
    private array $ro=['siteurl','home','admin_email','permalink_structure','template','stylesheet','WPLANG'];
    public function register_routes(): void {
        register_rest_route($this->namespace,'/options',[['methods'=>'GET','callback'=>[$this,'get_all'],'permission_callback'=>[Auth::class,'tier1']],['methods'=>'PUT','callback'=>[$this,'update'],'permission_callback'=>[Auth::class,'tier3']]]);
    }
    public function get_all(\WP_REST_Request $r): \WP_REST_Response { $res=[];foreach(array_merge($this->rw,$this->ro) as $k) $res[$k]=get_option($k);return $this->success($res); }
    public function update(\WP_REST_Request $r): \WP_REST_Response { $d=$r->get_json_params();$up=[];foreach($d as $k=>$v){$k=sanitize_key($k);if(in_array($k,$this->rw)){update_option($k,sanitize_text_field((string)$v));$up[]=$k;}}$this->log('update_options','option',0,['keys'=>$up],3);return $this->success(['updated'=>$up]); }
}
