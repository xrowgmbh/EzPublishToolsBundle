<?php
namespace xrow\Bundle\EzpublishToolsBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\File\File;

use xrow\Bundle\EzpublishToolsBundle\SVG\IconFontGenerator;

class BuildFontCommand extends Command
{

    protected function configure()
    {
        $this->setName('tools:buildfont')
            ->setDescription('Build a webfont from a directory')
            ->addArgument('path', InputArgument::REQUIRED, 'Directory where all svg icons reside')
            ->addArgument('destination', InputArgument::OPTIONAL, 'Directory where the generated fonts will be placed');;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $directory = $input->getArgument('path');
        if (! is_dir($directory)) {
        throw new \Exception("Path $path isn't a directory.");
        }
        
        $destination = realpath( $input->getArgument('destination') );

        if ( $input->getArgument('destination') and !is_dir( $destination ) )
        {
            throw new \Exception("Destination $path isn't a directory.");
        }
        $basename = 'icon-font';
        $outputFile = realpath($directory . "../".$basename.".svg");
        $generator = new IconFontGenerator();
        
        $output->writeln('reading files from "' . $directory . '" ...');
        
        $generator->generateFromDir($directory, array(), true);
        
        $ffscript = realpath(dirname(__FILE__) . "/../SVG/woff.pe");
        
        file_put_contents($outputFile, $generator->getFont()->getXML());
        $cmd = "fontforge -script " . $ffscript . " " . $outputFile;
        $output->writeln($cmd);
        $retval = null;
        system("fontforge -script " . $ffscript . " " . $outputFile, $retval);
        if ($retval !== 0) {
            throw new \Exception("Error creating fonts with fontforge");
        }
        $files[] = array();
        $files[] = new File( $outputFile );
        $files[] = new File( dirname( $outputFile ). "/" . $basename . ".ttf" );
        $files[] = new File( dirname( $outputFile ). "/" . $basename . ".eot" );
        $files[] = new File( dirname( $outputFile ). "/" . $basename . ".woff" );

        if ( $destination )
        {
            foreach ( $files as $file )
            {
                $file->move( $input->getArgument('destination') );
            }
        }
        
        $output->writeln('created fonts successfully');
    }
}