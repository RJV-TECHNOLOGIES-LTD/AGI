<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;
use RJV_AGI_Bridge\Auth;

class Comments extends Base {
    public function register_routes(): void {
        register_rest_route($this->namespace,'/comments',[['methods'=>'GET','callback'=>[$this,'list_all'],'permission_callback'=>[Auth::class,'tier1']]]);
        register_rest_route($this->namespace,'/comments/(?P<id>\d+)/approve',[['methods'=>'POST','callback'=>[$this,'approve'],'permission_callback'=>[Auth::class,'tier2']]]);
        register_rest_route($this->namespace,'/comments/(?P<id>\d+)/spam',[['methods'=>'POST','callback'=>[$this,'spam'],'permission_callback'=>[Auth::class,'tier2']]]);
        register_rest_route($this->namespace,'/comments/(?P<id>\d+)/status',[['methods'=>'POST','callback'=>[$this,'set_status'],'permission_callback'=>[Auth::class,'tier2']]]);
        register_rest_route($this->namespace,'/comments/bulk',[['methods'=>'POST','callback'=>[$this,'bulk'],'permission_callback'=>[Auth::class,'tier2']]]);
        register_rest_route($this->namespace,'/comments/(?P<id>\d+)',[['methods'=>'DELETE','callback'=>[$this,'delete'],'permission_callback'=>[Auth::class,'tier3']]]);
    }
    public function list_all(\WP_REST_Request $r): \WP_REST_Response { return $this->success(array_map(fn($c)=>['id'=>(int)$c->comment_ID,'post'=>(int)$c->comment_post_ID,'author'=>$c->comment_author,'content'=>$c->comment_content,'date'=>$c->comment_date,'status'=>$c->comment_approved],get_comments(['number'=>50,'status'=>'all','orderby'=>'comment_date_gmt','order'=>'DESC']))); }
    public function approve(\WP_REST_Request $r): \WP_REST_Response { wp_set_comment_status((int)$r['id'],'approve');$this->log('approve','comment',(int)$r['id'],[],2);return $this->success(['approved'=>true]); }
    public function spam(\WP_REST_Request $r): \WP_REST_Response { wp_spam_comment((int)$r['id']);return $this->success(['spammed'=>true]); }
    public function set_status(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id=(int)$r['id']; $status=sanitize_key((string)($r->get_json_params()['status']??''));
        $allowed=['approve','hold','spam','trash']; if(!in_array($status,$allowed,true)) return $this->error('Invalid status');
        $res=wp_set_comment_status($id,$status); if(!$res) return $this->error('Failed to set status',500);
        $this->log('set_comment_status','comment',$id,['status'=>$status],2);
        return $this->success(['updated'=>true,'id'=>$id,'status'=>$status]);
    }
    public function bulk(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d=$r->get_json_params(); $ids=array_map('absint',(array)($d['ids']??[])); $action=sanitize_key((string)($d['action']??''));
        if(empty($ids)||$action==='') return $this->error('action and ids required');
        $results=[]; foreach($ids as $id){
            $ok=false;
            if(in_array($action,['approve','hold','spam','trash'],true)) $ok=(bool)wp_set_comment_status($id,$action);
            if($action==='delete') $ok=(bool)wp_delete_comment($id,true);
            $results[]=['id'=>$id,'done'=>$ok];
        }
        $this->log('bulk_comments','comment',0,['action'=>$action,'count'=>count($ids)],$action==='delete'?3:2);
        return $this->success($results);
    }
    public function delete(\WP_REST_Request $r): \WP_REST_Response { wp_delete_comment((int)$r['id'],true);$this->log('delete','comment',(int)$r['id'],[],3);return $this->success(['deleted'=>true]); }
}
