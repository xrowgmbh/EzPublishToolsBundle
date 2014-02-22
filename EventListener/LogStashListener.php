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
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Filesystem\Filesystem;

class LogStashListener implements EventSubscriberInterface
{

    const DISABLED = 1;

    const ENABLED = 2;
    const TYPE = 'ezpublish';
    private $dir = null;
    private $profiler = null;

    private $exception = null;

    private $userid = null;

    private $username = null;

    private $data = array();

    private $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        if (function_exists('xhprof_enable') and $this->profiler === null) {
            xhprof_enable(XHPROF_FLAGS_NO_BUILTINS + XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY);
            //xhprof_sample_enable();
            $this->profiler = self::ENABLED;
        }
        $this->data["tags"] = array(
            'ezpublish'
        );

        //@TODO Do the timezone right. #, new \DateTimeZone( date_default_timezone_get() ) , new \DateTimeZone( date_default_timezone_get() )
        $date = isset($_SERVER['REQUEST_TIME']) ? new \DateTime('@' . (int) $_SERVER['REQUEST_TIME'] ) : new DateTime('now', new \DateTimeZone( date_default_timezone_get() ));
        $this->data["@timestamp"] = $date->format(\DateTime::W3C);

        $this->dir = $container->get('kernel')->getRootDir() . "/logs/profiling";  
    }

    public function onKernelException(GetResponseForExceptionEvent $event)
    {
        $this->data["tags"][] = 'exception';
        $this->data["tags"][] = 'error';
        $this->data['trace'] = $event->getException()->getTrace();
    }

    public function isEnabled()
    {
        return self::DISABLED !== $this->profiler;
    }

    public function onKernelRequest(GetResponseEvent $event)
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
        $this->data['status'] = 1;

    }

    static function isSSL()
    {
        $nowSSL = false;
        if (isset($_SERVER['HTTPS'])) {
            $nowSSL = ($_SERVER['HTTPS'] == 'on');
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $nowSSL = ($_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https');
        } else 
            if (isset($_SERVER['HTTP_X_FORWARDED_PORT'])) {
                $nowSSL = ($_SERVER['HTTP_X_FORWARDED_PORT'] == '443');
            }
        return $nowSSL;
    }
    static function getHost()
    {
        if ($_SERVER["SERVER_PORT"] != "80") {
            $pageURL = $_SERVER["SERVER_NAME"] . ":" . $_SERVER["SERVER_PORT"];
        } else {
            $pageURL = $_SERVER["SERVER_NAME"];
        }
        return $pageURL;
    }
    static function getURL()
    {
        return self::getProtocol() . "://" . self::getHost() . $_SERVER["REQUEST_URI"];
    }
    static function getProtocol()
    {
        if ( self::isSSL() )
            return 'https';
        else
            return 'http';
    }
    public function onKernelTerminate(PostResponseEvent $event)
    {
        if (! self::isEnabled()) {
            return;
        }
        $this->data["type"] = "ezpublish";
        $this->data["version"] = "1";

        $this->data['environment'] = $this->container->get('kernel')->getEnvironment();

        if (php_sapi_name() == 'cli') {
            $argvtmp = $GLOBALS['argv'];
            $script = array_shift($argvtmp);
            $this->data["source"] = getcwd() . $script;
            $this->data["tags"][] = array(
                'cli'
            );
            $this->data['arguments'] = $argvtmp;
        } else {
            $this->data["source"] = self::getURL();
            $this->data["message"] = self::getURL();
            $this->data["tags"][] = array(
                'http'
            );
            
            $this->data['cookie'] = $_COOKIE;
            $this->data['get'] = $_GET;
            $this->data['post'] = $_POST;
            $protectkeys = array( 'password', 'Password' );
            foreach ( $protectkeys as $key )
            {
                if (isset( $this->data['get'][$key] ))
                {
                     unset($this->data['get']);
                }
                if (isset( $this->data['post'][$key] ))
                {
                    unset($this->data['post']);
                }
            }
            if (isset($_SERVER['SERVER_ADDR'])) {
                $data['server_addr'] = $_SERVER['SERVER_ADDR'];
            }
            $this->data['remote_addr'] = $_SERVER['REMOTE_ADDR'];
            if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $this->data['remote_addr'] = $_SERVER['HTTP_X_FORWARDED_FOR'];
            }
            $this->data['request_method'] = $_SERVER['REQUEST_METHOD'];
            if( isset($_SERVER['HTTP_REFERER']) )
            {
                $this->data['http_referrer'] = $_SERVER['HTTP_REFERER'];
            }
            
            $this->data['agent'] = $_SERVER['HTTP_USER_AGENT'];
            
        }
        
        /** @var $session \Symfony\Component\HttpFoundation\Session\Session */
        $session = $this->container->get( 'session' );
        /** @var $authenticationToken \Symfony\Component\Security\Core\Authentication\Token\TokenInterface */
        $authenticationToken = $this->container->get( 'security.context' )->getToken();
        
        if ( $session->isStarted() )
        {
            $this->data['ezpublish']['session'] = $session->getId();
        }
        
        if( $session->isStarted() && $authenticationToken !== null )
        {
            $user = $authenticationToken->getUser();
            $this->data['ezpublish']['groups'] = $user->getRoles();
            $this->data['ezpublish']['username'] = $user->getUsername();
        }

        if (isset($_SERVER['PHP_AUTH_USER'])) {
            $this->data['http_auth_user'] = $_SERVER['PHP_AUTH_USER'];
        }
        
        if (function_exists('posix_uname')) {
            $nodeinfo = posix_uname();
            $this->data["source_host"] = $nodeinfo['nodename'];
        }
        
        foreach (headers_list() as $header) {

            list ($key, $value) = split(": ", $header);
            if ( in_array( $key , array( 'X-ChromeLogger-Data' ) ) )
            {
                 continue;
            }
            $this->data['http_response_header'][$key] = $value;
        }

        if (function_exists('http_response_code')) {
            $this->data['http_response_code'] = http_response_code();
        }
        else
        {
            $this->data['http_response_code'] = $event->getResponse()->getStatusCode();
        }
        $this->data['body_bytes_sent'] = strlen($event->getResponse()->getContent());
        
        foreach (getallheaders() as $key => $value)
        {
            $this->data['http_request_header'][$key] = $value;
        }

        $data = xhprof_disable();
        $fs = new Filesystem();
        if ( !$fs->exists( $this->dir ) )
        {
            $fs->mkdir( $this->dir, 0777 );
        }
        $xhprof = new \XHProfRuns_Default( $this->dir );
        $run_id = $xhprof->save_run($data , self::TYPE);
        $this->data['profiling'] = self::getProtocol() . "://" .  self::getHost() . "/_profiling/".$run_id.".".self::TYPE.".xhprof";
        $file = fopen("../ezpublish/logs/logstash.log", "a");
        
        if ($file) {
            fwrite($file, json_encode($this->data) . "\n");
            fclose($file);
        }

    }

    public static function getSubscribedEvents()
    {
        return array(
            KernelEvents::REQUEST => array(
                'onKernelRequest',
                1025
            ),
            KernelEvents::EXCEPTION => array(
            'onKernelException',
            -120
            ),
            KernelEvents::RESPONSE => array(
                'onKernelResponse',
                0
            ),
            KernelEvents::TERMINATE => array(
                'onKernelTerminate',
                - 1024
            )
        );
    }
}

/** Example Structure


$data ["fields"] ['sql'] = array (
		"SELECT * from BLA" => array( 	"time" => "Mon, 06 Jan 2014 15:10:26 GMT" , 	"duration" => 1231, "result" => 123, "success" => 1 ),
		"DELTE FROM ALL WHERE 1" => array( 	"time" => "Mon, 06 Jan 2014 15:10:26 GMT" , 	"duration" => 1231, "result" => 123, "success" => 1 ),
);
*/
