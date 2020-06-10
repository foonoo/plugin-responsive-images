<?php

namespace foonoo\plugins\contrib\responsive_images;

use clearice\io\Io;
use ntentan\utils\exceptions\FileAlreadyExistsException;
use ntentan\utils\Filesystem;
use foonoo\events\PageOutputGenerated;
use foonoo\events\PageWriteStarted;
use foonoo\events\PluginsInitialized;
use foonoo\events\SiteWriteStarted;
use foonoo\events\ThemeLoaded;
use foonoo\Plugin;
use foonoo\sites\AbstractSite;


class ResponsiveImagesPlugin extends Plugin
{
    private $templateEngine;
    private $site;
    private $page;

    public function getEvents()
    {
        return [
            PluginsInitialized::class => [$this, 'registerParser'],
            ThemeLoaded::class => [$this, 'registerTemplates'],
            PageOutputGenerated::class => [$this, 'processMarkup'],
            SiteWriteStarted::class => [$this, 'setActiveSite'],
            PageWriteStarted::class => [$this, 'setActivePage']
        ];
    }

    public function setActiveSite(SiteWriteStarted $event)
    {
        $this->site = $event->getSite();
        $this->makeImageDirectory($this->site);
    }

    public function setActivePage(PageWriteStarted $event)
    {
        $this->page = $event->getContent();
    }

    public function processMarkup(PageOutputGenerated $event)
    {
        try {
            $dom = $event->getDOM();
        } catch (\TypeError $error) {
            return;
        }
        $xpath = new \DOMXPath($dom);
        $imgs = $xpath->query("//img[@fn-responsive]");

        if($imgs->length == 0) {
            return;
        }

        $site = $event->getSite();
        $page = $event->getPage();
        $this->makeImageDirectory($site);

        /** @var $img \DOMNode */
        foreach ($imgs as $img) {
            $sitePath = $site->getTemplateData($site->getDestinationPath($page->getDestination()))['site_path'];
            $src = $img->attributes->getNamedItem("src");
            $alt = $img->attributes->getNamedItem("alt");

            if(!$src) {
                $this->errOut("src attribute of <img> tag cannot be empty on page targeted for \"{$page->getDestination()}\"\n", Io::OUTPUT_LEVEL_1);
                continue;
            }

            $src = substr($src->nodeValue, strlen($sitePath));
            $markup = $this->generateResponsiveImageMarkup($page, $src, $alt ? $alt->nodeValue : "");
            $newDom = new \DOMDocument();
            @$newDom->loadHTML($markup);
            $pictureElement = $newDom->getElementsByTagName('picture');

            if($pictureElement->length > 0) {
                $pictureElement = $pictureElement->item(0);
                $pictureElement = $dom->importNode($pictureElement, true);
            } else {
                $pictureElement = $dom->createTextNode($markup);
            }

            $img->parentNode->replaceChild($pictureElement, $img);
        }
    }

    public function registerParser(PluginsInitialized $event)
    {
        $event->getTagParser()->registerTag(
            "/(?<image>.*\.(jpeg|jpg|png|gif|webp))\s*(\|'?(?<alt>[a-zA-Z0-9 ,.-]*)'?)?/",
            0, [$this, 'getMarkupGenerator']
        );
    }

    public function registerTemplates(ThemeLoaded $event)
    {
        $this->templateEngine = $event->getTemplateEngine();
        $this->templateEngine->prependPath(__DIR__ . "/templates");
    }

    private function generateLinearSteppedImages($site, $image)
    {
        $sizes = [];

        $width = $image->getImageWidth();
        $aspect = $width / $image->getImageHeight();
        $min = $this->getOption('min_width', 300);
        $max = $this->getOption('max_width', $width);
        $step = ($max - $min) / $this->getOption('num_steps', 5);
        $halfStep = $step / 2;
        $lenSourcePath = strlen($site->getSourcePath(""));
        
        for ($i = $max; $i >= $min; $i -= $step) {
            $size = round($i);
            $jpegs = [];
            $webps = [];
            $jpeg = substr($this->writeImage($site, $image, $size, 'jpeg', $aspect), $lenSourcePath);
            $jpegs[]= [$jpeg];
            $webps[]= [substr($this->writeImage($site, $image, $size, 'webp', $aspect), $lenSourcePath)];

            if($this->getOption('hidpi', false) && $size * 2 < $max) {
                $jpegs[]= [substr($this->writeImage($site, $image, $size * 2, 'jpeg', $aspect), $lenSourcePath), 2];
                $webps[]= [substr($this->writeImage($site, $image, $size * 2, 'webp', $aspect), $lenSourcePath), 2];    
            }
            $sizes[] = ['jpeg_srcset' => $jpegs, 'webp_srcset' => $webps, 'min_width' => $size - $halfStep];
        }

        return [$sizes, $jpeg];
    }

    private function makeImageDirectory(AbstractSite $site) : void
    {
        $outputDir = Filesystem::directory($site->getSourcePath($this->getOption('image_path', 'np_images/responsive_images/')));
        try{
            $outputDir->create();
        } catch(FileAlreadyExistsException $e) {

        }
    }

    private function generateResponsiveImageMarkup($page, $imagePath, $alt)
    {
        $site = $this->site;
        return $site->getCache()->get("responsive-image:$imagePath:$alt:{$page->getDestination()}",
            function() use($site, $page, $imagePath, $alt) {
                $filename = $site->getSourcePath($imagePath); 

                if(!\file_exists($filename)) {
                    $this->errOut("File {$filename} does not exist.\n");
                    return "Responsive Image Plugin: File [{$filename}] does not exist.";
                }
        
                $image = new \Imagick($filename);
                $this->stdOut("Generating responsive images for {$filename}\n", Io::OUTPUT_LEVEL_1);
                $templateVariables = $site->getTemplateData($page->getFullDestination());
                list($sources, $defaultImage) = $this->generateLinearSteppedImages($site, $image);

                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if($extension == 'jpeg' || $extension == 'jpg') {
                    $defaultImage = $imagePath;
                }

                $args = [
                    'sources' => $sources,
                    'image_path' => $defaultImage, 'alt' => $alt,
                    'site_path' => $templateVariables['site_path']
                ];
        
                return $this->templateEngine->render('responsive_images', $args);    
            },
            file_exists($imagePath) ? filemtime($imagePath) : 0
        );
    }

    /**
     * @param $matches
     * @return string
     */
    public function getMarkupGenerator($matches)
    {
        return $this->generateResponsiveImageMarkup($this->page, "np_images/{$matches['image']}", $matches['alt']);
    }

    /**
     * @param $site
     * @param $image
     * @param $width
     * @param $format
     * @param $aspect
     * @return string
     */
    private function writeImage($site, $image, $width, $format, $aspect)
    {
        $filename = substr($image->getImageFilename(), strlen($site->getSourcePath("np_images")) + 1);
        $filename = $site->getSourcePath(
            $this->getOption('image_path', 'np_images/responsive_images/') .
            str_replace("/", "-", $filename) . "@{$width}px.$format"
        );
        if(file_exists($filename) && filemtime($image->getImageFilename()) < filemtime($filename)) {
            return $filename;
        }
        $image = $image->clone();
        $width = round($width);
        $image->scaleImage($width, $width / $aspect);
        $image->setImageCompressionQuality($this->getOption("compression_quality", 75));
        $this->stdOut("Writing image $filename\n");
        $image->writeImage($filename);

        return $filename;
    }
}
