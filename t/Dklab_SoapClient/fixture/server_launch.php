<?php

/*- Client-side --------------------------------------------------------------*/

function serverLaunchLog($message)
{
	// @todo Make it opt-in
	if ('run-tests.php does not like being polluted by STDERR diagnose') {
		return;
	}
	$args = func_get_args();
	array_unshift($args, STDERR);
	call_user_func_array('fprintf', $args);
}

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
			if ($r->concurrency == 1) {
				system("kill $pid");
			} else {
				// If the pid is a slave, we want to reach the master.
				exec("ps -p {$r->PPID} -o pid,ppid,command", $rPPID);
				$rPPID = array_pop($rPPID);
				if (false !== strpos($rPPID, 'php -S ')) {
					$pid = $r->PPID;
				}
				// And whoops, it seems that it is not receptive anymore to SIGINT (only SIGTERM, which lets orphans to be killed individually).
				serverLaunchLog("Killing server %d with concurrency %d...\n", $pid, $r->concurrency);
				system("kill $pid `ps -o pid,ppid | grep '[ 	]$pid$' | awk '{print\$1}'`");
			}
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
	serverLaunchLog("Starting %s...\n", strtr($cmd, array('nohup ' => '')));
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
	$r = array
	(
		'PID' => getmypid(),
		'PPID' => posix_getppid(),
		'concurrency' => $concurrency,
	);
	echo json_encode($r);
}

if (!isset($GLOBALS['argv'])) {
	serverInfo();
}
