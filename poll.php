<?php

class Context{
    private $call;
    public function __construct($callback){
        $this->call=$callback;
    }
    public function getCall() {
        return $this->call;
    }
}
/**
 * Future 状态
 */
class FutureState{
    private $state;
    private $data;
    public function isReady() {
        return $this->state==0;
    }
    public function isPending() {
        return $this->state==1;
    }
    public function readyData(){
        return $this->data;
    }
    public static function ready($data){
        $obj=new static;
        $obj->state=0;
        $obj->data=$data;
        return $obj;
    }
    public static function pending(){
        $obj=new static;
        $obj->state=1;
        return $obj;
    }
}
/**
 * Future 接口
 */
interface Future{
    public function poll(Context $cx):FutureState;
}
/**
 * 基于生成器的Future
 */
class GrenatorFuture implements Future{
    public static function factory(callable $call) {
        return new self($call);
    }
    private $generator;
    public function __construct(callable $call){
        $this->generator=call_user_func($call);
        
    }
    public function poll(Context $cx): FutureState
    {
        if(!$this->generator instanceof Generator){
            return FutureState::ready($this->generator);
        }
        restart:
        $data=$this->generator->current();
        if ($data instanceof Future){
            $ret=$data->poll($cx);
            if ($ret->isReady()) {
                $this->generator->send($ret->readyData());
                if($this->generator->valid()){
                    goto restart;
                }
                return FutureState::ready($this->generator->getReturn());
            }else{
                return $ret;
            }
        }else return FutureState::ready($data);
    }
}



$mysqli=new mysqli("127.0.0.1","test","123456","test_db","3306");

//系统调用
class SystemCall{
    private static $data;
    public static function set($call){
        return self::$data=$call;
    }
    public static function poll(){
        if (empty(self::$data)){
            return true;
        }
        global $mysqli;
        $links = $errors = $reject = array();
        $links[] = $errors[] = $reject[] = $mysqli;
        mysqli_poll($links, $errors, $reject, 1);
        call_user_func(self::$data);
        return true;
    }
}


//自定义future
class mysqlsqlfuture implements Future{
    private $sql;
    private $send;
    public function __construct($sql) {
        $this->sql=$sql;
    }
    public function poll(Context $cx): FutureState
    {
        global $mysqli;
        if (!$this->send) {
            $this->send=1;
            $mysqli->query($this->sql,MYSQLI_ASYNC);
            SystemCall::set($cx->getCall());
            return FutureState::pending();
        }
        $row=$mysqli->reap_async_query();
        return FutureState::ready(111);
    }
}
//运行时
class Runtime{
    public static function block_on(Future $future) {
        $cx=new Context(function(){
            //回调
        });
        while (SystemCall::poll()) {
            $next_future=$future->poll($cx);
            if($next_future->isReady())break;
        }
        return $next_future->readyData();
    }
}
//用户代码
$data=Runtime::block_on(new GrenatorFuture(function () {
    $a=yield GrenatorFuture::factory(function(){
        $out=yield new mysqlsqlfuture("select sleep(5)");
        return $out;
    });;
    $b=GrenatorFuture::factory(function()use($a){
        return $a+1;
    });
    $b=yield $b;
    $b=GrenatorFuture::factory(function()use($a){
        $out=yield GrenatorFuture::factory(function(){
            return 111;
        });;
        return $out+$a;
    });
    $b=yield $b;
    return $b;
}));

var_dump($data);