<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;
use RJV_AGI_Bridge\Auth;

class Widgets extends Base {
    public function register_routes(): void {
        register_rest_route($this->namespace,'/widgets',[['methods'=>'GET','callback'=>[$this,'list_all'],'permission_callback'=>[Auth::class,'tier1']]]);
    }
    public function list_all(\WP_REST_Request $r): \WP_REST_Response {
        global $wp_registered_sidebars;$sidebars=wp_get_sidebars_widgets();$res=[];
        foreach($wp_registered_sidebars as $id=>$s) $res[]=['id'=>$id,'name'=>$s['name'],'widgets'=>$sidebars[$id]??[]];
        return $this->success($res);
    }
}
