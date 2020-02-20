<?php $reducer = function($c, $i) use($site_path) {return $c . ", {$site_path}{$i[0]} {$i[1]}x";} ?>
<figure>
    <picture>
        <?php foreach($sources as $source): ?>
        <source srcset="<?= $site_path . $source['webp_srcset'][0][0] ?> <?= array_reduce(array_slice($source['webp_srcset']->unescape(), 1), $reducer, "") ?>" media="(max-width: <?= $source['max_width'] ?>px)" >
        <source srcset="<?= $site_path . $source['jpeg_srcset'][0][0] ?> <?= array_reduce(array_slice($source['jpeg_srcset']->unescape(), 1), $reducer, "") ?>" media="(max-width: <?= $source['max_width'] ?>px)" >
        <?php endforeach; ?>
        <img src="<?= $site_path . $image_path ?>" alt="<?= $alt ?>" />
    </picture>
    <figcaption><?= $alt ?></figcaption>
</figure>
