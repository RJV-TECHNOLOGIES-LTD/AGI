<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;
use RJV_AGI_Bridge\Auth;

class Menus extends Base {
    public function register_routes(): void {
        register_rest_route($this->namespace,'/menus',[['methods'=>'GET','callback'=>[$this,'list_all'],'permission_callback'=>[Auth::class,'tier1']]]);
        register_rest_route($this->namespace,'/menus/(?P<id>\d+)',[['methods'=>'GET','callback'=>[$this,'get'],'permission_callback'=>[Auth::class,'tier1']]]);
        register_rest_route($this->namespace,'/menus/(?P<id>\d+)/items',[['methods'=>'POST','callback'=>[$this,'add_item'],'permission_callback'=>[Auth::class,'tier2']]]);
    }
    public function list_all(\WP_REST_Request $r): \WP_REST_Response { return $this->success(array_map(fn($m)=>['id'=>$m->term_id,'name'=>$m->name,'slug'=>$m->slug,'count'=>$m->count],wp_get_nav_menus())); }
    public function get(\WP_REST_Request $r): \WP_REST_Response|\WP_Error { $items=wp_get_nav_menu_items((int)$r['id']);if($items===false)return $this->error('Not found',404);return $this->success(array_map(fn($i)=>['id'=>$i->ID,'title'=>$i->title,'url'=>$i->url,'type'=>$i->type,'parent'=>(int)$i->menu_item_parent,'order'=>$i->menu_order],$items)); }
    public function add_item(\WP_REST_Request $r): \WP_REST_Response|\WP_Error { $d=$r->get_json_params();$id=wp_update_nav_menu_item((int)$r['id'],0,['menu-item-title'=>sanitize_text_field($d['title']??''),'menu-item-url'=>esc_url_raw($d['url']??''),'menu-item-status'=>'publish','menu-item-type'=>'custom']);if(is_wp_error($id))return $this->error($id->get_error_message(),500);$this->log('add_menu_item','menu',(int)$r['id'],[],2);return $this->success(['id'=>$id],201); }
}
