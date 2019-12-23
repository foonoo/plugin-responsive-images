<figure>
    <picture>
        <?php foreach($sources as $source): ?>
        <source srcset="<?= $site_path . $source['src_webp'] ?>" media="(max-width: <?= $source['max_width'] ?>px)" />
        <source srcset="<?= $site_path . $source['src_jpeg'] ?>" media="(max-width: <?= $source['max_width'] ?>px)" />
        <?php endforeach; ?>
        <img src="<?= $site_path . $image_path ?>" alt="<?= $alt ?>" />
    </picture>
    <figcaption><?= $alt ?></figcaption>
</figure>
