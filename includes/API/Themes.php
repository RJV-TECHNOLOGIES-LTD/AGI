<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;
use RJV_AGI_Bridge\Auth;

class Themes extends Base {
    public function register_routes(): void {
        register_rest_route($this->namespace,'/themes',[['methods'=>'GET','callback'=>[$this,'list_all'],'permission_callback'=>[Auth::class,'tier1']]]);
        register_rest_route($this->namespace,'/themes/activate',[['methods'=>'POST','callback'=>[$this,'activate'],'permission_callback'=>[Auth::class,'tier3']]]);
        register_rest_route($this->namespace,'/themes/customizer',[['methods'=>'GET','callback'=>[$this,'get_mods'],'permission_callback'=>[Auth::class,'tier1']],['methods'=>'PUT','callback'=>[$this,'set_mods'],'permission_callback'=>[Auth::class,'tier2']]]);
    }
    public function list_all(\WP_REST_Request $r): \WP_REST_Response { $active=get_stylesheet();return $this->success(array_map(fn($s,$t)=>['slug'=>$s,'name'=>$t->get('Name'),'version'=>$t->get('Version'),'active'=>$s===$active],array_keys(wp_get_themes()),wp_get_themes())); }
    public function activate(\WP_REST_Request $r): \WP_REST_Response|\WP_Error { $s=sanitize_text_field($r->get_json_params()['slug']??'');$t=wp_get_theme($s);if(!$t->exists())return $this->error('Not found',404);switch_theme($s);$this->log('activate_theme','theme',0,['slug'=>$s],3);return $this->success(['activated'=>$s]); }
    public function get_mods(\WP_REST_Request $r): \WP_REST_Response { return $this->success(get_theme_mods()); }
    public function set_mods(\WP_REST_Request $r): \WP_REST_Response { foreach($r->get_json_params() as $k=>$v) set_theme_mod(sanitize_key($k),$v);$this->log('set_theme_mods','theme',0,[],2);return $this->success(['updated'=>true]); }
}
