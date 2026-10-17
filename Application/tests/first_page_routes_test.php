<?php
require __DIR__ . '/../src/Services/FirstPage.php';
$startup = ['me'=>['user'=>['access_state'=>['active'=>true]]], 'home'=>['ok'=>true,
    'sections'=>[['id'=>'movies','type'=>'movies','label'=>'Films','items'=>[['id'=>'abc','title'=>'Home film','year'=>2000,'kind'=>'item']]]], 'continue_watching'=>[], 'item_count'=>1]];
foreach (['library/movies','library/tv','tvshows/greys-anatomy','movies/a-film','collections','games','live','search','settings'] as $route) {
    $html = FirstPage::render($startup, '/', 'Vaultcast', '', $route);
    if (strpos($html, '<img') !== false || strpos($html, 'Home film') !== false || strpos($html, 'aria-busy="true"') === false) throw new RuntimeException('Unrelated Home content on ' . $route);
}
if (strpos(FirstPage::render($startup, '/', 'Vaultcast'), 'Home film') === false) throw new RuntimeException('Home lost real first-response content');
$homeHtml = FirstPage::render($startup, '/', 'Vaultcast');
foreach (['class="search-box" id="q"', 'class="icon-btn avatar"', 'class="subnav-btn active" aria-current="page"'] as $markup) {
    if (strpos($homeHtml, $markup) === false) throw new RuntimeException('Home fallback navigation missing: ' . $markup);
}
$private = ['me'=>['user'=>null], 'home'=>null];
if (strpos(FirstPage::render($private, '/', 'Vaultcast', '', 'movies/a-film'), 'Sign in') === false) throw new RuntimeException('Private deep link bypassed sign-in');
echo "all passed\n";
