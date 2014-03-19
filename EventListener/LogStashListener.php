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

    private $profiling = null;

    private $exception = null;

    private $userid = null;

    private $username = null;

    private $data = array();

    private $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;

        if ($this->container->getParameter('ezpublishtools.profiling') and function_exists('xhprof_enable') and $this->profiler === null) {
            xhprof_enable(XHPROF_FLAGS_NO_BUILTINS + XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY);
            // xhprof_sample_enable();
            $this->profiling = self::ENABLED;
        } else {
            $this->profiling = self::DISABLED;
        }
        $this->data["tags"] = array(
            'ezpublish'
        );
    }

    private function getDuration()
    {}

    public function onKernelException(GetResponseForExceptionEvent $event)
    {
        $this->data["tags"][] = 'exception';
        $this->data["tags"][] = 'error';
        $this->data['trace'] = $event->getException()->getTrace();
    }

    public function isProfilingEnabled()
    {
        return self::DISABLED !== $this->profiling;
    }

    public function onKernelRequest(GetResponseEvent $event)
    {
        if (HttpKernelInterface::MASTER_REQUEST !== $event->getRequestType()) {
            return;
        }
    }

    public function onKernelResponse(FilterResponseEvent $event)
    {
        $this->data['status'] = 1;
        if (HttpKernelInterface::MASTER_REQUEST == $event->getRequestType()) {
            $this->data['request.master'] = 1;
        }
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
        if (self::isSSL())
            return 'https';
        else
            return 'http';
    }

    static function convertMicroSecondsFloatToDatetime($time)
    {
        $micro_time = sprintf("%06d", ($time - floor($time)) * 1000000);
        return new \DateTime(date('Y-m-d H:i:s.' . $micro_time, $time));
    }

    public function onKernelTerminate(PostResponseEvent $event)
    {
        if (!$this->container->getParameter('ezpublishtools.logstash')) {
            return;
        }
        $this->data["type"] = "ezpublish";
        $this->data["version"] = "1";
        
        $this->data['ezpublish.environment'] = $this->container->get('kernel')->getEnvironment();
        $profiler = $this->container->get('profiler');
        $start = self::convertMicroSecondsFloatToDatetime($profiler->get('time')->getStartTime() / 1000);
        
        $this->data["@timestamp"] = $start->format(\DateTime::W3C);
        
        
        $this->data['ezpublish.stash.calls'] = $profiler->get('stash')->getCalls();

        $this->data['ezpublish.spi.call_count'] = $profiler->get('ezpublish.spi.persistence')->getCount();
        
        $this->data['ezpublish.spi.calls'] = array();
        if ( $this->data['ezpublish.spi.call_count'] > 0)
        {
            foreach( $profiler->get('ezpublish.spi.persistence')->getCalls() as $call )
            {
                $this->data['ezpublish.spi.calls'][] = $call['class'] . '::' . $call['method'];
            }
            $this->data['ezpublish.spi.calls'] = array_values(array_unique( $this->data['ezpublish.spi.calls'] ));
        }

        // @TODO find the Destination
        $request = $event->getRequest();
        $this->data['ezpublish.siteaccess'] = $request->get('siteaccess');
        $this->data['ezpublish.route.name'] = $request->get('_route');
        $this->data['ezpublish.controller'] = $request->get('_controller');
        $this->data['ezpublish.route.params'] = $request->get('_route_params');

        $response = $event->getResponse();

        //Get Events
        $profile = $profiler->loadProfileFromResponse( $response );
        if( $profile ){
            // In newer versoins use lateCollect? https://github.com/symfony/symfony/commit/5cedea2c07b581678cc6ecd87de28030747fbc07
            $this->data['collector.events'] = array();
            foreach( $profile->getCollector('events')->getCalledListeners() as $calledevent )
            {
                $this->data['collector.events'][] = $calledevent['event'];
            
            }
            $this->data['collector.events'] = array_values( array_unique($this->data['collector.events']) );
        }


        if (php_sapi_name() == 'cli') {
            $argvtmp = $GLOBALS['argv'];
            $script = array_shift($argvtmp);
            $this->data["source"] = getcwd() . $script;
            $this->data["@message"] = getcwd() . $script;
            $this->data["tags"][] = array(
                'cli'
            );
            $this->data['arguments'] = $argvtmp;
        } else {
            $this->data["source"] = self::getURL();
            $this->data["@message"] = self::getURL();
            $this->data["tags"][] = array(
                'http'
            );
            
            $this->data['request.cookie'] = $_COOKIE;
            $this->data['request.get'] = $_GET;
            $this->data['request.post'] = $_POST;
            $protectkeys = array(
                'password',
                'Password'
            );
            foreach ($protectkeys as $key) {
                if (isset($this->data['get'][$key])) {
                    unset($this->data['get']);
                }
                if (isset($this->data['post'][$key])) {
                    unset($this->data['post']);
                }
            }
            if (isset($_SERVER['SERVER_ADDR'])) {
                $this->data['server.addr'] = $_SERVER['SERVER_ADDR'];
            }
            $this->data['remote.addr'] = $_SERVER['REMOTE_ADDR'];
            if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $this->data['remote.addr'] = $_SERVER['HTTP_X_FORWARDED_FOR'];
            }
            $this->data['request.method'] = $_SERVER['REQUEST_METHOD'];
            if (isset($_SERVER['HTTP_REFERER'])) {
                $this->data['referrer'] = $_SERVER['HTTP_REFERER'];
            }
            $type = explode(";", $response->headers->get('Content-Type'));
            $this->data['content.type'] = $type[0];
            $this->data['content.bytes'] = strlen($event->getResponse()->getContent());
            try {
                $parser = \UAParser\Parser::create();

                $result = $parser->parse( $_SERVER['HTTP_USER_AGENT'] );
                $this->data['agent.os.name'] = $result->os->family;
                $this->data['agent.os.version'] = $result->os->toVersion();
                $this->data['agent.browser.name'] = $result->ua->family;
                $this->data['agent.browser.version'] = $result->ua->toVersion();
                $this->data['agent.device.name'] = $result->device->family;
            } catch (Exception $e) {
                $logger = $this->container->get('logger');
                $logger->info('UAParser not available. ' . $e->toString() );
            }
        }
        
        /**
         * @var $session \Symfony\Component\HttpFoundation\Session\Session
         */
        $session = $this->container->get('session');
        /**
         * @var $authenticationToken \Symfony\Component\Security\Core\Authentication\Token\TokenInterface
         */        
        $authenticationToken = $this->container->get( 'security.context' )->getToken();
        if ($session->isStarted()) {
            $this->data['ezpublish']['session'] = $session->getId();
        }
        
        if ($session->isStarted() && $authenticationToken !== null) {
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
            
            list ($key, $value) = explode(": ", $header);
            if (in_array($key, array(
                'X-ChromeLogger-Data'
            ))) {
                continue;
            }
            $this->data['response.header'][$key] = $value;
        }
        
        
        if (function_exists('http_response_code')) {
            $this->data['http_response_code'] = http_response_code();
        } else {
            $this->data['response.code'] = $event->getResponse()->getStatusCode();
        }

        
        foreach (getallheaders() as $key => $value) {
            $this->data['request.header'][$key] = $value;
        }
        
        if ($this->isProfilingEnabled()) {
            $dir = $container->get('kernel')->getRootDir() . "/logs/profiling";
            $data = xhprof_disable();
            $fs = new Filesystem();
            if (! $fs->exists($dir)) {
                $fs->mkdir($dir, 0777);
            }
            $xhprof = new \XHProfRuns_Default($dir);
            $run_id = $xhprof->save_run($data, self::TYPE);
            $this->data['profiling'] = self::getProtocol() . "://" . self::getHost() . "/_profiling/" . $run_id . "." . self::TYPE . ".xhprof";
        }
        if ( $profile )
        {
            $this->data['ezpublish.memory.peak'] = $profile->getCollector('memory')->updateMemoryUsage();
            $this->data["duration"]  = (int) $profile->getCollector('time')->getDuration();
        }

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
                - 120
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
