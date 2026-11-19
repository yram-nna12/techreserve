<?php
namespace Config; use CodeIgniter\Config\BaseConfig;
class VerificationAssistance extends BaseConfig { public string $baseUrl='http://127.0.0.1:8081';public string $apiKey='';public int $maxFileSizeBytes=5242880;public function __construct(){parent::__construct();$this->baseUrl=rtrim((string)env('verificationAssistance.baseUrl',$this->baseUrl),'/');$this->apiKey=(string)env('verificationAssistance.apiKey','');}}
