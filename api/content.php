<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
/* GET api/content.php → cennik, galeria i certyfikaty dla strony */
$c = content_get();
json_out(['prices' => $c['prices'], 'extras' => $c['extras'], 'note' => $c['note'], 'gallery' => array_values($c['gallery']), 'certs' => array_values($c['certs']), 'reviews' => array_values($c['reviews'])]);
