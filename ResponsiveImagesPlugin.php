<?php

namespace foonoo\plugins\contrib\responsive_images;

use clearice\io\Io;
use foonoo\content\Content;
use ntentan\utils\exceptions\FileAlreadyExistsException;
use ntentan\utils\Filesystem;
use foonoo\events\ContentOutputGenerated;
use foonoo\events\ContentWriteStarted;
use foonoo\events\PluginsInitialized;
use foonoo\events\SiteWriteStarted;
use foonoo\events\ThemeLoaded;
use foonoo\Plugin;
use foonoo\sites\AbstractSite;


/**
 * Generates responsive image sizes.
 *
 */
class ResponsiveImagesPlugin extends Plugin
{
    private $templateEngine;

    /**
     * @var AbstractSite
     */
    private $site;

    /**
     * @var Content
     */
    private $content;

    public function getEvents()
    {
        return [
            PluginsInitialized::class => function(PluginsInitialized $event) {$this->registerParserTags($event);},
            ThemeLoaded::class => function(ThemeLoaded $event) {$this->registerTemplates($event);},
            ContentOutputGenerated::class => function(ContentOutputGenerated $event) {$this->processMarkup($event);},
            SiteWriteStarted::class => function(SiteWriteStarted $event) {$this->setActiveSite($event);},
            ContentWriteStarted::class => function(ContentWriteStarted $event) {$this->setActiveContent($event);}
        ];
    }

    /**
     * This event handler helps the plugin keep track of the current site being processed.
     *
     * @param SiteWriteStarted $event
     */
    private function setActiveSite(SiteWriteStarted $event)
    {
        $this->site = $event->getSite();
        $this->makeImageDirectory($this->site);
    }

    /**
     * This event handler helps the plugin keep track of the current page being processed.
     *
     * @param ContentWriteStarted $event
     */
    private function setActiveContent(ContentWriteStarted $event)
    {
        $this->page = $event->getContent();
    }

    /**
     * Whenever content is generated, this event handler goes through the generated DOM tree and makes any image tags
     * that have an fn-responsive attribute responsive.
     *
     * @param ContentOutputGenerated $event
     */
    private function processMarkup(ContentOutputGenerated $event)
    {
        try {
            $dom = $event->getDOM();
        } catch (\TypeError $error) {
            return;
        }
        $xpath = new \DOMXPath($dom);
        $imgs = $xpath->query("//img[@fn-responsive]");

        if ($imgs->length == 0) {
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

            if (!$src) {
                $this->errOut("src attribute of <img> tag cannot be empty on page targeted for \"{$page->getDestination()}\"\n", Io::OUTPUT_LEVEL_1);
                continue;
            }

            $src = substr($src->nodeValue, strlen($sitePath));
            $markup = $this->generateResponsiveImageMarkup($page, $src, ['alt' => $alt ? $alt->nodeValue : ""]);
            $newDom = new \DOMDocument();
            @$newDom->loadHTML($markup);
            $pictureElement = $newDom->getElementsByTagName('picture');

            if ($pictureElement->length > 0) {
                $pictureElement = $pictureElement->item(0);
                $pictureElement = $dom->importNode($pictureElement, true);
            } else {
                $pictureElement = $dom->createTextNode($markup);
            }

            $img->parentNode->replaceChild($pictureElement, $img);
        }
    }

    /**
     * Registers the image markup with nyansapow's built in parser.
     *
     * @param PluginsInitialized $event
     */
    private function registerParserTags(PluginsInitialized $event)
    {
        $event->getTagParser()->registerTag("/(?<image>.*\.(jpeg|jpg|png|gif|webp))/",10, [$this, 'getMarkupGenerator'], 'responsive image');
    }

    /**
     * Registers the templates used for rendering images.
     *
     * @param ThemeLoaded $event
     */
    private function registerTemplates(ThemeLoaded $event)
    {
        $this->templateEngine = $event->getTemplateEngine();
        $this->templateEngine->prependPath(__DIR__ . "/templates");
    }

