<?php

namespace nyansapow\plugins\contrib\responsive_images;

use clearice\io\Io;
use ntentan\utils\exceptions\FileAlreadyExistsException;
use ntentan\utils\exceptions\FileNotWriteableException;
use ntentan\utils\Filesystem;
use nyansapow\events\PageOutputGenerated;
use nyansapow\events\PluginsInitialized;
use nyansapow\events\ThemeLoaded;
use nyansapow\Plugin;
use nyansapow\sites\AbstractSite;


class ResponsiveImagesPlugin extends Plugin
{
    private $templateEngine;

    public function getEvents()
    {
        return [
            PluginsInitialized::class => [$this, 'registerParser'],
            ThemeLoaded::class => [$this, 'registerTemplates'],
            PageOutputGenerated::class => [$this, 'processMarkup']
        ];
    }

    public function processMarkup(PageOutputGenerated $event)
    {
        $content = $event->getOutput();
        $dom = new \DOMDocument();
        @$dom->loadHTML($content);
        $xpath = new \DOMXPath($dom);
        $imgs = $xpath->query("//img[@fn-responsive]");

        if($imgs->length == 0) {
            return;
        }

        $site = $event->getSite();
        $page = $event->getPage();
        $this->makeImageDirectory($site);
        $altered = false;

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
            $markup = $this->generateResponsiveImageMarkup($site, $page, $src, $alt ? $alt->nodeValue : "");
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
            $altered = true;
        }

        $event->setOutput($altered ? $dom->saveHTML() : $content);
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
        
        for ($i = $min; $i <= $max; $i += $step) {
            $jpeg = $this->writeImage($site, $image, $i, 'jpeg', $aspect);
            $jpeg = substr($jpeg, strlen($site->getSourcePath("")));
            $webp = $this->writeImage($site, $image, $i, 'webp', $aspect);
            $webp = substr($webp, strlen($site->getSourcePath("")));
            $sizes[] = ['src_jpeg' => $jpeg, 'src_webp' => $webp, 'max_width' => $i];
        }

        return $sizes;
    }

    private function makeImageDirectory(AbstractSite $site) : void
    {
        $outputDir = Filesystem::directory($site->getSourcePath($this->getOption('image_path', 'np_images/responsive_images/')));
        try{
            $outputDir->create();
        } catch(FileAlreadyExistsException $e) {

        }
    }

    private function generateResponsiveImageMarkup($site, $page, $image, $alt)
    {
        $filename = $site->getSourcePath($image); //"np_images/{$matches['image']}");

        if(!\file_exists($filename)) {
            $this->errOut("File {$filename} does not exist.\n");
            return "Responsive Image Plugin: File [{$filename}] does not exist.";
        }

        $image = new \Imagick($filename);
        $templateVariables = $site->getTemplateData($site->getDestinationPath($page->getDestination()));
        $args = [
            'sources' => $this->generateLinearSteppedImages($site, $image),
            'image_path' => $image, 'alt' => $alt,
            'site_path' => $templateVariables['site_path']
        ];

        return $this->templateEngine->render('responsive_images', $args);

    }

    /**
     * @param AbstractSite $site
     * @param $page
     * @return \Closure
     * @throws FileNotWriteableException
     */
    public function getMarkupGenerator($site, $page)
    {
        $this->makeImageDirectory($site);
        return function ($matches) use ($site, $page) {
            return $this->generateResponsiveImageMarkup($site, $page, "np_images/{$matches['image']}", $matches['alt']);
        };
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
        $image = $image->clone();
        $width = round($width);
        $filename = $site->getSourcePath(
            $this->getOption('image_path', 'np_images/responsive_images/') .
            str_replace("/", "-", $filename) . "@{$width}px.$format"
        );
        if(file_exists($filename) && filemtime($image->getImageFilename()) < filemtime($filename)) {
            return $filename;
        }
        $image->scaleImage($width, $width / $aspect);
        $image->setImageCompressionQuality(90);
        $this->stdOut("Writing image $filename\n");
        $image->writeImage($filename);

        return $filename;
    }
}
