<?php

/*- Client-side --------------------------------------------------------------*/

function serverRuns($host, $port, $concurrency = 1, $killIfNotEnoughConcurrency = false)
{
	$c = curl_init("http://$host:$port/server_launch.php");
	curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
	$r = curl_exec($c);
	$r = json_decode($r);
	if ($r) {
		if ($r->concurrency >= $concurrency) {
			return true;
		}
		if ($killIfNotEnoughConcurrency) {
			$pid = $r->PID;
			fprintf(STDERR, "Killing server %d with concurrency %d...\n", $r->PID, $r->concurrency);
			system("kill $pid");
		}
	}
}

function requireServer($host, $port, $concurrency = 1)
{
	// Can we contact an already running server?

	if (serverRuns($host, $port, $concurrency, true)) {
		return;
	}

	// Launch!

	// @todo Make it work on Windows.
	$env = $concurrency > 1 ? "PHP_CLI_SERVER_WORKERS=$concurrency " : '';
	$here = dirname(__FILE__);
	$cmd = "{$env}nohup php -S $host:$port -t $here";
	fprintf(STDERR, "Starting %s...\n", strtr($cmd, array('nohup ' => '')));
	system("cd /tmp/ && $cmd < /dev/null > /tmp/php$port.log 2>&1 &");
	usleep(200000);

	// Validate.

	if (!serverRuns($host, $port, $concurrency, false)) {
		throw new Exception("Server at $host:$port could not be launch with concurrency $concurrency");
	}
}

/*- Server-side --------------------------------------------------------------*/

function serverInfo()
{
	$concurrency = getenv('PHP_CLI_SERVER_WORKERS');
	if(!$concurrency || version_compare(phpversion(), '7.4.0') < 0) {
		$concurrency = 1;
	}
	echo json_encode(array('PID' => getmypid(), 'concurrency' => $concurrency));
}

if (!isset($GLOBALS['argv'])) {
	serverInfo();
}
