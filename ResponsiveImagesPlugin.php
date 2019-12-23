<?php

namespace nyansapow\plugins\contrib\responsive_images;

use ntentan\utils\exceptions\FileAlreadyExistsException;
use ntentan\utils\Filesystem;
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
            ThemeLoaded::class => [$this, 'registerTemplates']
        ];
    }

    public function registerParser(PluginsInitialized $event)
    {
        $event->getTagParser()->registerTag(
            "/(?<image>.*\.(jpeg|jpg|png|gif|webp))\s*(\|'?(?<alt>[a-zA-Z0-9 ,.-]*)'?)?/",
            0, [$this, 'generateResponsiveImageMarkup']
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
            $jpeg = substr($jpeg, strlen($site->getDestinationPath("")));
            $webp = $this->writeImage($site, $image, $i, 'webp', $aspect);
            $webp = substr($webp, strlen($site->getDestinationPath("")));
            $sizes[] = ['src_jpeg' => $jpeg, 'src_webp' => $webp, 'max_width' => $i];
        }

        return $sizes;
    }

//    private function generateExponentialImages($site, $image)
//    {
//        $sizes = [];
//
//        $width = $image->getImageWidth();
//        $aspect = $width / $image->getImageHeight();
//        $min = $this->getOption('min_width', 300);
//        $max = $this->getOption('max_width', $width);
//        $rate = $this->getOption('exponential_rate', 0.8);
//
//        $startX = log($max - $min) / log($rate);
//        $step = (0 - $startX) / $this->getOption('num_steps', 5);
//
//        for ($i = $startX; $i < 0; $i += $step) {
//            $newWidth = $max - pow($rate, $i);
//            $jpeg = $this->writeImage($site, $image, $newWidth, 'jpeg', $aspect);
//            $webp = $this->writeImage($site, $image, $newWidth, 'webp', $aspect);
//            $sizes[] = ['src_jpeg' => $jpeg, 'src_webp' => $webp, 'max_width' => $newWidth];
//        }
//
//    }

    /**
     * @param AbstractSite $site
     * @param $page
     * @return \Closure
     * @throws \ntentan\utils\exceptions\FileNotWriteableException
     */
    public function generateResponsiveImageMarkup($site, $page)
    {
        $dir = Filesystem::directory($this->getOption('image_path', 'np_images/responsive_images/'));
        try{
            $dir->create();
        } catch(FileAlreadyExistsException $e) {

        }
        return function ($matches) use ($site, $page) {
            $filename = $site->getSourcePath("np_images/{$matches['image']}");

            if(!\file_exists($filename)) {
                $this->errOut("File {$filename} does not exist.");
                return "File {$filename} does not exist.";
            }

            $image = new \Imagick($filename);
            $templateVariables = $site->getTemplateData($site->getDestinationPath($page->getDestination()));
            $args = [
                'sources' => $this->generateLinearSteppedImages($site, $image),
                'image_path' => "np_images/{$matches['image']}", 'alt' => $matches['alt'],
                'site_path' => $templateVariables['site_path']
            ];

            return $this->templateEngine->render('responsive_images', $args);
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
        $image->scaleImage($width, $width / $aspect);
        $width = round($width);
        $filename = $site->getDestinationPath(
            $this->getOption('image_path', 'np_images/responsive_images/') .
            str_replace("/", "-", $filename) . "@{$width}px.$format"
        );
        $image->setImageCompressionQuality(90);
        $image->writeImage($filename);

        return $filename;
    }
}
