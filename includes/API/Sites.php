<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;
use RJV_AGI_Bridge\Auth;

class Sites extends Base {
    public function register_routes(): void {
        register_rest_route($this->namespace,'/sites/current',[['methods'=>'GET','callback'=>[$this,'current'],'permission_callback'=>[Auth::class,'tier1']]]);
        register_rest_route($this->namespace,'/sites',[['methods'=>'GET','callback'=>[$this,'list_all'],'permission_callback'=>[Auth::class,'tier1']]]);
    }
    public function current(\WP_REST_Request $r): \WP_REST_Response {
        return $this->success(['multisite'=>is_multisite(),'blog_id'=>get_current_blog_id(),'site_url'=>site_url(),'home_url'=>home_url(),'network_admin'=>is_network_admin()]);
    }
    public function list_all(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        if(!is_multisite()) return $this->success(['multisite'=>false,'sites'=>[]]);
        $sites=get_sites(['number'=>200]); $items=array_map(fn(\WP_Site $s)=>['blog_id'=>(int)$s->blog_id,'domain'=>$s->domain,'path'=>$s->path,'network_id'=>(int)$s->network_id],$sites);
        return $this->success(['multisite'=>true,'sites'=>$items]);
    }
}
