<?php

namespace foonoo\plugins\foonoo\responsive_images;

use clearice\io\Io;
use foonoo\content\Content;
use ntentan\utils\exceptions\FileAlreadyExistsException;
use ntentan\utils\Filesystem;
use foonoo\events\ContentOutputGenerated;
use foonoo\events\ContentGenerationStarted;
use foonoo\events\PluginsInitialized;
use foonoo\events\SiteObjectCreated;
use foonoo\events\SiteWriteStarted;
use foonoo\events\ThemeLoaded;
use foonoo\Plugin;
use foonoo\sites\AbstractSite;
use foonoo\text\TagToken;
use foonoo\text\TemplateEngine;


/**
 * Generates responsive image sizes.
 */
class ResponsiveImagesPlugin extends Plugin
{
    private const TAGS = ['min-width', 'max-width', 'num-steps', 'frame', 'loading', 'preset', 'hidpi', 'compression-quality', 'alt'];

    private TemplateEngine $templateEngine;

    /**
     * @var AbstractSite
     */
    private AbstractSite $site;

    /**
     * @var Content
     */
    private Content $content;

    public function getEvents() : array
    {
        return [
            PluginsInitialized::class => fn (PluginsInitialized $event) => $this->registerParserTags($event),
            ThemeLoaded::class => fn (ThemeLoaded $event) => $this->registerTemplates($event),
            ContentOutputGenerated::class => fn (ContentOutputGenerated $event) => $this->processMarkup($event),
            SiteObjectCreated::class => fn (SiteObjectCreated $event) => $this->setActiveSite($event),
            ContentGenerationStarted::class => fn (ContentGenerationStarted $event) => $this->setActiveContent($event)
        ];
    }

    /**
     * This event handler helps the plugin keep track of the current site being processed
     */
    private function setActiveSite(SiteObjectCreated $event) : void
    {
        $this->site = $event->getSite();
        $this->makeImageDirectory($this->site);
    }

