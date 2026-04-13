<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;
use RJV_AGI_Bridge\Auth;

class Users extends Base {
    public function register_routes(): void {
        register_rest_route($this->namespace,'/users',[['methods'=>'GET','callback'=>[$this,'list_all'],'permission_callback'=>[Auth::class,'tier1']]]);
        register_rest_route($this->namespace,'/users/(?P<id>\d+)',[['methods'=>'GET','callback'=>[$this,'get'],'permission_callback'=>[Auth::class,'tier1']],['methods'=>'PUT','callback'=>[$this,'update'],'permission_callback'=>[Auth::class,'tier3']]]);
    }
    public function list_all(\WP_REST_Request $r): \WP_REST_Response { return $this->success(array_map(fn($u)=>['id'=>$u->ID,'login'=>$u->user_login,'email'=>$u->user_email,'name'=>$u->display_name,'roles'=>$u->roles],get_users(['number'=>100]))); }
    public function get(\WP_REST_Request $r): \WP_REST_Response|\WP_Error { $u=get_user_by('ID',(int)$r['id']);if(!$u)return $this->error('Not found',404);return $this->success(['id'=>$u->ID,'login'=>$u->user_login,'email'=>$u->user_email,'name'=>$u->display_name,'roles'=>$u->roles,'registered'=>$u->user_registered]); }
    public function update(\WP_REST_Request $r): \WP_REST_Response|\WP_Error { $d=$r->get_json_params();$u=['ID'=>(int)$r['id']];if(isset($d['name']))$u['display_name']=sanitize_text_field($d['name']);if(isset($d['email']))$u['user_email']=sanitize_email($d['email']);$res=wp_update_user($u);if(is_wp_error($res))return $this->error($res->get_error_message(),500);$this->log('update_user','user',(int)$r['id'],[],3);return $this->success(['updated'=>true]); }
}
