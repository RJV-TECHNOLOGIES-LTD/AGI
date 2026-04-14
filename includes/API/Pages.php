<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;
use RJV_AGI_Bridge\Auth;

class Pages extends Base {
    public function register_routes(): void {
        register_rest_route($this->namespace,'/pages',[['methods'=>'GET','callback'=>[$this,'list_all'],'permission_callback'=>[Auth::class,'tier1']],['methods'=>'POST','callback'=>[$this,'create'],'permission_callback'=>[Auth::class,'tier2']]]);
        register_rest_route($this->namespace,'/pages/(?P<id>\d+)',[['methods'=>'GET','callback'=>[$this,'get'],'permission_callback'=>[Auth::class,'tier1']],['methods'=>'PUT,PATCH','callback'=>[$this,'update'],'permission_callback'=>[Auth::class,'tier2']],['methods'=>'DELETE','callback'=>[$this,'delete'],'permission_callback'=>[Auth::class,'tier3']]]);
        register_rest_route($this->namespace,'/pages/(?P<id>\d+)/revisions',[['methods'=>'GET','callback'=>[$this,'revisions'],'permission_callback'=>[Auth::class,'tier1']]]);
        register_rest_route($this->namespace,'/pages/(?P<id>\d+)/revisions/(?P<revision_id>\d+)/restore',[['methods'=>'POST','callback'=>[$this,'restore_revision'],'permission_callback'=>[Auth::class,'tier3']]]);
    }
    public function list_all(\WP_REST_Request $r): \WP_REST_Response {
        $pages=get_pages(['sort_column'=>'post_title','post_status'=>'any']);
        return $this->success(array_map(fn($p)=>['id'=>$p->ID,'title'=>$p->post_title,'slug'=>$p->post_name,'status'=>$p->post_status,'parent'=>$p->post_parent,'template'=>get_page_template_slug($p->ID)?:'default','permalink'=>get_permalink($p->ID)],$pages?:[]));
    }
    public function get(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $p=get_post((int)$r['id']); if(!$p||$p->post_type!=='page') return $this->error('Not found',404);
        return $this->success(['id'=>$p->ID,'title'=>$p->post_title,'content'=>$p->post_content,'slug'=>$p->post_name,'status'=>$p->post_status,'template'=>get_page_template_slug($p->ID),'meta'=>get_post_meta($p->ID)]);
    }
    public function create(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d=$r->get_json_params();$id=wp_insert_post(['post_type'=>'page','post_title'=>sanitize_text_field($d['title']??''),'post_content'=>wp_kses_post($d['content']??''),'post_status'=>sanitize_text_field($d['status']??'draft'),'post_parent'=>(int)($d['parent']??0)],true);
        if(is_wp_error($id)) return $this->error($id->get_error_message(),500);
        if(!empty($d['template'])) update_post_meta($id,'_wp_page_template',sanitize_text_field($d['template']));
        $this->log('create_page','page',$id,[],2); return $this->success(['id'=>$id],201);
    }
    public function update(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id=(int)$r['id'];$d=$r->get_json_params();$u=['ID'=>$id];
        if(isset($d['title']))$u['post_title']=sanitize_text_field($d['title']);if(isset($d['content']))$u['post_content']=wp_kses_post($d['content']);if(isset($d['status']))$u['post_status']=sanitize_text_field($d['status']);
        $res=wp_update_post($u,true);if(is_wp_error($res))return $this->error($res->get_error_message(),500);
        if(!empty($d['template']))update_post_meta($id,'_wp_page_template',sanitize_text_field($d['template']));
        $this->log('update_page','page',$id,[],2);return $this->success(['updated'=>true]);
    }
    public function delete(\WP_REST_Request $r): \WP_REST_Response {
        $d=$r->get_json_params(); $id=(int)$r['id']; $force=!empty($d['force']);
        wp_delete_post($id,$force); $this->log('delete_page','page',$id,['force'=>$force],3); return $this->success(['deleted'=>true,'force'=>$force]);
    }
    public function revisions(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id=(int)$r['id']; $p=get_post($id); if(!$p||$p->post_type!=='page') return $this->error('Not found',404);
        $revs=wp_get_post_revisions($id,['check_enabled'=>false]);
        $items=array_map(function(\WP_Post $rev): array {
            return ['id'=>$rev->ID,'parent'=>(int)$rev->post_parent,'author'=>(int)$rev->post_author,'date'=>$rev->post_date_gmt,'modified'=>$rev->post_modified_gmt,'title'=>$rev->post_title];
        }, array_values($revs));
        return $this->success(['page_id'=>$id,'revisions'=>$items]);
    }
    public function restore_revision(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id=(int)$r['id']; $revision_id=(int)$r['revision_id'];
        $post=get_post($id); $revision=get_post($revision_id);
        if(!$post||$post->post_type!=='page') return $this->error('Not found',404);
        if(!$revision||$revision->post_parent!==$id||$revision->post_type!=='revision') return $this->error('Revision not found',404);
        $restored=wp_restore_post_revision($revision_id);
        if(!$restored) return $this->error('Failed to restore revision',500);
        $this->log('restore_page_revision','page',$id,['revision_id'=>$revision_id],3);
        return $this->success(['restored'=>true,'page_id'=>$id,'revision_id'=>$revision_id]);
    }
}
