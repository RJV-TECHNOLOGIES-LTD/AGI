<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;
use RJV_AGI_Bridge\Auth;

class FileSystem extends Base {
    public function register_routes(): void {
        register_rest_route($this->namespace,'/files/theme',[['methods'=>'GET','callback'=>[$this,'list_files'],'permission_callback'=>[Auth::class,'tier1']]]);
        register_rest_route($this->namespace,'/files/theme/read',[['methods'=>'POST','callback'=>[$this,'read'],'permission_callback'=>[Auth::class,'tier2']]]);
        register_rest_route($this->namespace,'/files/theme/write',[['methods'=>'POST','callback'=>[$this,'write'],'permission_callback'=>[Auth::class,'tier3']]]);
    }
    public function list_files(\WP_REST_Request $r): \WP_REST_Response { $dir=get_stylesheet_directory();return $this->success($this->scan($dir,$dir)); }
    public function read(\WP_REST_Request $r): \WP_REST_Response|\WP_Error { $f=sanitize_text_field($r->get_json_params()['file']??'');$b=get_stylesheet_directory();$full=realpath($b.'/'.$f);if(!$full||!str_starts_with($full,$b))return $this->error('Invalid path',403);if(!file_exists($full))return $this->error('Not found',404);return $this->success(['file'=>$f,'content'=>file_get_contents($full),'size'=>filesize($full)]); }
    public function write(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d=$r->get_json_params();$f=sanitize_text_field($d['file']??'');$c=$d['content']??'';$b=get_stylesheet_directory();
        if(strlen($c)>1048576)return $this->error('Content too large (max 1MB)',403);
        $ext=strtolower(pathinfo($f,PATHINFO_EXTENSION));$blocked=['php','phtml','php5','phar'];
        if(in_array($ext,$blocked))return $this->error(".{$ext} blocked",403);
        $ok=['css','js','html','json','svg','txt','md'];if(!in_array($ext,$ok))return $this->error(".{$ext} not allowed",403);
        $dir=dirname($b.'/'.$f);if(!is_dir($dir))wp_mkdir_p($dir);$full=realpath($dir).'/'.basename($f);
        if(!str_starts_with($full,$b))return $this->error('Invalid path',403);
        if(file_exists($full)&&is_link($full))return $this->error('Symlinks not allowed',403);
        file_put_contents($full,$c);
        $this->log('write_file','filesystem',0,['file'=>$f],3);return $this->success(['written'=>true,'size'=>strlen($c)]);
    }
    private function scan(string $d, string $b): array { $r=[];foreach(scandir($d) as $i){if($i==='.'||$i==='..')continue;$p=$d.'/'.$i;$rel=str_replace($b.'/','',$p);if(is_dir($p))$r=array_merge($r,$this->scan($p,$b));else $r[]=['file'=>$rel,'size'=>filesize($p),'ext'=>pathinfo($i,PATHINFO_EXTENSION)];}return $r; }
}
