<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;
use RJV_AGI_Bridge\Auth;

class Taxonomies extends Base {
    public function register_routes(): void {
        register_rest_route($this->namespace,'/taxonomies',[['methods'=>'GET','callback'=>[$this,'list_all'],'permission_callback'=>[Auth::class,'tier1']]]);
        register_rest_route($this->namespace,'/taxonomies/(?P<tax>[a-z_]+)/terms',[['methods'=>'GET','callback'=>[$this,'terms'],'permission_callback'=>[Auth::class,'tier1']],['methods'=>'POST','callback'=>[$this,'create_term'],'permission_callback'=>[Auth::class,'tier2']]]);
        register_rest_route($this->namespace,'/taxonomies/(?P<tax>[a-z_]+)/terms/(?P<id>\d+)',[['methods'=>'PUT,PATCH','callback'=>[$this,'update_term'],'permission_callback'=>[Auth::class,'tier2']],['methods'=>'DELETE','callback'=>[$this,'delete_term'],'permission_callback'=>[Auth::class,'tier3']]]);
    }
    public function list_all(\WP_REST_Request $r): \WP_REST_Response { $res=[];foreach(get_taxonomies([],'objects') as $t) $res[]=['name'=>$t->name,'label'=>$t->label,'hierarchical'=>$t->hierarchical];return $this->success($res); }
    public function terms(\WP_REST_Request $r): \WP_REST_Response { $ts=get_terms(['taxonomy'=>sanitize_key($r['tax']),'hide_empty'=>false]);if(is_wp_error($ts))return $this->success([]);return $this->success(array_map(fn($t)=>['id'=>$t->term_id,'name'=>$t->name,'slug'=>$t->slug,'count'=>$t->count,'parent'=>$t->parent],$ts)); }
    public function create_term(\WP_REST_Request $r): \WP_REST_Response|\WP_Error { $d=$r->get_json_params();$res=wp_insert_term(sanitize_text_field($d['name']??''),sanitize_key($r['tax']),['slug'=>sanitize_title($d['slug']??''),'parent'=>(int)($d['parent']??0)]);if(is_wp_error($res))return $this->error($res->get_error_message(),500);return $this->success(['term_id'=>$res['term_id']],201); }
    public function update_term(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $tax=sanitize_key((string)$r['tax']); $id=(int)$r['id']; $d=$r->get_json_params(); $args=[];
        if(isset($d['name'])) $args['name']=sanitize_text_field((string)$d['name']);
        if(isset($d['slug'])) $args['slug']=sanitize_title((string)$d['slug']);
        if(isset($d['description'])) $args['description']=sanitize_textarea_field((string)$d['description']);
        if(isset($d['parent'])) $args['parent']=(int)$d['parent'];
        $res=wp_update_term($id,$tax,$args); if(is_wp_error($res)) return $this->error($res->get_error_message(),500);
        $this->log('update_term','taxonomy',$id,['taxonomy'=>$tax],2);
        return $this->success(['updated'=>true,'term_id'=>(int)$res['term_id']]);
    }
    public function delete_term(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $tax=sanitize_key((string)$r['tax']); $id=(int)$r['id'];
        $res=wp_delete_term($id,$tax); if(is_wp_error($res)||$res===false) return $this->error(is_wp_error($res)?$res->get_error_message():'Failed to delete term',500);
        $this->log('delete_term','taxonomy',$id,['taxonomy'=>$tax],3);
        return $this->success(['deleted'=>true,'term_id'=>$id]);
    }
}
