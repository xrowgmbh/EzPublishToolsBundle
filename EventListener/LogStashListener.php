<?php

namespace xrow\Bundle\EzPublishToolsBundle\EventListener;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\Event\PostResponseEvent;

class LogStashListener implements EventSubscriberInterface
{
    const DISABLED = 1;
    const ENABLED  = 2;
    private $profiler = null;
    private $exception = null;
    private $userid = null;
    private $username = null;
    public function __construct()
    {
        if ( function_exists( 'xhprof_enable' ) and $this->profiler === null )
        {
            if ( strpos( $_SERVER["REQUEST_URI"], '/_' ) !== 0 )
            {
                //xhprof_enable(XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY);
                xhprof_sample_enable();
                $this->profiler = self::ENABLED;
            }
            else 
            {
                $this->profiler = self::DISABLED;
            }
        }

    }
    public function onKernelException(GetResponseForExceptionEvent $event)
    {
        if ($this->onlyMasterRequests && !$event->isMasterRequest()) {
            return;
        }
    
        $this->exception = $event->getException();
    }
    public function isEnabled()
    {
        return self::DISABLED !== $this->profiler;
    }
    public function onKernelRequest(GetResponseEvent  $event)
    {
        if (HttpKernelInterface::MASTER_REQUEST !== $event->getRequestType()) {
            return;
        }
    
    
    }
    public function onKernelResponse(FilterResponseEvent $event)
    {

        if (HttpKernelInterface::MASTER_REQUEST !== $event->getRequestType()) {
            return;
        }

    
    }
    private function getURL()
    {
        $pageURL = 'http';
        if ($_SERVER["HTTPS"] == "on") {$pageURL .= "s";}
        $pageURL .= "://";
        if ($_SERVER["SERVER_PORT"] != "80") {
            $pageURL .= $_SERVER["SERVER_NAME"].":".$_SERVER["SERVER_PORT"].$_SERVER["REQUEST_URI"];
        } else {
            $pageURL .= $_SERVER["SERVER_NAME"].$_SERVER["REQUEST_URI"];
        }
        return $pageURL;
    }
    
    public function onKernelTerminate(PostResponseEvent $event)
    {
        if( !self::isEnabled() )
        {
            return;
        }
        $data = array ();
        
        $data ["@type"] = "ezpublish";
        $data ["@tags"] = array (
            'ezpublish'
        );
        $argvtmp = $argv;
        $script = array_shift($argvtmp);

        if( php_sapi_name() == 'cli' )
        {
            $data ["@source"] = getcwd() . $script;
            $data ["@tags"] = array (
                'cli'
            );
            $data ["@fields"]['arguments'] = $argvtmp;
        }
        else 
        {
            $data ["@source"] = self::getURL();
            $data ["@tags"] = array (
                'http'
            );
            $data ["@fields"]['cookie'] = $_COOKIE;
            $data ["@fields"]['get'] = $_GET;
            $data ["@fields"]['post'] = $_POST;
            if( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) )
            {
                $logStringAkamai = ' FORWARDED_FOR_IP: ' . $_SERVER['HTTP_X_FORWARDED_FOR'];
            }
            $data ["@fields"]['remote_addr'] = $_SERVER['REMOTE_ADDR'];
            $data ["@fields"]['request_method'] = $_SERVER['REQUEST_METHOD'];
            $data ["@fields"]['http_referrer'] = $_SERVER['REQUEST_METHOD'];
            $data ["@fields"]['http_user_agent'] = $_SERVER['REQUEST_METHOD'];
        }
        $data ["@fields"]['userid'] = "14";
        $data ["@fields"]['username'] = "14";
        $data ["@fields"]['session'] = "14";
        $data ["@fields"]['remote_user'] = "14";
        $data ["@fields"]['body_bytes_sent'] = "14";
        $data ["@fields"]['status'] = "14";
        $data ["@fields"]['userid'] = "14";

        if ( function_exists( 'posix_uname' ) )
        {
            $data ["@source_host"] = posix_uname();
        }
        $date = isset( $_SERVER['REQUEST_TIME'] ) ? new \DateTime( '@' . (int)$_SERVER['REQUEST_TIME'], new \DateTimeZone('UTC') ) : new DateTime('now', new \DateTimeZone('UTC'));
        $data ["@timestamp"] =  $date->format( \DateTime::W3C );

        foreach( headers_list() as $header)
        {
            list($key, $value) = split( ": ", $header);
            $data ["@fields"] ['http_response_header'][$key] = $value;
        }
        if ( function_exists('http_response_code') )
        {
            $data ["@fields"] ['http_response_code'] = http_response_code();
        }
        
        foreach (getallheaders() as $key => $value)
        {
            $data ["@fields"] ['http_request_header'][$key] = $value;
        }
        if ( $this->exception )
        {
            $data ["@fields"] ['http_request_header'] = $this->exception->getTrace();
            $data ["@tags"][] = 'exception';
            $data ["@tags"][] = 'error';
        }
        
        
        //$data = xhprof_disable();
        $data ["@fields"] ['profiling'] = xhprof_sample_disable();
        $file = fopen ( "../ezpublish/logs/logstash.log", "a" );
        
        if ( $file )
        {
            fwrite ( $file, json_encode ( $data ) . "\n" );
            fclose ( $file );
        }
    }
    
    public static function getSubscribedEvents()
    {
        return array(
            KernelEvents::REQUEST => array('onKernelRequest', 1025),
            KernelEvents::RESPONSE => array('onKernelResponse', 0),
            KernelEvents::TERMINATE => array('onKernelTerminate', -1024),
        );
    }
}

/** Example Structure


$data ["@fields"] ['sql'] = array (
		"SELECT * from BLA" => array( 	"time" => "Mon, 06 Jan 2014 15:10:26 GMT" , 	"duration" => 1231, "result" => 123, "success" => 1 ),
		"DELTE FROM ALL WHERE 1" => array( 	"time" => "Mon, 06 Jan 2014 15:10:26 GMT" , 	"duration" => 1231, "result" => 123, "success" => 1 ),
);
*/
