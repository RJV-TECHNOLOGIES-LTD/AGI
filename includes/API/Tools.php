<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;
use RJV_AGI_Bridge\Auth;

class Tools extends Base {
    public function register_routes(): void {
        register_rest_route($this->namespace,'/tools/privacy/requests',[['methods'=>'GET','callback'=>[$this,'list_privacy_requests'],'permission_callback'=>[Auth::class,'tier1']],['methods'=>'POST','callback'=>[$this,'create_privacy_request'],'permission_callback'=>[Auth::class,'tier3']]]);
        register_rest_route($this->namespace,'/tools/privacy/requests/(?P<id>\d+)',[['methods'=>'DELETE','callback'=>[$this,'delete_privacy_request'],'permission_callback'=>[Auth::class,'tier3']]]);
    }
    public function list_privacy_requests(\WP_REST_Request $r): \WP_REST_Response {
        $type=sanitize_key((string)($r->get_param('type')?:''));
        $status=sanitize_key((string)($r->get_param('status')?:''));
        $args=['posts_per_page'=>100,'post_type'=>'user_request','post_status'=>'any','orderby'=>'ID','order'=>'DESC'];
        if($type!=='') $args['meta_query'][]=['key'=>'_wp_user_request_action_name','value'=>$type];
        if($status!=='') $args['post_status']=$status;
        $q=new \WP_Query($args);
        $items=array_map(function(\WP_Post $p): array {
            return ['id'=>$p->ID,'email'=>(string)get_post_meta($p->ID,'_wp_user_request_confirmed_email',true),'action'=>(string)get_post_meta($p->ID,'_wp_user_request_action_name',true),'status'=>$p->post_status,'confirmed'=>(bool)get_post_meta($p->ID,'_wp_user_request_confirmed_timestamp',true),'created'=>$p->post_date_gmt];
        },$q->posts);
        return $this->success(['requests'=>$items,'total'=>(int)$q->found_posts]);
    }
    public function create_privacy_request(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d=$r->get_json_params(); $email=sanitize_email((string)($d['email']??'')); $action=sanitize_key((string)($d['action']??''));
        if($email==='') return $this->error('email required');
        if(!in_array($action,['export_personal_data','remove_personal_data'],true)) return $this->error('action must be export_personal_data or remove_personal_data');
        if(!function_exists('wp_create_user_request')) require_once ABSPATH.'wp-admin/includes/user.php';
        $request_id=wp_create_user_request($email,$action); if(is_wp_error($request_id)) return $this->error($request_id->get_error_message(),500);
        if(!empty($d['send_confirmation'])) wp_send_user_request((int)$request_id);
        $this->log('create_privacy_request','tool',(int)$request_id,['action'=>$action,'email'=>$email],3);
        return $this->success(['id'=>(int)$request_id,'email'=>$email,'action'=>$action],201);
    }
    public function delete_privacy_request(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id=(int)$r['id']; $deleted=wp_delete_post($id,true); if(!$deleted) return $this->error('Failed to delete privacy request',500);
        $this->log('delete_privacy_request','tool',$id,[],3);
        return $this->success(['deleted'=>true,'id'=>$id]);
    }
}
