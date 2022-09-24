--TEST--
Dklab_SoapClient: __getLastResponse() corresponds to last getResult() even when fetched out of order
--FILE--
<?php
$serverConcurrency = 2;
require dirname(__FILE__) . '/init.php';

function payload($xml)
{
	return preg_match('#<(?:[^:> ]*:)?(?:param0|return)[^>]*>([^<]*)#', $xml, $r) ? $r[1] : null;
}

$results = array();
$raws = array();
for ($num = 0, $delay = 0.8; $num < 2; ++$num, $delay /= 2) {
	$results[$num] = $nonWsdlClientWithTraces->async->slowMethod($num, $delay);
}
for ($num = 2; --$num >= 0;) {
	$r = $results[$num]->getResult();
	$raws[$num] = array($nonWsdlClientWithTraces->__getLastRequest(), $nonWsdlClientWithTraces->__getLastResponse(), $r);
}
foreach ($raws as $num => $raw) {
	echo $num . ': ' . payload($raw[0]) . ' > ' . payload($raw[1]) . ' / ' . $raw[2] . "\n";
}

?>
--EXPECT--
1: 1 > Request #1 done / Request #1 done
0: 0 > Request #0 done / Request #0 done
