<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;
use RJV_AGI_Bridge\Auth;

class Tools extends Base {
    public function register_routes(): void {
        register_rest_route($this->namespace,'/tools/privacy/requests',[['methods'=>'GET','callback'=>[$this,'list_privacy_requests'],'permission_callback'=>[Auth::class,'tier1']],['methods'=>'POST','callback'=>[$this,'create_privacy_request'],'permission_callback'=>[Auth::class,'tier3']]]);
        register_rest_route($this->namespace,'/tools/privacy/requests/(?P<id>\d+)',[['methods'=>'DELETE','callback'=>[$this,'delete_privacy_request'],'permission_callback'=>[Auth::class,'tier3']]]);
        register_rest_route($this->namespace,'/tools/export',[['methods'=>'POST','callback'=>[$this,'export_settings'],'permission_callback'=>[Auth::class,'tier3']]]);
        register_rest_route($this->namespace,'/tools/import',[['methods'=>'POST','callback'=>[$this,'import_settings'],'permission_callback'=>[Auth::class,'tier3']]]);
        register_rest_route($this->namespace,'/tools/jobs/(?P<job_id>[a-zA-Z0-9_-]+)',[['methods'=>'GET','callback'=>[$this,'job_status'],'permission_callback'=>[Auth::class,'tier1']]]);
    }
    public function list_privacy_requests(\WP_REST_Request $r): \WP_REST_Response {
        $type=sanitize_key((string)($r->get_param('type')?:''));
        $status=sanitize_key((string)($r->get_param('status')?:''));
        $args=['posts_per_page'=>100,'post_type'=>'user_request','post_status'=>'any','orderby'=>'ID','order'=>'DESC'];
        if($type!=='') $args['meta_query'][]=['key'=>'_wp_user_request_action_name','value'=>$type];
        if($status!=='') $args['post_status']=$status;
        $q=new \WP_Query($args);
        $items=array_map(function(\WP_Post $p): array {
            return ['id'=>$p->ID,'email'=>(string)get_post_meta($p->ID,'_wp_user_request_confirmed_email',true),'action'=>(string)get_post_meta($p->ID,'_wp_user_request_action_name',true),'status'=>$p->post_status,'confirmed'=>(bool)get_post_meta($p->ID,'_wp_user_request_confirmed_timestamp',true),'created'=>$p->post_date_gmt];
        },$q->posts);
        return $this->success(['requests'=>$items,'total'=>(int)$q->found_posts]);
    }
    public function create_privacy_request(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d=$r->get_json_params(); $email=sanitize_email((string)($d['email']??'')); $action=sanitize_key((string)($d['action']??''));
        if($email==='') return $this->error('email required');
        if(!in_array($action,['export_personal_data','remove_personal_data'],true)) return $this->error('action must be export_personal_data or remove_personal_data');
        if(!function_exists('wp_create_user_request')) require_once ABSPATH.'wp-admin/includes/user.php';
        $request_id=wp_create_user_request($email,$action); if(is_wp_error($request_id)) return $this->error($request_id->get_error_message(),500);
        if(!empty($d['send_confirmation'])) wp_send_user_request((int)$request_id);
        $this->log('create_privacy_request','tool',(int)$request_id,['action'=>$action,'email'=>$email],3);
        return $this->success(['id'=>(int)$request_id,'email'=>$email,'action'=>$action],201);
    }
    public function delete_privacy_request(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id=(int)$r['id']; $deleted=wp_delete_post($id,true); if(!$deleted) return $this->error('Failed to delete privacy request',500);
        $this->log('delete_privacy_request','tool',$id,[],3);
        return $this->success(['deleted'=>true,'id'=>$id]);
    }
    public function export_settings(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d=(array)$r->get_json_params();
        $keys=array_values(array_filter(array_map('sanitize_key',(array)($d['option_keys'] ?? $this->default_option_keys()))));
        if(empty($keys)) return $this->error('option_keys required');
        $job_id='job_'.wp_generate_uuid4();
        $this->set_job($job_id,['type'=>'export','status'=>'running','started_at'=>gmdate('Y-m-d H:i:s'),'details'=>['count'=>count($keys)]]);
        try{
            $payload=['version'=>'1.0','generated_at'=>gmdate('c'),'option_keys'=>$keys,'options'=>[]];
            foreach($keys as $key){ $payload['options'][$key]=get_option($key,null); }
            $upload=wp_upload_dir();
            if(!empty($upload['error'])) throw new \RuntimeException((string)$upload['error']);
            $dir=trailingslashit((string)$upload['basedir']).'rjv-agi-exports';
            if(!wp_mkdir_p($dir)) throw new \RuntimeException('Cannot create export directory');
            $path=trailingslashit($dir)."{$job_id}.json";
            if(file_put_contents($path, wp_json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))===false) throw new \RuntimeException('Failed writing export file');
            $this->set_job($job_id,['status'=>'completed','finished_at'=>gmdate('Y-m-d H:i:s'),'result'=>['path'=>$path,'url'=>trailingslashit((string)$upload['baseurl'])."rjv-agi-exports/{$job_id}.json",'option_count'=>count($keys)]]);
            $this->log('tools_export','tool',0,['job_id'=>$job_id,'count'=>count($keys)],3);
            return $this->success(['job_id'=>$job_id,'status'=>'completed','path'=>$path,'url'=>trailingslashit((string)$upload['baseurl'])."rjv-agi-exports/{$job_id}.json"]);
        } catch(\Throwable $e){
            $this->set_job($job_id,['status'=>'failed','finished_at'=>gmdate('Y-m-d H:i:s'),'error'=>$e->getMessage()]);
            return $this->error($e->getMessage(),500);
        }
    }
    public function import_settings(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d=(array)$r->get_json_params();
        $job_id='job_'.wp_generate_uuid4();
        $this->set_job($job_id,['type'=>'import','status'=>'running','started_at'=>gmdate('Y-m-d H:i:s')]);
        try{
            $package=$this->resolve_package($d);
            $allowed=array_flip($this->default_option_keys());
            $imported=0; $skipped=0;
            foreach((array)($package['options'] ?? []) as $key=>$value){
                $key=sanitize_key((string)$key);
                if($key===''||!isset($allowed[$key])){ $skipped++; continue; }
                update_option($key,$value,false); $imported++;
            }
            $this->set_job($job_id,['status'=>'completed','finished_at'=>gmdate('Y-m-d H:i:s'),'result'=>['imported'=>$imported,'skipped'=>$skipped]]);
            $this->log('tools_import','tool',0,['job_id'=>$job_id,'imported'=>$imported,'skipped'=>$skipped],3);
            return $this->success(['job_id'=>$job_id,'status'=>'completed','imported'=>$imported,'skipped'=>$skipped]);
        } catch(\Throwable $e){
            $this->set_job($job_id,['status'=>'failed','finished_at'=>gmdate('Y-m-d H:i:s'),'error'=>$e->getMessage()]);
            return $this->error($e->getMessage(),500);
        }
    }
    public function job_status(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $job_id=sanitize_key((string)$r['job_id']); $job=$this->get_job($job_id);
        if(!$job) return $this->error('Job not found',404);
        return $this->success(['job_id'=>$job_id,'job'=>$job]);
    }
    private function default_option_keys(): array {
        return [
            'blogname','blogdescription','siteurl','home','timezone_string','date_format','time_format',
            'default_category','default_comment_status','default_ping_status','posts_per_page',
            'show_on_front','page_on_front','page_for_posts','permalink_structure','WPLANG',
        ];
    }
    private function resolve_package(array $payload): array {
        if(!empty($payload['package']) && is_array($payload['package'])) return (array)$payload['package'];
        $path=(string)($payload['path'] ?? '');
        if($path==='') throw new \RuntimeException('package or path is required');
        $real=realpath($path); if($real===false||!is_readable($real)) throw new \RuntimeException('Invalid import path');
        $upload=wp_upload_dir(); $base=realpath((string)$upload['basedir']);
        if($base===false) throw new \RuntimeException('Uploads directory unavailable');
        $basePrefix=rtrim($base,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if($real!==$base && strpos($real,$basePrefix)!==0) throw new \RuntimeException('Import path must be inside uploads directory');
        $json=file_get_contents($real); if($json===false) throw new \RuntimeException('Failed reading import file');
        $decoded=json_decode($json,true); if(!is_array($decoded)) throw new \RuntimeException('Invalid import package');
        return $decoded;
    }
    private function get_jobs(): array { $jobs=get_option('rjv_agi_tools_jobs',[]); return is_array($jobs)?$jobs:[]; }
    private function set_job(string $job_id, array $changes): void {
        $jobs=$this->get_jobs(); $job=is_array($jobs[$job_id] ?? null)?$jobs[$job_id]:[];
        $jobs[$job_id]=array_merge($job,$changes,['updated_at'=>gmdate('Y-m-d H:i:s')]);
        if(count($jobs)>200){ uasort($jobs,fn($a,$b)=>strcmp((string)($b['updated_at'] ?? ''),(string)($a['updated_at'] ?? ''))); $jobs=array_slice($jobs,0,200,true); }
        update_option('rjv_agi_tools_jobs',$jobs,false);
    }
    private function get_job(string $job_id): ?array {
        $jobs=$this->get_jobs(); $job=$jobs[$job_id] ?? null;
        return is_array($job)?$job:null;
    }
}
