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
            ->addArgument('source', InputArgument::REQUIRED, 'Directory where all svg icons reside')
            ->addArgument('destination', InputArgument::OPTIONAL, 'Directory where the generated fonts will be placed');
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $directory = $input->getArgument('source');
        if (! is_dir($directory)) {
            throw new \Exception("Source directory $path isn't a directory.");
        }
        
        $destination = realpath($input->getArgument('destination'));
        
        if ($input->getArgument('destination') and ! is_dir($destination)) {
            throw new \Exception("Destination directory $destination isn't a directory.");
        }
        if (! $destination) {
            $destination = realpath(getcwd());
        }
        if (! is_writeable($destination)) {
            throw new \Exception("Destination directory $destination isn't writeable.");
        }
        $basename = 'icon-font';
        
        $generator = new IconFontGenerator();
        $output->writeln('reading .svg icons from "' . $directory . '" ...');
        
        $generator->generateFromDir($directory, array(), true);
        
        $ffscript = realpath(dirname(__FILE__) . "/../SVG/woff.pe");
        
        $svg = $destination . "/" . $basename . ".svg";
        $css = $destination . "/" . $basename . ".css";
        $html = $destination . "/" . $basename . ".html";
        file_put_contents($svg, $generator->getFont()->getXML());
        file_put_contents($css, $generator->getCss());
        file_put_contents($html, $this->getHTMLFromGenerator($generator, $svg));
        
        $cmd = "fontforge -script " . $ffscript . " " . $svg;
        
        $retval = null;
        system($cmd, $retval);
        if ($retval !== 0) {
            throw new \Exception("Error creating fonts with fontforge");
        }
        $files = array();
        $files[] = new File($destination . "/" . $basename . ".svg");
        $files[] = new File($destination . "/" . $basename . ".ttf");
        $files[] = new File($destination . "/" . $basename . ".eot");
        $files[] = new File($destination . "/" . $basename . ".woff");
        $files[] = new File($destination . "/" . $basename . ".css");
        $files[] = new File($destination . "/" . $basename . ".html");
        if ($destination) {
            foreach ($files as $file) {
                $file->move($input->getArgument('destination'));
            }
        }
        foreach ($files as $file) {
            $output->writeln('Created font: ' . $file);
        }
    }

    /**
     * creates the HTML for the info page
     *
     * @param IconFontGenerator $generator
     *            icon font generator
     * @param string $fontFile
     *            font file name
     * @return string HTML for the info page
     */
    protected function getHTMLFromGenerator(IconFontGenerator $generator, $fontFile)
    {
        $fontOptions = $generator->getFont()->getOptions();
        
        $html = '<!doctype html>
			<html>
			<head>
			<title>' . htmlspecialchars($fontOptions['id']) . '</title>
			<style>
				@font-face {
					font-family: "' . $fontOptions['id'] . '";
					src: url("data:image/svg+xml;base64,' . base64_encode(file_get_contents($fontFile)) . '") format("svg");
					font-weight: normal;
					font-style: normal;
				}
				body {
					font-family: sans-serif;
					color: #444;
					line-height: 1.5;
					font-size: 16px;
					padding: 20px;
				}
				* {
					-moz-box-sizing: border-box;
					-webkit-box-sizing: border-box;
					box-sizing: border-box;
					margin: 0;
					paddin: 0;
				}
				.glyph{
					display: inline-block;
					width: 120px;
					margin: 10px;
					text-align: center;
					vertical-align: top;
					background: #eee;
					border-radius: 10px;
					box-shadow: 1px 1px 5px rgba(0, 0, 0, .2);
				}
				.glyph-icon{
					padding: 10px;
					display: block;
					font-family: "' . $fontOptions['id'] . '";
					font-size: 64px;
					line-height: 1;
				}
				.glyph-icon:before{
					content: attr(data-icon);
				}
				.class-name{
					font-size: 12px;
				}
				.glyph > input{
					display: block;
					width: 100px;
					margin: 5px auto;
					text-align: center;
					font-size: 12px;
					cursor: text;
				}
				.glyph > input.icon-input{
					font-family: "' . $fontOptions['id'] . '";
					font-size: 16px;
					margin-bottom: 10px;
				}
			</style>
			</head>
			<body>
			<section id="glyphs">';
        
        $glyphNames = $generator->getGlyphNames();
        asort($glyphNames);
        
        foreach ($glyphNames as $unicode => $glyph) {
            $html .= '<div class="glyph">
				<div class="glyph-icon" data-icon="&#x' . $unicode . ';"></div>
				<div class="class-name">icon-' . $glyph . '</div>
				<input type="text" readonly="readonly" value="&amp;#x' . $unicode . ';" />
				<input type="text" readonly="readonly" value="\\' . $unicode . '" />
				<input type="text" readonly="readonly" value="&#x' . $unicode . ';" class="icon-input" />
			</div>';
        }
        
        $html .= '</section>
			</body>
		</html>';
        
        return $html;
    }

    /**
     * creates a HTML list
     *
     * @param IconFontGenerator $generator
     *            icon font generator
     * @param string $fontFile
     *            font file name
     * @return string HTML unordered list
     */
    protected function getHTMLListFromGenerator(IconFontGenerator $generator, $fontFile)
    {
        $fontOptions = $generator->getFont()->getOptions();
        
        $html = '<ul>';
        
        $glyphNames = $generator->getGlyphNames();
        asort($glyphNames);
        
        foreach ($glyphNames as $unicode => $glyph) {
            $html .= "\n\t" . '<li data-icon="&#x' . $unicode . ';" title="' . htmlspecialchars($glyph) . '">' . htmlspecialchars($glyph) . '</li>';
        }
        
        $html .= "\n" . '</ul>' . "\n";
        
        return $html;
    }
}