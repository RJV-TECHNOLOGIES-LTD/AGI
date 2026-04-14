<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;
use RJV_AGI_Bridge\Auth;

class Plugins extends Base {
    public function register_routes(): void {
        register_rest_route($this->namespace,'/plugins',[['methods'=>'GET','callback'=>[$this,'list_all'],'permission_callback'=>[Auth::class,'tier1']]]);
        register_rest_route($this->namespace,'/plugins/toggle',[['methods'=>'POST','callback'=>[$this,'toggle'],'permission_callback'=>[Auth::class,'tier3']]]);
        register_rest_route($this->namespace,'/plugins/install',[['methods'=>'POST','callback'=>[$this,'install'],'permission_callback'=>[Auth::class,'tier3']]]);
        register_rest_route($this->namespace,'/plugins/update',[['methods'=>'POST','callback'=>[$this,'update_plugin'],'permission_callback'=>[Auth::class,'tier3']]]);
        register_rest_route($this->namespace,'/plugins/delete',[['methods'=>'POST','callback'=>[$this,'delete_plugin'],'permission_callback'=>[Auth::class,'tier3']]]);
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
    public function install(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d=$r->get_json_params(); $slug=sanitize_key((string)($d['slug']??''));
        if($slug==='') return $this->error('slug required');
        require_once ABSPATH.'wp-admin/includes/plugin-install.php';
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        $api=plugins_api('plugin_information',['slug'=>$slug,'fields'=>['sections'=>false]]);
        if(is_wp_error($api)) return $this->error($api->get_error_message(),500);
        $upgrader=new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
        $result=$upgrader->install((string)$api->download_link);
        if(!$result||is_wp_error($result)) return $this->error(is_wp_error($result)?$result->get_error_message():'Failed to install plugin',500);
        $this->log('install_plugin','plugin',0,['slug'=>$slug],3);
        return $this->success(['installed'=>true,'slug'=>$slug],201);
    }
    public function update_plugin(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d=$r->get_json_params(); $plugin=sanitize_text_field((string)($d['plugin']??''));
        if($plugin==='') return $this->error('plugin required');
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        $upgrader=new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
        $result=$upgrader->upgrade($plugin);
        if(!$result||is_wp_error($result)) return $this->error(is_wp_error($result)?$result->get_error_message():'Failed to update plugin',500);
        $this->log('update_plugin','plugin',0,['plugin'=>$plugin],3);
        return $this->success(['updated'=>true,'plugin'=>$plugin]);
    }
    public function delete_plugin(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d=$r->get_json_params(); $plugin=sanitize_text_field((string)($d['plugin']??''));
        if($plugin==='') return $this->error('plugin required');
        if(!function_exists('delete_plugins')) require_once ABSPATH.'wp-admin/includes/plugin.php';
        require_once ABSPATH.'wp-admin/includes/file.php';
        $res=delete_plugins([$plugin]);
        if(is_wp_error($res)) return $this->error($res->get_error_message(),500);
        $this->log('delete_plugin','plugin',0,['plugin'=>$plugin],3);
        return $this->success(['deleted'=>true,'plugin'=>$plugin]);
    }
}
