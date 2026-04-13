<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;
use RJV_AGI_Bridge\AuditLog;

abstract class Base {
    protected string $namespace = 'rjv-agi/v1';
    abstract public function register_routes(): void;
    protected function success($data, int $s=200): \WP_REST_Response { return new \WP_REST_Response(['success'=>true,'data'=>$data],$s); }
    protected function error(string $msg, int $s=400): \WP_Error { return new \WP_Error('error',$msg,['status'=>$s]); }
    protected function log(string $a, string $r='', int $id=0, array $d=[], int $t=1): void { AuditLog::log($a,$r,$id,$d,$t); }
}
