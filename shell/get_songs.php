<?php

if(php_sapi_name() !== 'cli') exit;

set_time_limit(0);

include_once 'lib/hQuery.php/hquery.php';
include_once 'lib/utils.php';

use duzun\hQuery;

hQuery::$cache_path = "cache";
hQuery::$cache_expires = 3600; // default one hour

// First load the songs.json file, or create an empty array
if(file_exists('data/songs.json'))
    $songs = json_decode(file_get_contents('data/songs.json'), JSON_OBJECT_AS_ARRAY);
else
    $songs = array();

// store the current number of songs we have before scraping new ones
$n = count($songs);

// Load newest songs by FM Sheet Music
$doc = hQuery::fromUrl('https://www.sheetmusicplus.com/publishers/francesca-marzolino-sheet-music/3007759?isPLP=1&Ns=releaseDate|1&recsPerPage=50&currentPage=1', ['Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8']);

// get the link to each song
$links = $doc->find('.productList .heroImg .overlay > a');

if($links) {

    foreach($links as $pos => $link) {

        // this adds the link to the song by the SMP id, if it is already not in array
        
        $href = $link->attr('href');
        
        $tmp = explode('/', $href);
        $id = end($tmp);

        if(!isset($songs[$id]))
            $songs[$id] = $href;
    }

}

// print some stats
$total = count($songs);
$new = $total-$n;
echo "$new new songs found\n";
echo "$total total songs\n";

// save data
file_put_contents('data/songs.json', json_encode($songs, JSON_PRETTY_PRINT));

foreach($songs as $id=>$song) {

    // todo: check if we have data already
    if (is_dir("data/$id")) {
        continue;
    } else {
        mkdir("data/$id");
    }
    
    $doc = hQuery::fromUrl($song, ['Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8']);

    // the data obj
    $data = array(
        'url' => $song,
        'slug' => '',
        'title' => '',
        'desc' => '',
        'long_desc' => '',
        'mp3' => '',
        'jpgs' => array(),
        'attr' => array()
    );

    $title = $doc->find('.productInfo h1');
    $data['title'] = trim($title->text());

    echo "\nScraping $title \n";
    echo "id: $id \n";
    

    $slug = slugify($title);
    $data['slug'] = $slug;

    $desc = $doc->find('.productInfo .desc');
    $data['desc'] = no_nbsp($desc->text());

    $desc_p = $doc->find('.productTabs #detailedTab .description p');
    $long_desc = '';
    foreach($desc_p as $p) {
        if(trim($p->text()) == 'About SMP Press') break;
        if(trim($p->text()) == '') continue;
        $long_desc .= $p->html().'<br/>';
    }
    $data['long_desc'] = $long_desc;

    $audio = $doc->find('li.jp-selectedtrack > a');
    if($audio) {
        $mp3_url = $audio->attr('data-jptrack');
        copy($mp3_url, "data/$id/$slug.mp3");
        $data['mp3'] = "$slug.mp3";
    } else {
        echo "NO MP3 FOUND FOR $id\n";
    }

    $preview_jpgs = array();
    $images = $doc->find('.modalSmallImages img');
    $i = 0;
    foreach($images as $img) {
        $src = $img->attr('src');
        if(trim($src) == '')
            $src = $img->attr('srcurl');

        $local = "data/$id/$slug-$i.png";
        copy($src, $local);
        if($i > 0) {
            $_local = escapeshellarg($local);
            `convert $_local assets/watermark.png -gravity center -composite $_local`;
        }
        $preview_jpgs[$i] = "$slug-$i.png";

        $i++;
    }
    $data['jpgs'] = $preview_jpgs;

    // get the "See Similar Sheet Music" attributes
    $attrs = $doc->find('article.anchorText a');
    $n_last_attr = 0;
    foreach($attrs as $attr) {
        if(strpos($attr->attr('href'), '#collapse') !== false) {
            $attr_label = rtrim(trim($attr->text()), ':');
            $data['attr'][] = array('label' => $attr_label, 'vals' => array());
            $n_last_attr = count($data['attr'])-1;
        } else {
            $data['attr'][$n_last_attr]['vals'][] = no_nbsp($attr->text());
        }
    }

    
    file_put_contents("data/$id/data.json", json_encode($data, JSON_PRETTY_PRINT));

    sleep(3);
    
}
