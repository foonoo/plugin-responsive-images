# Responsive Images Plugin
The idea behind responsive images is simple: your site should attempt serving the right image that works best the end user's display (both in terms of resolution and pixel density). In more obvious—and also simpler—terms, you serve a smaller image for a smaller screen, or a larger one for a larger screen, to give your site a more efficient and responsive browsing experience. 

Properly implementing image responsiveness requires having images of different resolutions, which can be served for the different display sizes, already prepared. Setting this up could be a daunting task, however, and that's where this plugin comes in. All this plugin requires is you provide a high resolution version of your image, and it will generate all the intermediate low-resolution images, as well as the HTML code needed to make it work.

## What does the plugin do
This plugin takes an image and renders it at different resolutions using lightweight web formats, like webp and jpeg. To produce optimal results, however, you have to specify the maximum width an image is expected to have on final rendered pages. This width is most likely going to be determined by your site's theme, or simply the width you want your image to have on your site. For example, when considering foonoo's default ashes theme, images in the body of any article will never exceed 850 pixels. Additionally, you also have to ensure that your original source image has a width larger than your chosen maximum width, and even twice the maximum width if you intend to target screens with higher pixel densities. 

## Usage
To enable this plugin, add `foonoo/responsive_images` to the list of plugins in your `site.yml` file. 


```yml
plugins:
    - foonoo/responsive_images
```

Once enabled, the plugin overrides foonoo's built in image tag to make any images added through those responsive. But to achieve the right responsiveness effect, you may have to provide the `max-width` parameter as shown below:

    [[This is a responsive image| some_responsive_image.png | max-width:800]]

or if you want to use the responsive images directly in your html templates, you could use:

```html
<img fn-responsive fn-responsive-max-width="800" src="some_responsive_image.png"/>
```

Note that parameters passed through html-tags are prefixed with `fn-responsive`. 


### Setting Parameters
Parameters for responsive images can be set in two main ways. They could be either set inline with the tag to locally affect a single image, or they could be set in the `site.yml` file, to globally affect all images (or a subset of images in some cases) while acting as a default value.

Setting inline parameters has already been demonstrated in the Usage section above. When using the `site.yml` to set your parameters, on the other hand, you add your parameters directly to the plugin definition as shown below.
                         
```yml
plugins:
    - foonoo/responsive_images:
        max-width: 800
```

### Using Parameter Classes
In some cases, you may have different categories of images that need responsive parameters. For instance, you could have thumbnails that are of a particular width, along with banners that may be of other widths. Here, setting parameters directly on individual tags could be laborious. Also, having defaults that are automatically applied to all images may not be helpful. To improve this situation, the responsive images plugin provides parameter classes that groups different parameters for selective use.

Parameter classes are actually analogous to—and were inspired by—CSS classes. To use parameter classes, you define a class in your `site.yml` with its own parameters, and then you apply this class to the in-content tags. For example, the following configuration ...

```yml
plugins:
    - foonoo/responsive_images:
        num-steps: 7
        hidpi: true
        classes:
            banner:
                max-width: 650
            preview:
                max-width: 470
            full:
                max-width: 940
```
... defines three classes with different `max-width` values. To apply these to any tags, you could use ...

```
[[This is a responsive image with a class | some_responsive_image.png | class:full]]
```

... or you could also use with HTML tags ...

```html
<img fn-responsive fn-responsive-class="preview" src="some_responsive_image.png" />
```

Classes may also be useful when you want to change the parameters of responsive images in bulk. Defining the class onces and adding the properties will alwasy be easier than editting a bunch of tags en masse.


## List of Parameters

 Parameter            | Default    | Description
--------------------- |------------|-------------------------------
`classes`             | None       | A list of classes and their associated parameters. See the example on parameter classes above for more details.
`compression_quality` | `70`       | Specifies the compression quality of the intermediate images generated. This is specified as a value between `0` and `100`.
`hidpi`               | `false`    | A boolean flag that determnies whether high DPI versions of the images are generated.
`image-path`          |            | Specifies the location in which the rendered intermediary images will be shown.
`max-width`           | Image width| Specifies the maximum width an image could possibly have on the final website.
`min-width`           | 200px      | Specifies the smallest sized image the responsive image should generate.
`num-steps`           | 7          | Specifies the number of images to be generated.
