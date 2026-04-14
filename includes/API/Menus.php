<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;
use RJV_AGI_Bridge\Auth;

class Menus extends Base {
    public function register_routes(): void {
        register_rest_route($this->namespace,'/menus',[['methods'=>'GET','callback'=>[$this,'list_all'],'permission_callback'=>[Auth::class,'tier1']],['methods'=>'POST','callback'=>[$this,'create'],'permission_callback'=>[Auth::class,'tier2']]]);
        register_rest_route($this->namespace,'/menus/(?P<id>\d+)',[['methods'=>'GET','callback'=>[$this,'get'],'permission_callback'=>[Auth::class,'tier1']],['methods'=>'PUT,PATCH','callback'=>[$this,'update'],'permission_callback'=>[Auth::class,'tier2']],['methods'=>'DELETE','callback'=>[$this,'delete'],'permission_callback'=>[Auth::class,'tier3']]]);
        register_rest_route($this->namespace,'/menus/(?P<id>\d+)/items',[['methods'=>'POST','callback'=>[$this,'add_item'],'permission_callback'=>[Auth::class,'tier2']]]);
        register_rest_route($this->namespace,'/menus/(?P<id>\d+)/items/(?P<item_id>\d+)',[['methods'=>'PUT,PATCH','callback'=>[$this,'update_item'],'permission_callback'=>[Auth::class,'tier2']],['methods'=>'DELETE','callback'=>[$this,'delete_item'],'permission_callback'=>[Auth::class,'tier3']]]);
    }
    public function list_all(\WP_REST_Request $r): \WP_REST_Response { return $this->success(array_map(fn($m)=>['id'=>$m->term_id,'name'=>$m->name,'slug'=>$m->slug,'count'=>$m->count],wp_get_nav_menus())); }
    public function get(\WP_REST_Request $r): \WP_REST_Response|\WP_Error { $items=wp_get_nav_menu_items((int)$r['id']);if($items===false)return $this->error('Not found',404);return $this->success(array_map(fn($i)=>['id'=>$i->ID,'title'=>$i->title,'url'=>$i->url,'type'=>$i->type,'parent'=>(int)$i->menu_item_parent,'order'=>$i->menu_order],$items)); }
    public function create(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $name=sanitize_text_field((string)($r->get_json_params()['name']??'')); if($name==='') return $this->error('name required');
        $id=wp_create_nav_menu($name); if(is_wp_error($id)) return $this->error($id->get_error_message(),500);
        $this->log('create_menu','menu',(int)$id,['name'=>$name],2); return $this->success(['id'=>(int)$id],201);
    }
    public function update(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id=(int)$r['id']; $name=sanitize_text_field((string)($r->get_json_params()['name']??'')); if($name==='') return $this->error('name required');
        $res=wp_update_nav_menu_object($id,['menu-name'=>$name]); if(is_wp_error($res)||$res===0) return $this->error(is_wp_error($res)?$res->get_error_message():'Failed to update menu',500);
        $this->log('update_menu','menu',$id,['name'=>$name],2); return $this->success(['updated'=>true,'id'=>$id]);
    }
    public function delete(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id=(int)$r['id']; $res=wp_delete_nav_menu($id); if(!$res) return $this->error('Failed to delete menu',500);
        $this->log('delete_menu','menu',$id,[],3); return $this->success(['deleted'=>true,'id'=>$id]);
    }
    public function add_item(\WP_REST_Request $r): \WP_REST_Response|\WP_Error { $d=$r->get_json_params();$id=wp_update_nav_menu_item((int)$r['id'],0,['menu-item-title'=>sanitize_text_field($d['title']??''),'menu-item-url'=>esc_url_raw($d['url']??''),'menu-item-status'=>'publish','menu-item-type'=>'custom']);if(is_wp_error($id))return $this->error($id->get_error_message(),500);$this->log('add_menu_item','menu',(int)$r['id'],[],2);return $this->success(['id'=>$id],201); }
    public function update_item(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d=$r->get_json_params(); $menu=(int)$r['id']; $item=(int)$r['item_id']; $args=['menu-item-status'=>'publish'];
        if(isset($d['title'])) $args['menu-item-title']=sanitize_text_field((string)$d['title']);
        if(isset($d['url'])) $args['menu-item-url']=esc_url_raw((string)$d['url']);
        if(isset($d['parent'])) $args['menu-item-parent-id']=(int)$d['parent'];
        $id=wp_update_nav_menu_item($menu,$item,$args); if(is_wp_error($id)) return $this->error($id->get_error_message(),500);
        $this->log('update_menu_item','menu',$menu,['item_id'=>$item],2); return $this->success(['updated'=>true,'item_id'=>(int)$id]);
    }
    public function delete_item(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $item=(int)$r['item_id']; $res=wp_delete_post($item,true); if(!$res) return $this->error('Failed to delete menu item',500);
        $this->log('delete_menu_item','menu',(int)$r['id'],['item_id'=>$item],3); return $this->success(['deleted'=>true,'item_id'=>$item]);
    }
}
