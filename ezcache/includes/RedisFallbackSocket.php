<?php
namespace Upress\EzCache;

/** Bounded RESP2 client for page-cache deployments without phpredis. */
class RedisFallbackSocket {
    private $socket;
    private $timeout;
    private $deadline;
    private $bytes_left;
    public function __construct($host, $port, $timeout) {
        $this->timeout = $timeout;
        $this->socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($this->socket) { stream_set_timeout($this->socket, (int)$timeout, (int)(($timeout - (int)$timeout)*1000000)); }
    }
    public function connected() { return is_resource($this->socket) && ! feof($this->socket); }
    private function fail() {
        if (is_resource($this->socket)) { fclose($this->socket); }
        $this->socket = null;
        throw new \RuntimeException('Invalid or incomplete Redis response.');
    }
    private function send(...$args) {
        if (!$this->connected()) { return false; }
        $this->deadline = microtime(true) + $this->timeout;
        $this->bytes_left = 16*1024*1024;
        $cmd = '*' . count($args) . "\r\n";
        foreach ($args as $arg) { $arg=(string)$arg; $cmd .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n"; }
        try {
            $offset=0;
            while ($offset < strlen($cmd)) {
                if (microtime(true)>$this->deadline) { $this->fail(); }
                $n=fwrite($this->socket, substr($cmd,$offset));
                if (!$n) { $this->fail(); } $offset += $n;
            }
            return $this->read_response();
        } catch (\Throwable $e) { return false; }
    }
    private function read_response($depth=0) {
        if ($depth>16 || !is_resource($this->socket) || ($this->deadline && microtime(true)>$this->deadline)) { $this->fail(); }
        $line=fgets($this->socket, 65536);
        if (false===$line || substr($line,-2)!=="\r\n") { $this->fail(); }
        $this->bytes_left -= strlen($line);
        if ($this->bytes_left < 0) { $this->fail(); }
        $data=substr($line,1,-2);
        switch ($line[0]) {
            case '+': return $data;
            case '-': return false;
            case ':': if (!preg_match('/^-?\d+$/D',$data)) { $this->fail(); } return (int)$data;
            case '$':
                if (!preg_match('/^-?\d+$/D',$data)) { $this->fail(); }
                $len=(int)$data;
                if ($len===-1) { return false; }
                if ($len<0 || $len>$this->bytes_left || $len>16*1024*1024) { $this->fail(); }
                $bulk='';
                while (strlen($bulk)<$len+2) {
                    if ($this->deadline && microtime(true)>$this->deadline) { $this->fail(); }
                    $part=fread($this->socket,$len+2-strlen($bulk));
                    if ($part===false || $part==='' || stream_get_meta_data($this->socket)['timed_out']) { $this->fail(); }
                    $bulk.=$part;
                }
                if (substr($bulk,-2)!=="\r\n") { $this->fail(); }
                $this->bytes_left -= $len+2;
                return substr($bulk,0,$len); // Preserve binary payloads ending in CR/LF.
            case '*':
                if (!preg_match('/^-?\d+$/D',$data)) { $this->fail(); }
                $count=(int)$data;
                if ($count===-1) { return []; }
                if ($count<0 || $count>10000) { $this->fail(); }
                $items=[]; for($i=0;$i<$count;$i++) { $items[]=$this->read_response($depth+1); } return $items;
            default: $this->fail();
        }
    }
    public function ping(){return $this->send('PING');}
    public function auth($password){return is_array($password) ? $this->send('AUTH',...$password) : $this->send('AUTH',$password);}
    public function select($db){return $this->send('SELECT',$db);}
    public function get($key){return $this->send('GET',$key);}
    public function set($key,$value){return $this->send('SET',$key,$value);}
    public function setex($key,$ttl,$value){return $this->send('SETEX',$key,$ttl,$value);}
    public function del(array $keys){return $this->send('DEL',...$keys);}
    public function hGet($key,$field){return $this->send('HGET',$key,$field);}
    public function hSet($key,$field,$value){return $this->send('HSET',$key,$field,$value);}
    public function hDel($key,$field){return $this->send('HDEL',$key,$field);}
    public function hLen($key){return $this->send('HLEN',$key);}
    public function expire($key,$ttl){return $this->send('EXPIRE',$key,(int)$ttl);}
    public function scan(&$cursor,$pattern,$count=500){
        if($cursor===0){return false;}
        $r=$this->send('SCAN',$cursor===null?0:$cursor,'MATCH',$pattern,'COUNT',$count);
        if(!is_array($r)||count($r)!==2||!is_array($r[1])){throw new \RuntimeException('Redis SCAN failed.');}
        $cursor=(int)$r[0];return $r[1];
    }
    public function info(){
        $raw=$this->send('INFO');if(!is_string($raw)){return false;}$info=[];
        foreach(explode("\r\n",$raw) as $line){if(strpos($line,':')!==false){list($key,$value)=explode(':',$line,2);$info[$key]=$value;}}return $info;
    }
}
