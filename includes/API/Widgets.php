<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;
use RJV_AGI_Bridge\Auth;

class Widgets extends Base {
    public function register_routes(): void {
        register_rest_route($this->namespace,'/widgets',[['methods'=>'GET','callback'=>[$this,'list_all'],'permission_callback'=>[Auth::class,'tier1']]]);
        register_rest_route($this->namespace,'/widgets/available',[['methods'=>'GET','callback'=>[$this,'available'],'permission_callback'=>[Auth::class,'tier1']]]);
        register_rest_route($this->namespace,'/widgets',[['methods'=>'POST','callback'=>[$this,'create'],'permission_callback'=>[Auth::class,'tier2']]]);
        register_rest_route($this->namespace,'/widgets/(?P<id>[a-z0-9_-]+-\d+)',[
            ['methods'=>'GET','callback'=>[$this,'get'],'permission_callback'=>[Auth::class,'tier1']],
            ['methods'=>'PUT,PATCH','callback'=>[$this,'update'],'permission_callback'=>[Auth::class,'tier2']],
            ['methods'=>'DELETE','callback'=>[$this,'delete'],'permission_callback'=>[Auth::class,'tier3']],
        ]);
        register_rest_route($this->namespace,'/widgets/(?P<id>[a-z0-9_-]+-\d+)/move',[['methods'=>'POST','callback'=>[$this,'move'],'permission_callback'=>[Auth::class,'tier2']]]);
    }
    public function list_all(\WP_REST_Request $r): \WP_REST_Response {
        global $wp_registered_sidebars; $sidebars=wp_get_sidebars_widgets(); $res=[];
        foreach($wp_registered_sidebars as $id=>$s) {
            $widgets=array_values(array_filter((array)($sidebars[$id]??[]),fn($w)=>is_string($w) && $w!=='' ));
            $res[]=['id'=>$id,'name'=>$s['name'],'widgets'=>array_map(fn($wid)=>$this->widget_state($wid),$widgets)];
        }
        return $this->success($res);
    }
    public function available(\WP_REST_Request $r): \WP_REST_Response {
        global $wp_registered_widget_controls; $items=[];
        foreach((array)$wp_registered_widget_controls as $id=>$widget){
            $items[]=[
                'id'=>$id,
                'base_id'=>(string)($widget['id_base'] ?? ''),
                'name'=>(string)($widget['name'] ?? $id),
            ];
        }
        return $this->success(['widgets'=>$items]);
    }
    public function create(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d=(array)$r->get_json_params();
        $base=sanitize_key((string)($d['type'] ?? $d['base_id'] ?? ''));
        $sidebar=sanitize_key((string)($d['sidebar'] ?? ''));
        if($base===''||$sidebar==='') return $this->error('type/base_id and sidebar are required');
        global $wp_registered_sidebars; if(!isset($wp_registered_sidebars[$sidebar])) return $this->error('Invalid sidebar');
        $instances=(array)get_option("widget_{$base}",[]);
        $numbers=array_filter(array_keys($instances),fn($k)=>is_int($k)||ctype_digit((string)$k));
        $next=(int)(empty($numbers)?1:max(array_map('intval',$numbers))+1);
        $settings=(array)($d['settings'] ?? []);
        $instances[$next]=$settings;
        update_option("widget_{$base}",$instances,false);
        $widget_id="{$base}-{$next}";
        $this->place_widget($widget_id,$sidebar,isset($d['position'])?(int)$d['position']:null);
        $this->log('create_widget','widget',0,['widget_id'=>$widget_id,'sidebar'=>$sidebar],2);
        return $this->success(['created'=>true,'widget'=>$this->widget_state($widget_id)],201);
    }
    public function get(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $state=$this->widget_state((string)$r['id']); if($state===null) return $this->error('Not found',404);
        return $this->success($state);
    }
    public function update(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id=(string)$r['id']; $parsed=$this->parse_widget_id($id); if(!$parsed) return $this->error('Invalid widget id');
        [$base,$number]=$parsed;
        $instances=(array)get_option("widget_{$base}",[]);
        if(!array_key_exists($number,$instances)) return $this->error('Not found',404);
        $d=(array)$r->get_json_params();
        if(isset($d['settings'])&&is_array($d['settings'])) {
            $instances[$number]=array_merge((array)$instances[$number],(array)$d['settings']);
            update_option("widget_{$base}",$instances,false);
        }
        if(isset($d['sidebar'])) {
            $sidebar=sanitize_key((string)$d['sidebar']);
            global $wp_registered_sidebars; if(!isset($wp_registered_sidebars[$sidebar])) return $this->error('Invalid sidebar');
            $this->place_widget($id,$sidebar,isset($d['position'])?(int)$d['position']:null);
        }
        $this->log('update_widget','widget',0,['widget_id'=>$id],2);
        return $this->success(['updated'=>true,'widget'=>$this->widget_state($id)]);
    }
    public function delete(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id=(string)$r['id']; $parsed=$this->parse_widget_id($id); if(!$parsed) return $this->error('Invalid widget id');
        [$base,$number]=$parsed;
        $instances=(array)get_option("widget_{$base}",[]);
        if(!array_key_exists($number,$instances)) return $this->error('Not found',404);
        unset($instances[$number]); update_option("widget_{$base}",$instances,false);
        $this->remove_widget_from_all_sidebars($id);
        $this->log('delete_widget','widget',0,['widget_id'=>$id],3);
        return $this->success(['deleted'=>true,'id'=>$id]);
    }
    public function move(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id=(string)$r['id']; if($this->widget_state($id)===null) return $this->error('Not found',404);
        $d=(array)$r->get_json_params(); $sidebar=sanitize_key((string)($d['sidebar'] ?? ''));
        if($sidebar==='') return $this->error('sidebar is required');
        global $wp_registered_sidebars; if(!isset($wp_registered_sidebars[$sidebar])) return $this->error('Invalid sidebar');
        $this->place_widget($id,$sidebar,isset($d['position'])?(int)$d['position']:null);
        $this->log('move_widget','widget',0,['widget_id'=>$id,'sidebar'=>$sidebar],2);
        return $this->success(['moved'=>true,'widget'=>$this->widget_state($id)]);
    }
    private function widget_state(string $widget_id): ?array {
        $parsed=$this->parse_widget_id($widget_id); if(!$parsed) return null;
        [$base,$number]=$parsed;
        $instances=(array)get_option("widget_{$base}",[]);
        if(!array_key_exists($number,$instances)) return null;
        $location=$this->find_widget_sidebar($widget_id);
        return ['id'=>$widget_id,'base_id'=>$base,'number'=>$number,'settings'=>$instances[$number],'sidebar'=>$location['sidebar'],'position'=>$location['position']];
    }
    private function parse_widget_id(string $widget_id): ?array {
        if(!preg_match('/^([a-z0-9_-]+)-(\d+)$/i',$widget_id,$m)) return null;
        return [sanitize_key($m[1]),(int)$m[2]];
    }
    private function find_widget_sidebar(string $widget_id): array {
        $sidebars=wp_get_sidebars_widgets();
        foreach((array)$sidebars as $sidebar=>$widgets){
            if(!is_array($widgets)) continue;
            $pos=array_search($widget_id,$widgets,true);
            if($pos!==false) return ['sidebar'=>(string)$sidebar,'position'=>(int)$pos];
        }
        return ['sidebar'=>null,'position'=>null];
    }
    private function remove_widget_from_all_sidebars(string $widget_id): void {
        $sidebars=wp_get_sidebars_widgets();
        foreach((array)$sidebars as $sidebar=>$widgets){
            if(!is_array($widgets)) continue;
            $sidebars[$sidebar]=array_values(array_filter($widgets,fn($w)=>$w!==$widget_id));
        }
        wp_set_sidebars_widgets($sidebars);
    }
    private function place_widget(string $widget_id, string $sidebar, ?int $position=null): void {
        $sidebars=wp_get_sidebars_widgets();
        foreach((array)$sidebars as $k=>$widgets){
            if(is_array($widgets)) $sidebars[$k]=array_values(array_filter($widgets,fn($w)=>$w!==$widget_id));
        }
        $target=array_values(array_filter((array)($sidebars[$sidebar] ?? []),fn($w)=>$w!==$widget_id));
        if($position===null || $position<0 || $position>=count($target)) $target[]=$widget_id;
        else array_splice($target,$position,0,[$widget_id]);
        $sidebars[$sidebar]=$target;
        wp_set_sidebars_widgets($sidebars);
    }
}
