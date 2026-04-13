<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;
use RJV_AGI_Bridge\Auth;

class Plugins extends Base {
    public function register_routes(): void {
        register_rest_route($this->namespace,'/plugins',[['methods'=>'GET','callback'=>[$this,'list_all'],'permission_callback'=>[Auth::class,'tier1']]]);
        register_rest_route($this->namespace,'/plugins/toggle',[['methods'=>'POST','callback'=>[$this,'toggle'],'permission_callback'=>[Auth::class,'tier3']]]);
    }
    public function list_all(\WP_REST_Request $r): \WP_REST_Response {
        if(!function_exists('get_plugins'))require_once ABSPATH.'wp-admin/includes/plugin.php';$all=get_plugins();$active=get_option('active_plugins',[]);
        return $this->success(array_map(fn($f,$d)=>['file'=>$f,'name'=>$d['Name'],'version'=>$d['Version'],'active'=>in_array($f,$active),'author'=>$d['Author']],array_keys($all),$all));
    }
    public function toggle(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d=$r->get_json_params();$f=sanitize_text_field($d['plugin']??'');$a=$d['action']??'';
        if(!in_array($a,['activate','deactivate']))return $this->error('action: activate or deactivate');
        if(!function_exists('activate_plugin'))require_once ABSPATH.'wp-admin/includes/plugin.php';
        if($a==='activate'){$res=activate_plugin($f);if(is_wp_error($res))return $this->error($res->get_error_message(),500);}else deactivate_plugins($f);
        $this->log('toggle_plugin','plugin',0,['plugin'=>$f,'action'=>$a],3);return $this->success(['plugin'=>$f,'action'=>$a]);
    }
}
