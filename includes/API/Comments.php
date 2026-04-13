<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;
use RJV_AGI_Bridge\Auth;

class Comments extends Base {
    public function register_routes(): void {
        register_rest_route($this->namespace,'/comments',[['methods'=>'GET','callback'=>[$this,'list_all'],'permission_callback'=>[Auth::class,'tier1']]]);
        register_rest_route($this->namespace,'/comments/(?P<id>\d+)/approve',[['methods'=>'POST','callback'=>[$this,'approve'],'permission_callback'=>[Auth::class,'tier2']]]);
        register_rest_route($this->namespace,'/comments/(?P<id>\d+)/spam',[['methods'=>'POST','callback'=>[$this,'spam'],'permission_callback'=>[Auth::class,'tier2']]]);
        register_rest_route($this->namespace,'/comments/(?P<id>\d+)',[['methods'=>'DELETE','callback'=>[$this,'delete'],'permission_callback'=>[Auth::class,'tier3']]]);
    }
    public function list_all(\WP_REST_Request $r): \WP_REST_Response { return $this->success(array_map(fn($c)=>['id'=>(int)$c->comment_ID,'post'=>(int)$c->comment_post_ID,'author'=>$c->comment_author,'content'=>$c->comment_content,'date'=>$c->comment_date,'status'=>$c->comment_approved],get_comments(['number'=>50,'status'=>'all','orderby'=>'comment_date_gmt','order'=>'DESC']))); }
    public function approve(\WP_REST_Request $r): \WP_REST_Response { wp_set_comment_status((int)$r['id'],'approve');$this->log('approve','comment',(int)$r['id'],[],2);return $this->success(['approved'=>true]); }
    public function spam(\WP_REST_Request $r): \WP_REST_Response { wp_spam_comment((int)$r['id']);return $this->success(['spammed'=>true]); }
    public function delete(\WP_REST_Request $r): \WP_REST_Response { wp_delete_comment((int)$r['id'],true);$this->log('delete','comment',(int)$r['id'],[],3);return $this->success(['deleted'=>true]); }
}
