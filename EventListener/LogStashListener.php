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

class LogStashListener implements EventSubscriberInterface
{

    const DISABLED = 1;

    const ENABLED = 2;

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
            if (strpos($_SERVER["REQUEST_URI"], '/_') !== 0) {
                // xhprof_enable(XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY);
                xhprof_sample_enable();
                $this->profiler = self::ENABLED;
            } else {
                $this->profiler = self::DISABLED;
            }
        }
        $this->data["@tags"] = array(
            'ezpublish'
        );
        $date = isset($_SERVER['REQUEST_TIME']) ? new \DateTime('@' . (int) $_SERVER['REQUEST_TIME'], new \DateTimeZone('UTC')) : new DateTime('now', new \DateTimeZone('UTC'));
        $this->data["@timestamp"] = $date->format(\DateTime::W3C);
    }

    public function onKernelException(GetResponseForExceptionEvent $event)
    {
        if ($this->onlyMasterRequests && ! $event->isMasterRequest()) {
            return;
        }
        $this->data["@tags"][] = 'exception';
        $this->data["@tags"][] = 'error';
        $this->data["@fields"]['trace'] = $event->getException()->getTrace();
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
        
        $this->data["@fields"]['body_bytes_sent'] = 0;
        $data["@fields"]['status'] = 1;

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

    static function getURL()
    {
        if ($_SERVER["SERVER_PORT"] != "80") {
            $pageURL .= self::serverProtocol() . "://" . $_SERVER["SERVER_NAME"] . ":" . $_SERVER["SERVER_PORT"] . $_SERVER["REQUEST_URI"];
        } else {
            $pageURL .= self::serverProtocol() . "://" . $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"];
        }
        return $pageURL;
    }
    static function serverProtocol()
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
        
        $data = $this->data;
        
        $data["@type"] = "ezpublish";
        
        $data["@fields"]['environment'] = $this->container->get('kernel')->getEnvironment();
        $argvtmp = $argv;
        $script = array_shift($argvtmp);
        
        if (php_sapi_name() == 'cli') {
            $data["@source"] = getcwd() . $script;
            $data["@tags"] = array(
                'cli'
            );
            $data["@fields"]['arguments'] = $argvtmp;
        } else {
            $data["@source"] = self::getURL();
            $data["@tags"] = array(
                'http'
            );
            $data["@fields"]['cookie'] = $_COOKIE;
            $data["@fields"]['get'] = $_GET;
            $data["@fields"]['post'] = $_POST;
            if (isset($_SERVER['SERVER_ADDR'])) {
                $data["@fields"]['server_addr'] = $_SERVER['SERVER_ADDR'];
            }
            $data["@fields"]['remote_addr'] = $_SERVER['REMOTE_ADDR'];
            if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $data["@fields"]['remote_addr'] = $_SERVER['HTTP_X_FORWARDED_FOR'];
            }
            $data["@fields"]['request_method'] = $_SERVER['REQUEST_METHOD'];
            $data["@fields"]['http_referrer'] = $_SERVER['HTTP_REFERER'];
            $data["@fields"]['http_user_agent'] = $_SERVER['HTTP_USER_AGENT'];
        }

        /** @var $session \Symfony\Component\HttpFoundation\Session\Session */
        $session = $this->container->get( 'session' );
        /** @var $authenticationToken \Symfony\Component\Security\Core\Authentication\Token\TokenInterface */
        $authenticationToken = $this->container->get( 'security.context' )->getToken();
        
        if ( $session->isStarted() )
        {
            $data["@fields"]['session'] = $session->getId();
        }
        
        if( $session->isStarted() && $authenticationToken !== null )
        {
            $user = $authenticationToken->getUser();
            $data["@fields"]['groups'] = $user->getRoles();
            $data["@fields"]['username'] = $user->getUsername();
        }

        if (isset($_SERVER['PHP_AUTH_USER'])) {
            $data["@fields"]['http_auth_user'] = $_SERVER['PHP_AUTH_USER'];
        }
        
        if (function_exists('posix_uname')) {
            $data["@source_host"] = posix_uname();
        }
        
        foreach (headers_list() as $header) {
            list ($key, $value) = split(": ", $header);
            $data["@fields"]['http_response_header'][$key] = $value;
        }
        if (function_exists('http_response_code')) {
            $data["@fields"]['http_response_code'] = http_response_code();
        }
        foreach (getallheaders() as $key => $value) {
            $data["@fields"]['http_request_header'][$key] = $value;
        }
        
        
        // $data = xhprof_disable();
        $data["@fields"]['profiling'] = xhprof_sample_disable();
        $file = fopen("../ezpublish/logs/logstash.log", "a");
        
        if ($file) {
            fwrite($file, json_encode($data) . "\n");
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


$data ["@fields"] ['sql'] = array (
		"SELECT * from BLA" => array( 	"time" => "Mon, 06 Jan 2014 15:10:26 GMT" , 	"duration" => 1231, "result" => 123, "success" => 1 ),
		"DELTE FROM ALL WHERE 1" => array( 	"time" => "Mon, 06 Jan 2014 15:10:26 GMT" , 	"duration" => 1231, "result" => 123, "success" => 1 ),
);
*/
