<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;
use RJV_AGI_Bridge\Auth;

class Users extends Base {
    public function register_routes(): void {
        register_rest_route($this->namespace,'/users',[['methods'=>'GET','callback'=>[$this,'list_all'],'permission_callback'=>[Auth::class,'tier1']],['methods'=>'POST','callback'=>[$this,'create'],'permission_callback'=>[Auth::class,'tier3']]]);
        register_rest_route($this->namespace,'/users/roles',[['methods'=>'GET','callback'=>[$this,'roles'],'permission_callback'=>[Auth::class,'tier1']]]);
        register_rest_route($this->namespace,'/users/(?P<id>\d+)',[['methods'=>'GET','callback'=>[$this,'get'],'permission_callback'=>[Auth::class,'tier1']],['methods'=>'PUT','callback'=>[$this,'update'],'permission_callback'=>[Auth::class,'tier3']],['methods'=>'DELETE','callback'=>[$this,'delete'],'permission_callback'=>[Auth::class,'tier3']]]);
    }
    public function list_all(\WP_REST_Request $r): \WP_REST_Response { return $this->success(array_map(fn($u)=>['id'=>$u->ID,'login'=>$u->user_login,'email'=>$u->user_email,'name'=>$u->display_name,'roles'=>$u->roles],get_users(['number'=>100]))); }
    public function roles(\WP_REST_Request $r): \WP_REST_Response {
        global $wp_roles; $roles=[];
        foreach(($wp_roles?->roles ?? []) as $key=>$role){$roles[]=['key'=>$key,'name'=>$role['name'],'capabilities'=>array_keys(array_filter($role['capabilities']??[]))];}
        return $this->success($roles);
    }
    public function get(\WP_REST_Request $r): \WP_REST_Response|\WP_Error { $u=get_user_by('ID',(int)$r['id']);if(!$u)return $this->error('Not found',404);return $this->success(['id'=>$u->ID,'login'=>$u->user_login,'email'=>$u->user_email,'name'=>$u->display_name,'roles'=>$u->roles,'registered'=>$u->user_registered]); }
    public function create(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d=$r->get_json_params(); $login=sanitize_user((string)($d['login']??'')); $email=sanitize_email((string)($d['email']??'')); $password=(string)($d['password']??'');
        if($login===''||$email===''||$password==='') return $this->error('login, email and password are required');
        $userdata=['user_login'=>$login,'user_email'=>$email,'user_pass'=>$password,'display_name'=>sanitize_text_field((string)($d['name']??$login))];
        $id=wp_insert_user($userdata); if(is_wp_error($id)) return $this->error($id->get_error_message(),500);
        if(!empty($d['role'])) { $user=new \WP_User((int)$id); $user->set_role(sanitize_key((string)$d['role'])); }
        $this->log('create_user','user',(int)$id,['login'=>$login],3);
        return $this->success(['id'=>(int)$id],201);
    }
    public function update(\WP_REST_Request $r): \WP_REST_Response|\WP_Error { $d=$r->get_json_params();$u=['ID'=>(int)$r['id']];if(isset($d['name']))$u['display_name']=sanitize_text_field($d['name']);if(isset($d['email']))$u['user_email']=sanitize_email($d['email']);$res=wp_update_user($u);if(is_wp_error($res))return $this->error($res->get_error_message(),500);$this->log('update_user','user',(int)$r['id'],[],3);return $this->success(['updated'=>true]); }
    public function delete(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id=(int)$r['id']; $d=$r->get_json_params(); $reassign=isset($d['reassign'])?(int)$d['reassign']:null;
        if(!function_exists('wp_delete_user')) require_once ABSPATH.'wp-admin/includes/user.php';
        $deleted=wp_delete_user($id,$reassign); if(!$deleted) return $this->error('Failed to delete user',500);
        $this->log('delete_user','user',$id,['reassign'=>$reassign],3);
        return $this->success(['deleted'=>true,'id'=>$id,'reassign'=>$reassign]);
    }
}