    /**
     * This event handler helps the plugin keep track of the current current being processed.
     * This is necessary for the cases where responsive images are being generated from tags.
     */
    private function setActiveContent(ContentGenerationStarted $event) : void
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
    private function processMarkup(ContentOutputGenerated $event) : void
    {
        try {
            $dom = $event->getDOM();
        } catch (\TypeError $error) {
            $this->errOut("Skipping non DOM content [{$event->getContent()->getDestination()}]");
            return;
        }
        if($dom == null) {
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
            $sitePath = $site->getTemplateData($page)['site_path'];
            list($attributes, $nodeAttributes) = $this->extractDomAttributes($img->attributes);
            $attributes['attributes'] = $nodeAttributes;
            $src = $nodeAttributes['src'];

            if (!$src) {
                $this->errOut("src attribute of <img> tag cannot be empty on page targeted for \"{$page->getDestination()}\"\n", Io::OUTPUT_LEVEL_1);
                continue;
            }

            $src = $sitePath == "./" || $sitePath == "."  ? $src : substr($src, strlen($sitePath));
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
     * Collate attributes so those from tags, presets, and plugin options are combined in the right order.
     * Attribute combination follows this hierarchy: tag supercedes preset, which further supercedes plugin. The method
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
        $presets = $this->getOption('presets');
        $presetAttributes = [];

        if($presets != null && isset($attributes['preset']) && isset($presets[$attributes['preset']])) {
            $preset = $attributes['preset'];
            $presetAttributes = $presets[$preset];
            $attributes['attributes']['class'] = 
                (isset($attributes['attributes']['class']) ? $attributes['attributes']['class'] : "") 
                . " fn-preset-${preset}";
        } else if ($presets != null && isset($attributes['preset'])) {
            $this->errOut("Preset [{$attributes['preset']}] is not configured.");
        }

        foreach ($tags as $tag) {
            if(isset($attributes[$tag])) {
                continue;
            } else if (isset($presetAttributes[$tag])) {
                $attributes[$tag] = $presetAttributes[$tag];
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
    private function registerParserTags(PluginsInitialized $event) : void
    {
        /** @var TagParser */
        $tagParser = $event->getTagParser();
        $imgLinkRegex = "(?<path>.*\.(jpeg|jpg|png|gif|webp))";

        $tags = [
            ["url" => $imgLinkRegex], // Register the tag for just a single image URL [[url.img]]
            ["url" => $imgLinkRegex, "args" => TagToken::ARGS_LIST],
            ["alt" => TagToken::TEXT, "url" => $imgLinkRegex], // Register the tag for a description and its URL [[description|url.img]]
            ["alt" => TagToken::TEXT, "url" => $imgLinkRegex, TagToken::ARGS_LIST],
        ];

        foreach($tags as $tag) {
            $tagParser->registerTag($tag, 1000, fn($args) => $this->getMarkupGenerator($args), 'responsive image');    
        }
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
    private function generateLinearSteppedImages(AbstractSite $site, \Imagick $image, array $attributes) : array
    {
        $sources = [];
        $jpeg = null;

        $width = $image->getImageWidth();
        $aspect = $width / $image->getImageHeight();
        $min = $this->getOption('min-width', 200);
        $max = $attributes['max-width'] ?? $this->getOption('max-width', $width);
        $backgroundColor = $attributes['background-color'] ?? $this->getOption('background-color', 'white');
        $step = ($max - $min) / $this->getOption('num-steps', 7);
        $lenSourcePath = strlen($site->getSourcePath("_foonoo")) + 1;

        for ($i = $min; $i < $max || abs($i - $max) < 0.0001; $i += $step) {
            $size = round($i);
            $jpegs = [];
            $webps = [];
            // Extract the default fallback JPEG
            $jpeg = substr($this->writeImage($site, $image, $size, 'jpeg', $aspect, $backgroundColor), $lenSourcePath);
            $jpegs[] = [$jpeg];
            $webps[] = [substr($this->writeImage($site, $image, $size, 'webp', $aspect), $lenSourcePath)];

            if (array_search($this->getOption('hidpi', false), [true, "true", ""]) !== false && $size * 2 < $width) {
                $jpegs[] = [substr($this->writeImage($site, $image, $size * 2, 'jpeg', $aspect, $backgroundColor), $lenSourcePath), 2];
                $webps[] = [substr($this->writeImage($site, $image, $size * 2, 'webp', $aspect), $lenSourcePath), 2];
            }
            $sources[] = ['jpeg_srcset' => $jpegs, 'webp_srcset' => $webps, 'max_width' => $size];
            
            // special case to break when step is 0
            if($step == 0) {
                break;
            }
        }

        return [$sources, $jpeg];
    }

    private function makeImageDirectory(AbstractSite $site): void
    {
        $outputDir = Filesystem::directory($site->getSourcePath(
            $this->getOption('image_path', '_foonoo/images/responsive_images/'))
        );
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
        return $site->getCache()->get(
            "responsive-image:$imagePath:$jsonAttributes:{$content->getDestination()}:{$this->getOption('hidpi', false)}",
            function () use ($site, $content, $imagePath, $attributes) {
                $filename = $site->getSourcePath($imagePath);

                if (!\file_exists($filename)) {
                    $this->errOut("File {$filename} does not exist.\n");
                    return "Responsive Image Plugin: File [{$filename}] does not exist.";
                }

                $image = new \Imagick($filename);
                $this->stdOut("Generating responsive images for {$filename}\n", Io::OUTPUT_LEVEL_1);
                $templateVariables = $site->getTemplateData($content);
                list($sources, $defaultImage) = $this->generateLinearSteppedImages($site, $image, $attributes);

                $args = [
                    'sources' => $sources,
                    'image_path' => $defaultImage, 
                    'alt' => $attributes['alt'] ?? "",
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
     * Get an instance of the markup generator.
     * 
     * @param $matches
     * @return string
     * @throws \ImagickException
     */
    private function getMarkupGenerator(array $args) : string
    {
        $attributes = $args['__args'] ?? [];
        $attributes['alt'] = $args['alt'] ?? "";
        $attributes['attributes'] = $attributes;

        foreach(self::TAGS as $tag) {
            unset($attributes['attributes'][$tag]);
        }

        return $this->generateResponsiveImageMarkup(
            $this->content, "_foonoo/images/{$args['url']['path']}", 
            $this->collateAttributes($attributes)
        );
    }

    private function applyBackgroundColor(\Imagick $image, string $backgroundColorDescription) {
        $backgroundColor = new \ImagickPixel();
        $backgroundColor->setColor($backgroundColorDescription);
        $output = new \Imagick();
        $output->newImage($image->getImageWidth(), $image->getImageHeight(), $backgroundColor, 'jpeg');
        $output->compositeImage($image, \Imagick::COMPOSITE_DEFAULT, 0, 0);
        return $output;
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
    private function writeImage(AbstractSite $site, \Imagick $image, float $width, string $format, float $aspect, string $backgroundColor=null): string
    {
        $filename = substr($image->getImageFilename(), strlen($site->getSourcePath("_foonoo/images")) + 1);
        $filename = $site->getSourcePath(
            $this->getOption('image_path', '_foonoo/images/responsive_images/') .
            str_replace("/", "-", $filename) . "@{$width}px.$format"
        );
        if (file_exists($filename) && filemtime($image->getImageFilename()) < filemtime($filename)) {
            return $filename;
        }
        $image = clone $image;
        $width = round($width);
        $image->scaleImage($width, (int) round($width / $aspect));
        if ($format == 'jpeg' && $image->getImageAlphaChannel() && $backgroundColor != null) {
            $image = $this->applyBackgroundColor($image, $backgroundColor);
        }
        $image->setImageCompressionQuality($this->getOption("compression-quality", 70));
        $this->stdOut("Writing image $filename\n");
        $image->writeImage($filename);
        return $filename;
    }
}

