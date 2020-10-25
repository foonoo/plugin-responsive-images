<?php $reducer = function($c, $i) use($site_path) {return $c . ", {$site_path}{$i[0]} {$i[1]}x";} ?>
<figure>
    <picture>
        <?php foreach($sources as $i => $source): ?>
        <?php
        if($i < count($sources) - 1)
            $media = "media=\"(min-width:{$source['min_width']}px)\"";
        else
            $media = "";
        ?>
        <source srcset="<?= $site_path . $source['webp_srcset'][0][0] ?> <?= array_reduce(array_slice($source['webp_srcset']->unescape(), 1), $reducer, "") ?>" type="image/webp" <?= $media ?> >
        <source srcset="<?= $site_path . $source['jpeg_srcset'][0][0] ?> <?= array_reduce(array_slice($source['jpeg_srcset']->unescape(), 1), $reducer, "") ?>" type="image/jpeg" <?= $media ?> >
        <?php endforeach; ?>
        <img width="<?= $width ?>" height="<?= $height ?>" src="<?= $site_path . $image_path ?>" alt="<?= $alt ?>" loading="lazy" />
    </picture>
    <figcaption><?= $alt ?></figcaption>
</figure>
