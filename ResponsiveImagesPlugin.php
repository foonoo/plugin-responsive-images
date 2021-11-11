<?php

namespace foonoo\plugins\contrib\responsive_images;

use clearice\io\Io;
use foonoo\content\Content;
use ntentan\utils\exceptions\FileAlreadyExistsException;
use ntentan\utils\Filesystem;
use foonoo\events\ContentOutputGenerated;
use foonoo\events\ContentGenerationStarted;
use foonoo\events\PluginsInitialized;
use foonoo\events\SiteWriteStarted;
use foonoo\events\ThemeLoaded;
use foonoo\Plugin;
use foonoo\sites\AbstractSite;


/**
 * Generates responsive image sizes.
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
            PluginsInitialized::class => function (PluginsInitialized $event) { $this->registerParserTags($event); },
            ThemeLoaded::class => function (ThemeLoaded $event) { $this->registerTemplates($event); },
            ContentOutputGenerated::class => function (ContentOutputGenerated $event) { $this->processMarkup($event); },
            SiteWriteStarted::class => function (SiteWriteStarted $event) { $this->setActiveSite($event); },
            ContentGenerationStarted::class => function (ContentGenerationStarted $event) { $this->setActiveContent($event); }
        ];
    }

    /**.
     *
     * This event handler helps the plugin keep track of the current site being processed
     * @param SiteWriteStarted $event
     */
    private function setActiveSite(SiteWriteStarted $event)
    {
        $this->site = $event->getSite();
        $this->makeImageDirectory($this->site);
    }

    /**
     * This event handler helps the plugin keep track of the current current being processed.
     * This is necessary for the cases where responsive images are being generated from tags.
     *
     * @param ContentGenerationStarted $event
     */
    private function setActiveContent(ContentGenerationStarted $event)
    {
        $this->content = $event->getContent();
    }

    private function extractDomAttributes(\DOMNamedNodeMap $domAttributes) : array
    {
        $fnResponsiveAttributes = [];
        $otherAttributes = [];

        /**
         * @var string $name
         * @var \DOMAttr $value
         */
        foreach($domAttributes as $name => $value) {
            if(preg_match("/fn-responsive(-)?(?<attribute>.*)?/", $name, $matches)) {
                if($matches['attribute']) {
                    $fnResponsiveAttributes[$matches['attribute']] = $value->textContent;
                }
            } else {
                $otherAttributes[$name] = $value->textContent;
            }
        }

        return [$fnResponsiveAttributes, $otherAttributes];
    }

    /**
     * Whenever content is generated, this event handler goes through the generated DOM tree and converts any image tags
     * that have an fn-responsive attribute.
     *
     * @param ContentOutputGenerated $event
     * @throws \ImagickException
     */
    private function processMarkup(ContentOutputGenerated $event)
    {
        try {
            $dom = $event->getDOM();
        } catch (\TypeError $error) {
            $this->errOut("Skipping non DOM content [{$event->getContent()->getDestination()}]");
            return;
        }
        $xpath = new \DOMXPath($dom);
        $imgs = $xpath->query("//img[@fn-responsive]");

        if ($imgs->length == 0) {
            return;
        }

        $site = $event->getSite();
        $page = $event->getContent();
        $this->makeImageDirectory($site);

        /** @var $img \DOMNode */
        foreach ($imgs as $img) {
            $sitePath = $site->getTemplateData($site->getDestinationPath($page->getDestination()))['site_path'];
            list($attributes, $nodeAttributes) = $this->extractDomAttributes($img->attributes);
            $attributes['attributes'] = $nodeAttributes;
            $src = $nodeAttributes['src'];

            if (!$src) {
                $this->errOut("src attribute of <img> tag cannot be empty on page targeted for \"{$page->getDestination()}\"\n", Io::OUTPUT_LEVEL_1);
                continue;
            }

            $src = substr($src, strlen($sitePath));
            $markup = $this->generateResponsiveImageMarkup($page, "_foonoo/$src", $this->collateAttributes($attributes));
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
     * Collate attributes so those from tags, classes, and plugin options are combined in the right order.
     * Attribute combination follows this hierarchy: tag supercedes class, which further supercedes plugin. The method
     * that consumes the plugin is left to specify the default.
     *
     * @param $attributes array
     * @return array
     */
    private function collateAttributes(array $attributes): array
    {
        // Setup some defaults
        $attributes['loading'] = $attributes['loading'] ?? 'lazy';

        // Merge on these specific tags
        $tags = ['min-width', 'max-width', 'num-steps', 'frame', 'loading'];
        $classes = $this->getOption('classes');
        $classAttributes = [];

        if($classes != null && isset($attributes['class']) && isset($classes[$attributes['class']])) {
            $classAttributes = $classes[$attributes['class']];
        } else if ($classes != null && isset($attributes['class'])) {
            $this->errOut("Class [{$attributes['class']}] is not configured.");
        }

        foreach ($tags as $tag) {
            if(isset($attributes[$tag])) {
                continue;
            } else if (isset($classAttributes[$tag])) {
                $attributes[$tag] = $classAttributes[$tag];
            } else if ($this->getOption($tag) !== null) {
                $attributes[$tag] = $this->getOption($tag);
            }
        }
        return $attributes;
    }

    /**
     * Registers the image markup with foonoo's built in parser.
     *
     * @param PluginsInitialized $event
     */
    private function registerParserTags(PluginsInitialized $event)
    {
        $event->getTagParser()->registerTag("/(?<image>.*\.(jpeg|jpg|png|gif|webp))/", 10,
            function ($matches, $text, $attributes) {
                return $this->getMarkupGenerator($matches, $text, $attributes);
            },
            'responsive image'
        );
    }

    /**
     * Registers the HTML templates used for rendering images into pages.
     *
     * @param ThemeLoaded $event
     */
    private function registerTemplates(ThemeLoaded $event)
    {
        $this->templateEngine = $event->getTemplateEngine();
        $this->templateEngine->prependPath(__DIR__ . "/templates");
    }

    /**
     * Generate a list of image breakpoints with linearly increasing widths.
     * The linear factor for increasing the widths is computed by dividing the difference between the minimum width and
     * maximum width by the number of required steps.
     *
     * @param $site AbstractSite The current site
     * @param $image \Imagick An instance of the image.
     * @param $attributes array An array with image attributes.
     * @return array
     * @throws \ImagickException
     */
    private function generateLinearSteppedImages(AbstractSite $site, \Imagick $image, array $attributes)
    {
        $sizes = [];
        $jpeg = null;

        $width = $image->getImageWidth();
        $aspect = $width / $image->getImageHeight();
        $min = $this->getOption('min-width', 200);
        $max = $attributes['max-width'] ?? $this->getOption('max-width', $width);
        $step = ($max - $min) / $this->getOption('num-steps', 7);
        $lenSourcePath = strlen($site->getSourcePath("_foonoo"));

        for ($i = $min; $i < $max || abs($i - $max) < 0.0001; $i += $step) {
            $size = round($i);
            $jpegs = [];
            $webps = [];
            $jpeg = substr($this->writeImage($site, $image, $size, 'jpeg', $aspect), $lenSourcePath + 1);
            $jpegs[] = [$jpeg];
            $webps[] = [substr($this->writeImage($site, $image, $size, 'webp', $aspect), $lenSourcePath + 1)];

            if ($this->getOption('hidpi', false) && $size * 2 < $width) {
                $jpegs[] = [substr($this->writeImage($site, $image, $size * 2, 'jpeg', $aspect), $lenSourcePath), 2];
                $webps[] = [substr($this->writeImage($site, $image, $size * 2, 'webp', $aspect), $lenSourcePath), 2];
            }
            $sizes[] = ['jpeg_srcset' => $jpegs, 'webp_srcset' => $webps, 'max_width' => $size];
            
            // special case to break when step is 0
            if($step == 0) {
                break;
            }
        }

        return [$sizes, $jpeg];
    }

    private function makeImageDirectory(AbstractSite $site): void
    {
        $outputDir = Filesystem::directory($site->getSourcePath($this->getOption('image_path', '_foonoo/images/responsive_images/')));
        try {
            $outputDir->create(true);
        } catch (FileAlreadyExistsException $e) {

        }
    }

    /**
     * Generates and the HTML markup for a single responsive image instance.
     * This function generates markups through the plugins templates, which can be overidden by the end user, and caches
     * them to improve performance.
     *
     * @param Content $content
     * @param string $imagePath
     * @param array $attributes
     * @return string
     * @throws \ImagickException
     */
    private function generateResponsiveImageMarkup(Content $content, string $imagePath, array $attributes): string
    {
        $site = $this->site;
        // serialized json attributes are added to the cache key to force cache invalidations
        // when image attributes change
        $jsonAttributes = \json_encode($attributes);
        return $site->getCache()->get("responsive-image:$imagePath:$jsonAttributes:{$content->getDestination()}",
            function () use ($site, $content, $imagePath, $attributes) {
                $filename = $site->getSourcePath($imagePath);

                if (!\file_exists($filename)) {
                    $this->errOut("File {$filename} does not exist.\n");
                    return "Responsive Image Plugin: File [{$filename}] does not exist.";
                }

                $image = new \Imagick($filename);
                $this->stdOut("Generating responsive images for {$filename}\n", Io::OUTPUT_LEVEL_1);
                $templateVariables = $site->getTemplateData($content->getFullDestination());
                list($sources, $defaultImage) = $this->generateLinearSteppedImages($site, $image, $attributes);

                $args = [
                    'sources' => $sources,
                    'image_path' => $defaultImage, 
                    'alt' => $attributes['attributes']['alt'] ?? "",
                    // replace the site path with a dot in cases where we're working on the root site
                    'site_path' => $templateVariables['site_path'] == "" ? "./" : $templateVariables['site_path'],
                    'width' => $image->getImageWidth(),
                    'height' => $image->getImageHeight(),
                    'attrs' => $attributes
                ];

                return $this->templateEngine->render('responsive_images', $args);
            },
            file_exists($imagePath) ? filemtime($imagePath) : 0
        );
    }

    /**
     * @param $matches
     * @return string
     * @throws \ImagickException
     */
    private function getMarkupGenerator($matches, $text, $attributes)
    {
        $attributes['attributes'] = ['alt' => $attributes['__default'] ?? ""];
        return $this->generateResponsiveImageMarkup($this->content, "_foonoo/images/{$matches['image']}", $this->collateAttributes($attributes));
    }

    /**
     * Write images to file.
     * 
     * @param $site
     * @param $image
     * @param $width
     * @param $format
     * @param $aspect
     * @return string
     */
    private function writeImage($site, $image, $width, $format, $aspect): string
    {
        $filename = substr($image->getImageFilename(), strlen($site->getSourcePath("_foonoo/images")) + 1);
        $filename = $site->getSourcePath(
            $this->getOption('image_path', '_foonoo/images/responsive_images/') .
            str_replace("/", "-", $filename) . "@{$width}px.$format"
        );
        if (file_exists($filename) && filemtime($image->getImageFilename()) < filemtime($filename)) {
            return $filename;
        }
        $image = $image->clone();
        $width = round($width);
        $image->scaleImage($width, $width / $aspect);
        $image->setImageCompressionQuality($this->getOption("compression_quality", 60));
        $this->stdOut("Writing image $filename\n");
        $image->writeImage($filename);
        return $filename;
    }
}
