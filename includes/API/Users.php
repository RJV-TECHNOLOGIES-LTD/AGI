<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;
use RJV_AGI_Bridge\Auth;

class Users extends Base {
    public function register_routes(): void {
        register_rest_route($this->namespace,'/users',[['methods'=>'GET','callback'=>[$this,'list_all'],'permission_callback'=>[Auth::class,'tier1']],['methods'=>'POST','callback'=>[$this,'create'],'permission_callback'=>[Auth::class,'tier3']]]);
        register_rest_route($this->namespace,'/users/roles',[['methods'=>'GET','callback'=>[$this,'roles'],'permission_callback'=>[Auth::class,'tier1']]]);
        register_rest_route($this->namespace,'/users/(?P<id>\d+)',[['methods'=>'GET','callback'=>[$this,'get'],'permission_callback'=>[Auth::class,'tier1']],['methods'=>'PUT','callback'=>[$this,'update'],'permission_callback'=>[Auth::class,'tier3']],['methods'=>'DELETE','callback'=>[$this,'delete'],'permission_callback'=>[Auth::class,'tier3']]]);
        register_rest_route($this->namespace,'/users/(?P<id>\d+)/password',[['methods'=>'POST','callback'=>[$this,'set_password'],'permission_callback'=>[Auth::class,'tier3']]]);
        register_rest_route($this->namespace,'/users/(?P<id>\d+)/profile',[['methods'=>'PUT,PATCH','callback'=>[$this,'update_profile'],'permission_callback'=>[Auth::class,'tier3']]]);
        register_rest_route($this->namespace,'/users/(?P<id>\d+)/capability-diff',[['methods'=>'GET','callback'=>[$this,'capability_diff'],'permission_callback'=>[Auth::class,'tier1']]]);
        register_rest_route($this->namespace,'/users/(?P<id>\d+)/role-transition',[['methods'=>'POST','callback'=>[$this,'role_transition'],'permission_callback'=>[Auth::class,'tier3']]]);
    }
    public function list_all(\WP_REST_Request $r): \WP_REST_Response { return $this->success(array_map(fn($u)=>['id'=>$u->ID,'login'=>$u->user_login,'email'=>$u->user_email,'name'=>$u->display_name,'roles'=>$u->roles],get_users(['number'=>100]))); }
    public function roles(\WP_REST_Request $r): \WP_REST_Response {
        global $wp_roles; $roles=[];
        foreach(($wp_roles?->roles ?? []) as $key=>$role){$roles[]=['key'=>$key,'name'=>$role['name'],'capabilities'=>array_keys(array_filter($role['capabilities']??[]))];}
        return $this->success($roles);
    }
    public function get(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $u=get_user_by('ID',(int)$r['id']); if(!$u) return $this->error('Not found',404);
        return $this->success($this->format_user($u));
    }
    public function create(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d=$r->get_json_params(); $login=sanitize_user((string)($d['login']??'')); $email=sanitize_email((string)($d['email']??'')); $password=(string)($d['password']??'');
        if($login===''||$email===''||$password==='') return $this->error('login, email and password are required');
        $userdata=['user_login'=>$login,'user_email'=>$email,'user_pass'=>$password,'display_name'=>sanitize_text_field((string)($d['name']??$login))];
        $id=wp_insert_user($userdata); if(is_wp_error($id)) return $this->error($id->get_error_message(),500);
        if(!empty($d['role'])) { $user=new \WP_User((int)$id); $user->set_role(sanitize_key((string)$d['role'])); }
        $this->log('create_user','user',(int)$id,['login'=>$login],3);
        return $this->success(['id'=>(int)$id],201);
    }
    public function update(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id=(int)$r['id']; $d=(array)$r->get_json_params(); $u=['ID'=>$id];
        if(isset($d['name'])) $u['display_name']=sanitize_text_field((string)$d['name']);
        if(isset($d['email'])) $u['user_email']=sanitize_email((string)$d['email']);
        if(isset($d['first_name'])) $u['first_name']=sanitize_text_field((string)$d['first_name']);
        if(isset($d['last_name'])) $u['last_name']=sanitize_text_field((string)$d['last_name']);
        if(isset($d['nickname'])) $u['nickname']=sanitize_text_field((string)$d['nickname']);
        if(isset($d['url'])) $u['user_url']=esc_url_raw((string)$d['url']);
        if(isset($d['description'])) $u['description']=sanitize_textarea_field((string)$d['description']);
        $res=wp_update_user($u); if(is_wp_error($res)) return $this->error($res->get_error_message(),500);
        if(isset($d['role'])) {
            $transition = $this->apply_role_transition($id, sanitize_key((string)$d['role']), (string)($d['reason'] ?? 'profile_update'));
            if ($transition !== true) return $transition;
        }
        $this->log('update_user','user',$id,['fields'=>array_keys($d)],3);
        $user=get_user_by('ID',$id); if(!$user) return $this->error('User unavailable after update',500);
        return $this->success(['updated'=>true,'user'=>$this->format_user($user)]);
    }
    public function delete(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id=(int)$r['id']; $d=$r->get_json_params(); $reassign=isset($d['reassign'])?(int)$d['reassign']:null;
        if($this->is_last_administrator($id)) return $this->error('Cannot delete the last administrator');
        if(!function_exists('wp_delete_user')) require_once ABSPATH.'wp-admin/includes/user.php';
        $deleted=wp_delete_user($id,$reassign); if(!$deleted) return $this->error('Failed to delete user',500);
        $this->log('delete_user','user',$id,['reassign'=>$reassign],3);
        return $this->success(['deleted'=>true,'id'=>$id,'reassign'=>$reassign]);
    }
    public function set_password(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id=(int)$r['id']; $user=get_user_by('ID',$id); if(!$user) return $this->error('Not found',404);
        $d=(array)$r->get_json_params();
        $generated=false;
        $password=(string)($d['password'] ?? '');
        if($password==='') { $password=wp_generate_password(24,true,true); $generated=true; }
        wp_set_password($password,$id);
        if(!empty($d['send_reset_email'])) {
            wp_retrieve_password($user->user_login);
        }
        $this->log('set_user_password','user',$id,['generated'=>$generated,'send_reset_email'=>!empty($d['send_reset_email'])],3);
        return $this->success(['updated'=>true,'id'=>$id,'generated'=>$generated,'password'=>$generated?$password:null]);
    }
    public function update_profile(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id=(int)$r['id']; $user=get_user_by('ID',$id); if(!$user) return $this->error('Not found',404);
        $d=(array)$r->get_json_params();
        $payload=['ID'=>$id];
        foreach(['first_name','last_name','nickname','display_name'] as $field) {
            if(isset($d[$field])) $payload[$field]=sanitize_text_field((string)$d[$field]);
        }
        if(isset($d['description'])) $payload['description']=sanitize_textarea_field((string)$d['description']);
        if(isset($d['user_url'])) $payload['user_url']=esc_url_raw((string)$d['user_url']);
        if(count($payload)>1){ $res=wp_update_user($payload); if(is_wp_error($res)) return $this->error($res->get_error_message(),500); }
        foreach((array)($d['meta'] ?? []) as $key=>$value) {
            update_user_meta($id, sanitize_key((string)$key), is_scalar($value) ? sanitize_text_field((string)$value) : wp_json_encode($value));
        }
        $this->log('update_user_profile','user',$id,['fields'=>array_keys($d)],3);
        $updated=get_user_by('ID',$id); if(!$updated) return $this->error('Not found',404);
        return $this->success(['updated'=>true,'user'=>$this->format_user($updated)]);
    }
    public function capability_diff(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id=(int)$r['id']; $user=get_user_by('ID',$id); if(!$user) return $this->error('Not found',404);
        $role_caps=[]; global $wp_roles;
        foreach((array)$user->roles as $role){ $role_caps=array_merge($role_caps, array_keys(array_filter((array)($wp_roles?->roles[$role]['capabilities'] ?? [])))); }
        $role_caps=array_values(array_unique($role_caps));
        $effective=array_keys(array_filter((array)$user->allcaps));
        $direct_caps=(array)$user->caps;
        $direct_grants=[]; $direct_denies=[];
        foreach($direct_caps as $cap=>$granted){ if($granted){$direct_grants[]=(string)$cap;} else {$direct_denies[]=(string)$cap;} }
        return $this->success([
            'id'=>$id,
            'roles'=>array_values((array)$user->roles),
            'role_caps'=>$role_caps,
            'effective_caps'=>array_values($effective),
            'direct_grants'=>array_values(array_unique($direct_grants)),
            'direct_denies'=>array_values(array_unique($direct_denies)),
            'effective_extra'=>array_values(array_diff($effective,$role_caps)),
            'role_missing_in_effective'=>array_values(array_diff($role_caps,$effective)),
        ]);
    }
    public function role_transition(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id=(int)$r['id']; $user=get_user_by('ID',$id); if(!$user) return $this->error('Not found',404);
        $d=(array)$r->get_json_params(); $target=sanitize_key((string)($d['role'] ?? ''));
        if($target==='') return $this->error('role required');
        $result = $this->apply_role_transition($id, $target, (string)($d['reason'] ?? 'role_transition'));
        if ($result !== true) return $result;
        $updated=get_user_by('ID',$id); if(!$updated) return $this->error('Not found',404);
        return $this->success(['updated'=>true,'user'=>$this->format_user($updated)]);
    }
    /**
     * Applies a role transition safely.
     * Returns true on success or WP_Error on failure.
     */
    private function apply_role_transition(int $user_id, string $target_role, string $reason): true|\WP_Error {
        global $wp_roles;
        if(!isset(($wp_roles?->roles ?? [])[$target_role])) return $this->error('Invalid role');
        $user=new \WP_User($user_id); if(!$user->exists()) return $this->error('Not found',404);
        $current=(array)$user->roles;
        if(in_array('administrator',$current,true) && $target_role!=='administrator' && $this->is_last_administrator($user_id)) {
            return $this->error('Cannot demote the last administrator');
        }
        if(count($current)===1 && $current[0]===$target_role) return true;
        $user->set_role($target_role);
        $this->log('transition_user_role','user',$user_id,['from'=>$current,'to'=>$target_role,'reason'=>sanitize_text_field($reason)],3);
        return true;
    }
    private function is_last_administrator(int $user_id): bool {
        $user=get_user_by('ID',$user_id); if(!$user) return false;
        if(!in_array('administrator',(array)$user->roles,true)) return false;
        $admins=get_users(['role'=>'administrator','fields'=>'ID','number'=>2]);
        return count((array)$admins) <= 1;
    }
    private function format_user(\WP_User $u): array {
        return [
            'id'=>$u->ID,
            'login'=>$u->user_login,
            'email'=>$u->user_email,
            'name'=>$u->display_name,
            'roles'=>$u->roles,
            'registered'=>$u->user_registered,
            'first_name'=>get_user_meta($u->ID,'first_name',true),
            'last_name'=>get_user_meta($u->ID,'last_name',true),
            'nickname'=>get_user_meta($u->ID,'nickname',true),
            'description'=>get_user_meta($u->ID,'description',true),
            'url'=>$u->user_url,
        ];
    }
}
