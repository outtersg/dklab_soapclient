--TEST--
Dklab_SoapClient: __getLastRequest() corresponds to last pushed request
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
	$raws[$num] = array($nonWsdlClientWithTraces->__getLastRequest());
}
foreach ($results as $num => $result) {
	$r = $result->getResult();
	$raws[$num][1] = $nonWsdlClientWithTraces->__getLastResponse();
	$raws[$num][2] = $r;
}
foreach ($raws as $num => $raw) {
	echo $num . ': ' . payload($raw[0]) . ' > ' . payload($raw[1]) . ' / ' . $raw[2] . "\n";
}

?>
--EXPECT--
0: 0 > Request #0 done / Request #0 done
1: 1 > Request #1 done / Request #1 done
