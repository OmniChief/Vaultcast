<?php
declare(strict_types=1);

final class CompatibilityApiResult extends Exception {
    public $data;public $status;
    public function __construct($data,int $status){parent::__construct('Compatibility response');$this->data=$data;$this->status=$status;}
}
final class CompatibilityApi {
    public static $context=null;
    public static function call(string $path,string $method='GET',array $body=[],array $query=[]): array {
        if(self::$context!==null)throw new RuntimeException('Nested compatibility request.');
        $server=$_SERVER;$get=$_GET;
        self::$context=['path'=>$path,'body'=>$body];
        $_SERVER['REQUEST_METHOD']=$method;$_SERVER['CONTENT_TYPE']='application/json';
        $_SERVER['REQUEST_URI']=rtrim(\Vaultcast\Database::basePath(),'/').'/api'.$path;$_GET=$query;
        try{require STREAM_ROOT.'/src/Http/routes.php';throw new RuntimeException('No response.');}
        catch(CompatibilityApiResult $result){
            $data=is_array($result->data)?$result->data:['value'=>$result->data];
            if($result->status>=400 || ($data['ok'] ?? true)===false)throw new RuntimeException((string)($data['error'] ?? 'Could not complete this request.'));
            return $data;
        }finally{self::$context=null;$_SERVER=$server;$_GET=$get;}
    }
}
