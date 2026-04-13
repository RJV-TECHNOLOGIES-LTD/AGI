<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;
use RJV_AGI_Bridge\Auth;

class Cron extends Base {
    public function register_routes(): void {
        register_rest_route($this->namespace,'/cron',[['methods'=>'GET','callback'=>[$this,'list_all'],'permission_callback'=>[Auth::class,'tier1']]]);
        register_rest_route($this->namespace,'/cron/schedule',[['methods'=>'POST','callback'=>[$this,'schedule'],'permission_callback'=>[Auth::class,'tier2']]]);
        register_rest_route($this->namespace,'/cron/clear',[['methods'=>'POST','callback'=>[$this,'clear'],'permission_callback'=>[Auth::class,'tier3']]]);
    }
    public function list_all(\WP_REST_Request $r): \WP_REST_Response {
        $crons=_get_cron_array();$res=[];foreach($crons as $t=>$evts)foreach($evts as $h=>$info)foreach($info as $k=>$d)$res[]=['hook'=>$h,'next'=>gmdate('Y-m-d H:i:s',$t),'schedule'=>$d['schedule']??'single'];
        return $this->success($res);
    }
    public function schedule(\WP_REST_Request $r): \WP_REST_Response|\WP_Error { $d=$r->get_json_params();$h=sanitize_key($d['hook']??'');$rec=sanitize_key($d['recurrence']??'');if(empty($h))return $this->error('hook required');$t=!empty($d['time'])?strtotime($d['time']):time();if($rec)wp_schedule_event($t,$rec,$h);else wp_schedule_single_event($t,$h);$this->log('schedule','cron',0,['hook'=>$h],2);return $this->success(['scheduled'=>$h]); }
    public function clear(\WP_REST_Request $r): \WP_REST_Response { $h=sanitize_key($r->get_json_params()['hook']??'');wp_clear_scheduled_hook($h);$this->log('clear','cron',0,['hook'=>$h],3);return $this->success(['cleared'=>$h]); }
}
