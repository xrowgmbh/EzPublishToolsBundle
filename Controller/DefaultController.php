<?php

namespace xrow\Bundle\EzPublishToolsBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;


class DefaultController extends Controller
{
    /** test that worked with symfony 2.4
     * @Route("/hello/{name}")
     * @Template()
     */
    public function indexAction($name)
    {
        return array('name' => $name);
    }
    /**
     * @Route("/_profiling/{name}")
     * @Template()
     */
    public function profilingAction($name)
    {
        
        $dir = $this->container->get('kernel')->getRootDir() . "/logs/profiling";
        return $this->container->get('templating')->renderResponse('xrowBundleEzPublishToolsBundle:Default:profiling.html.twig', array(
            'data'  => unserialize( file_get_contents( $dir . "/" . basename($name)))
        ));
    }
}