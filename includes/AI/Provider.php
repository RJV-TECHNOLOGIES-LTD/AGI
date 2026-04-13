<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\AI;

interface Provider {
    public function complete(string $sys, string $msg, array $opts=[]): array;
    public function get_name(): string;
    public function get_model(): string;
    public function is_configured(): bool;
}
