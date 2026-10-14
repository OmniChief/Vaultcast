<?php
/** A–Z rail layout guards (static): php Application/tests/az_rail_test.php */
$fails = 0;
function check($c, $m) { global $fails; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; }
$css = file_get_contents(__DIR__ . '/../assets/css/app.css');
$js = file_get_contents(__DIR__ . '/../assets/js/app.js');
check(preg_match('/body\.has-az-rail #section-view,\s*body\.has-az-rail \.has-az-rail \{ padding-left: calc\(var\(--az-gutter\)/', $css) === 1, 'content is padded by the rail gutter');
check(strpos($css, '--az-gutter: 34px') !== false && strpos($css, 'width: 28px !important') !== false, 'rail fits inside its gutter');
check(strpos($css, 'body.az-scrolling .az-rail') !== false && strpos($css, 'opacity: .38') !== false, 'rail is faded until hover/scroll');
check(strpos($css, 'body.az-open .az-rail { display: flex !important; }') !== false, 'narrow screens open the rail from a toggle');
check(strpos($js, "id:'az-toggle'") !== false && strpos($js, "removeEventListener('scroll', azOnScroll)") !== false, 'toggle and scroll listener are created and cleaned up');
echo $fails ? "$fails failed\n" : "all passed\n";
exit($fails ? 1 : 0);