    private function generateLinearSteppedImages($site, $image, $attributes)
    {
        $sizes = [];
        $jpeg = null;

        $width = $image->getImageWidth();
        $aspect = $width / $image->getImageHeight();
        $min = $this->getOption('min_width', 200);
        $max = $attributes['max-width'] ?? $this->getOption('max_width', $width);
        $step = ($max - $min) / $this->getOption('num_steps', 7);
        $halfStep = $step / 2;
        $lenSourcePath = strlen($site->getSourcePath(""));

        for ($i = $min; $i < $max || abs($i - $max) < 0.0001; $i += $step) {
            $size = round($i);
            $jpegs = [];
            $webps = [];
            $jpeg = substr($this->writeImage($site, $image, $size, 'jpeg', $aspect), $lenSourcePath);
            $jpegs[] = [$jpeg];
            $webps[] = [substr($this->writeImage($site, $image, $size, 'webp', $aspect), $lenSourcePath)];

            if ($this->getOption('hidpi', false) && $size * 2 < $width) {
                $jpegs[] = [substr($this->writeImage($site, $image, $size * 2, 'jpeg', $aspect), $lenSourcePath), 2];
                $webps[] = [substr($this->writeImage($site, $image, $size * 2, 'webp', $aspect), $lenSourcePath), 2];
            }
            $sizes[] = ['jpeg_srcset' => $jpegs, 'webp_srcset' => $webps, 'max_width' => $size];
        }

        return [$sizes, $jpeg];
    }

    private function makeImageDirectory(AbstractSite $site): void
    {
        $outputDir = Filesystem::directory($site->getSourcePath($this->getOption('image_path', 'np_images/responsive_images/')));
        try {
            $outputDir->create();
        } catch (FileAlreadyExistsException $e) {

        }
    }

    /**
     * Generates and the HTML markup for a single responsive image instance.
     * This function generates markups through the plugins templates, which can be overidden by the end user, and caches
     * them to improve performance.
     *
     * @param $page
     * @param $imagePath
     * @param $attributes
     * @return string
     * @throws \ImagickException
     */
    private function generateResponsiveImageMarkup(Content $page, string $imagePath, array $attributes) : string
    {
        $site = $this->site;
        // serialized json attributes are added to the cache key to force cache invalidations
        // when image attributes change
        $jsonAttributes = \json_encode($attributes);
        return $site->getCache()->get("responsive-image:$imagePath:$jsonAttributes:{$page->getDestination()}",
            function () use ($site, $page, $imagePath, $attributes) {
                $alt = $attributes['__default'] ?? $attributes['alt'] ?? "";
                $filename = $site->getSourcePath($imagePath);

                if (!\file_exists($filename)) {
                    $this->errOut("File {$filename} does not exist.\n");
                    return "Responsive Image Plugin: File [{$filename}] does not exist.";
                }

                $image = new \Imagick($filename);
                $this->stdOut("Generating responsive images for {$filename}\n", Io::OUTPUT_LEVEL_1);
                $templateVariables = $site->getTemplateData($page->getFullDestination());
                list($sources, $defaultImage) = $this->generateLinearSteppedImages($site, $image, $attributes);

                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if ($extension == 'jpeg' || $extension == 'jpg') {
                    $defaultImage = $imagePath;
                }

                $args = [
                    'sources' => $sources,
                    'image_path' => $defaultImage, 'alt' => $alt,
                    'site_path' => $templateVariables['site_path'],
                    'width' => $image->getImageWidth(),
                    'height' => $image->getImageHeight(),
                    'attrs' => [
                        'loading' => $attributes['loading'] ?? 'lazy',
                        'frame' => $attributes['frame'] ?? ''
                    ]
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
    public function getMarkupGenerator($matches, $text, $attributes)
    {
        return $this->generateResponsiveImageMarkup($this->page, "np_images/{$matches['image']}", $attributes);
    }

    /**
     * @param $site
     * @param $image
     * @param $width
     * @param $format
     * @param $aspect
     * @return string
     */
    private function writeImage($site, $image, $width, $format, $aspect) : string
    {
        $filename = substr($image->getImageFilename(), strlen($site->getSourcePath("np_images")) + 1);
        $filename = $site->getSourcePath(
            $this->getOption('image_path', 'np_images/responsive_images/') .
            str_replace("/", "-", $filename) . "@{$width}px.$format"
        );
        if (file_exists($filename) && filemtime($image->getImageFilename()) < filemtime($filename)) {
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
