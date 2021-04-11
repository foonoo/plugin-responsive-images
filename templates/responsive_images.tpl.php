<?php $reducer = function($c, $i) use($site_path) {return $c . ", {$site_path}{$i[0]} {$i[1]}x";} ?>
<?php if($attrs['frame'] == "figure"): ?> <figure> <?php endif; ?>
<?php if($attrs['frame'] == "div"): ?> <div> <?php endif; ?>
    <picture>
        <?php foreach($sources as $i => $source): ?>
        <?php
        if($i < count($sources) - 1)
            $media = "media=\"(max-width:{$source['max_width']}px)\"";
        else
            $media = "";
        ?>
        <source srcset="<?= $site_path . $source['webp_srcset'][0][0] ?> <?= array_reduce(array_slice($source['webp_srcset']->unescape(), 1), $reducer, "") ?>" type="image/webp" <?= $media ?> >
        <source srcset="<?= $site_path . $source['jpeg_srcset'][0][0] ?> <?= array_reduce(array_slice($source['jpeg_srcset']->unescape(), 1), $reducer, "") ?>" type="image/jpeg" <?= $media ?> >
        <?php endforeach; ?>
        <img src="<?= $site_path . $image_path ?>" loading="<?= $attrs['loading'] ?>" <?php foreach($attrs['attributes'] as $key => $value){ print sprintf("$key='%s'", $value);  } ?> />
    </picture>
<?php if($attrs['frame'] == "figure"): ?> <figcaption><?= $alt ?></figcaption> </figure> <?php endif; ?>
<?php if($attrs['frame'] == "div"): ?> </div> <?php endif; ?>
