<?php
/**
 * Enhanced SOAP client with support of parallel requests and reconnects:
 *
 * 1. Can retry a connection if it is failed.
 * 2. Can perform multiple SOAP requests asynchronously, in parallel:
 *      $req1 = $client->async->someMethod1(); // called asynchronously
 *      $req2 = $client->async->someMethod2(); // called asynchronously
 *      $result3 = $client->someMethod(); // called synchronously, as usual
 *      $result1 = $req1->getResult();
 *      $result2 = $req1->getResult();
 * 3. Supports data fetch timeout processing.
 * 4. Supports connection timeout handling with reconnection if needed;
 * 
 * Additional supported options:
 *   - "timeout": cURL functions call timeout;
 *   - "connection_timeout": timeout for CONNECT procedure (may be less
 *     than "timeout"; if greater, set to "timeout");
 *   - "response_validator": callback to validate the response; must 
 *     return true if a response is valid, false - if invalid,
 *     and throw an exception if retry count is too high. Never called
 *     if a response data reading timed out.
 *   - "host": hostname used to pass in "Host:" header.
 * 
 * Additional SoapFault properties addigned after a fault:
 *   - "location": server URL which was called;
 *   - "request": calling parameters (the first is the procedure name);
 *   - "response": cURL-style response information as array.
 * 
 * Note that by default the interface is fully compatible with 
 * native SoapClient. You should use $client->async pseudo-property
 * to perform asyncronous requests. 
 * 
 * ATTENTION! Due to cURL or SoapCliend strange bug a crash is sometimes
 * caused on Windows. Don't know yet how to work-around it... This bug
 * is not clearly reproducible.
 *
 * @version 0.94
 */
class Dklab_SoapClient extends SoapClient
{
    /**
     * Which number of concurrent request do we default to when in async mode?
     * This is used when an instance is created with an 'async' option to true;
     * integer values passed in 'async' explicitely set the number of requests
     * to throttle to, overriding this default.
     * Note that a null default means no throttling (try to reach max
     * throughput when in async mode).
     * /!\ An 'async' of 1 will _not_ be considered a true, but merely a way
     *     of simulating sync mode (1 by 1 request) over async (to avoid
     *     modifying the caller's result handling, which is different in
     *     async mode, with an explicit getResult() to call).
     */
    public static $DEFAULT_ASYNC_THROTTLING = null;

    private $_recordedRequest = null;
    private $_hasForcedResponse = false;
    private $_forcedResponse = null;
    private $_clientOptions = array();
    private $_cookies = array();
    
    /**
     * Create a new object.
     * 
     * @see SoapClient
     */
    public function __construct($wsdl, $options = array())
    {
        $this->_clientOptions = is_array($options)? array() + $options : array();
        parent::__construct($wsdl, $options);
    }
    
    /**
     * Perform a raw SOAP request.
     * 
     * @see SoapClient::__doRequest
     */
    #[\ReturnTypeWillChange]
    public function __doRequest($request, $location, $action, $version, $one_way = 0)
    {
        if ($this->_hasForcedResponse) {
            // We forced a response, so return it.
            return $this->_forcedResponse;
        }
        // Record the request for later async sending.
        // Note the "" appended to the beginning of the string: this creates
        // string copies to work-around PHP's SoapClient bug with refs counting. 
        $this->_recordedRequest = array(
            'request'  => "" . $request,
            'location' => "" . $location,
            'action'   => "" . $action,
            'version'  => "" . $version,
            'cookies'  => $this->_cookies,
        );
        throw new Dklab_SoapClient_DelayedException();
    }
    
    /**
     * Perform a SOAP method call.
     * 
     * @see SoapClient::__call
     */
    #[\ReturnTypeWillChange]
    public function __call($functionName, $arguments)
    {
        return $this->__soapCall($functionName, $arguments);
    }
    
    /**
     * Perform a generic SOAP method call.
     * 
     * Depending on boolean $options['async'] it may be:
     *   - synchronous: the operation waits for a response, and result is returned
     *   - asynchronous: the operation is scheduled, but returned immediately
     *     the Request object which may be synchronized by getResult() call later.
     * 
     * @see SoapClient::__soapCall
     */
    #[\ReturnTypeWillChange]
    public function __soapCall($functionName, $arguments, $options = array(), $inputHeaders = null, &$outputHeaders = null)
    {
        $isAsync = false;
        if (!empty($options['async'])) {
            $isAsync = $options['async'];
            unset($options['async']);
        }
        $args = func_get_args();
        try {
        	// Unfortunately, we cannot use call_user_func_array(), because
        	// it does not support "parent::" construction. And we cannot
        	// call is "statically" because of E_STRICT.
            parent::__soapCall($functionName, $arguments, $options, $inputHeaders, $outputHeaders);
        } catch (Dklab_SoapClient_DelayedException $e) {
        }
        $request = new Dklab_SoapClient_Request($this, $args, $isAsync === true ? static::$DEFAULT_ASYNC_THROTTLING : $isAsync);
        $this->_recordedRequest = null;
        if ($isAsync) {
            // In async mode - return the request.
            return $request;
        } else {
            // In syncronous mode (default) - wait for a result.
            return $request->getResult();
        }
    }
    
    /**
     * Set a cookie for this client.
     * 
     * @param string $name
     * @param string $value
     * @return void
     */
    #[\ReturnTypeWillChange]
    public function __setCookie($name, $value = null)
    {
        parent::__setCookie($name, $value);
        if ($value !== null) {
            $this->_cookies[$name] = $value;
        } else {
            unset($this->_cookies[$name]);
        }
    }
    
    /**
     * Perform a SOAP method call emulation returning as a method 
     * result specified XML response. This is needed for curl_multi.
     * 
     * @param string $forcedResponse  XML forced as a SOAP response.
     * @param array $origArgs         Arguments for __soapCall().
     * @return mixed                  SOAP result.
     */
    public function __soapCallForced($forcedResponse, $origArgs)
    {
        $this->_forcedResponse = $forcedResponse;
        $this->_hasForcedResponse = true;
        try {
        	// Unfortunately, we cannot use call_user_func_array(), because
        	// it does not support "parent::" construction. And we cannot
        	// call is "statically" because of E_STRICT.
            $result = parent::__soapCall($origArgs[0], $origArgs[1], isset($origArgs[2])? $origArgs[2] : array(), @$origArgs[3], $origArgs[4]);
            $this->_forcedResponse = null;
            $this->_hasForcedResponse = false;
            return $result;
        } catch (Exception $e) {
            $this->_forcedResponse = null;
            $this->_hasForcedResponse = false;
            throw $e;
        }
    }
    
    /**
     * Getter for clientOptions
     */
    public function getClientOptions()
    {
        return $this->_clientOptions;
    }
    
    /**
     * Getter for _recordedRequest
     */
    public function getRecordedRequest()
    {
        return $this->_recordedRequest;
    }
    
    /**
     * Support for ->async property with no cyclic references.
     * 
     * @param string $key
     * @return self
     */
    public function __get($key)
    {
        if ($key == "async") {
            return new Dklab_SoapClient_AsyncCaller($this);
        } else {
            throw new Exception("Attempt to access undefined property " . get_class($this) . "::$key");
        }
    }
}


/**
 * Object is accessed via $dklabSoapClient->async->someMethod().
 */
class Dklab_SoapClient_AsyncCaller
{
    private $_client;
    
    public function __construct($client)
    {
        $this->_client = $client;
    }
    
    public function __soapCall($functionName, $arguments, $options)
    {
        $options += array('async' => true);
        return $this->_client->__soapCall($functionName, $arguments, $options);
    }
    
    public function __call($functionName, $arguments)
    {
        return $this->_client->__soapCall($functionName, $arguments, array('async' => true));
    }
}

/**
 * Exception to mark recording calls to __doRequest().
 * Used internally.
 */
class Dklab_SoapClient_DelayedException extends Exception
{
}


/**
 * Background processed HTTP request.
 * Used internally.
 */
class Dklab_SoapClient_Request
{
    /**
     * Shared curl_multi manager.
     * 
     * @var Dklab_SoapClient_Curl
     */
    private static $_curl = null;
    
    /**
     * True if this request already contain a response.
     * 
     * @var bool
     */
    private $_isSynchronized = false;
    
    /**
     * Request parameters.
     * 
     * @var array
     */
    private $_request = null;
    
    /**
     * Result of the request (if $_isSynchronized is true).
     * 
     * @var mixed
     */
    private $_result = null;
    
    /**
     * cURL request handler.
     * 
     * @var stdClass
     */
    private $_handler = null;
    
    /**
     * SOAP client object which created this request.
     * 
     * @var Dklab_SoapClient
     */
    private $_client = null;
    
    /**
     * Arguments to call __soapCall().
     * 
     * @var array 
     */
    private $_callArgs = null;
    
    /**
     * URL which is requested.
     * 
     * @var string
     */
    private $_url;
    
    /**
     * Create a new asynchronous cURL request.
     * 
     * @param Dklab_SoapClient $client
     * @param array $callArgs            Arguments to call __soapCall().
     * @param null|int $throttle         If set, limit to $throttle concurrently running requests.
     */
    public function __construct(Dklab_SoapClient $client, $callArgs, $throttle = null)
    {
        if (!self::$_curl) {
            self::$_curl = new Dklab_SoapClient_Curl();
        }
        self::$_curl->throttle($throttle);
        $this->_client = $client;
        $this->_request = $this->_client->getRecordedRequest();
        $this->_callArgs = $callArgs;
        $this->_url = $this->_request['location'];
        $clientOptions = $this->_client->getClientOptions();
        
        // Initialize curl request and add it to the queue.
        $curlOptions = array();
        $curlOptions[CURLOPT_URL] = $this->_request['location'];
        $curlOptions[CURLOPT_POST] = 1;
        $curlOptions[CURLOPT_POSTFIELDS] = $this->_request['request'];
        $curlOptions[CURLOPT_RETURNTRANSFER] = 1;
        $curlOptions[CURLOPT_HTTPHEADER] = array();
        if (isset($clientOptions['http_headers'])) {
            $curlOptions[CURLOPT_HTTPHEADER] = $clientOptions['http_headers'];
        }
        // SOAP version has to be consolidated.
        if (!isset($clientOptions['soap_version']) && isset($this->_request['version'])) {
            $clientOptions['soap_version'] = $this->_request['version'];
        }
        // adding SoapAction Header
        if (isset($this->_request['action'])) {
            $curlOptions[CURLOPT_HTTPHEADER][] = 'SOAPAction: "' . $this->_request['action'] . '"';
	}
        // Timeout handling.
        if (isset($clientOptions['timeout'])) {
            $curlOptions[CURLOPT_TIMEOUT] = $clientOptions['timeout'];
        }
        if (isset($clientOptions['connection_timeout'])) {
            $curlOptions[CURLOPT_CONNECTTIMEOUT] = $clientOptions['connection_timeout'];
        }
        // Response validator support.
        if (isset($clientOptions['response_validator'])) {
            $curlOptions['response_validator'] = $clientOptions['response_validator'];
        }
        // HTTP_HOST substitution support.
        if (isset($clientOptions['host'])) {
            $curlOptions[CURLOPT_HTTPHEADER][] = "Host: {$clientOptions['host']}";
        }
        // HTTP basic auth.
        if (isset($clientOptions['login']) && isset($clientOptions['password']) ) {
            $curlOptions[CURLOPT_USERPWD] = $clientOptions['login'] . ":" . $clientOptions['password'];
            if (!isset($curlOptions[CURLOPT_HTTPAUTH])) {
                $curlOptions[CURLOPT_HTTPAUTH] = CURLAUTH_ANY;
            }
        }
        // Cookies.       
        if ($this->_request['cookies']) {
            $pairs = array();
            foreach ($this->_request['cookies'] as $k => $v) {
                $pairs[] = urlencode($k) . "=" . urlencode($v);
            }
            $curlOptions[CURLOPT_COOKIE] = join("; ", $pairs);
        } 
        $this->_handler = self::$_curl->addRequest($curlOptions, $clientOptions); 
    }
    
    /**
     * Wait for the request termination and return its result.
     * 
     * @return mixed
     */
    public function getResult()
    {
        if ($this->_isSynchronized) {
            return $this->_result;
        }
        $this->_isSynchronized = true;
        // Wait for a result.
        $response = self::$_curl->getResult($this->_handler);
        try {
	        if ($response['result_timeout'] == 'data') {
	            // Data timeout.
	            throw new SoapFault("HTTP", "Response is timed out");
	        }
	        if ($response['result_timeout'] == 'connect') {
	            // Native SoapClient compatible message.
	            throw new SoapFault("HTTP", "Could not connect to host");
	        }
	        if (!strlen($response['body'])) {
	        	// Empty body (case of DNS error etc.).
	        	throw new SoapFault("HTTP", "SOAP response is empty");
	        }
	        // Process cookies.
	        foreach ($this->_extractCookies($response['headers']) as $k => $v) {
	            if ($this->_isCookieValid($v)) {
	                $this->_client->__setCookie($k, $v);
	            }
	        }
	        // Run the SOAP handler.
        	$this->_result = $this->_client->__soapCallForced($response['body'], $this->_callArgs);
        } catch (Exception $e) {
        	// Add more debug parameters to SoapFault.
        	$e->location = $this->_request['location'];
        	$e->request = $this->_callArgs;
        	$e->response = $response;
        	throw $e;        	
        }
        return $this->_result;
    }
    
    /**
     * Wait for the connect is established.
     * It is useful when you need to begin a SOAP request and then
     * plan to execute a long-running code in parallel.
     * 
     * @return void
     */
    public function waitForConnect()
    {
        return self::$_curl->waitForConnect($this->_handler);
    }
    
    /**
     * Allow to use lazy-loaded result by implicit property access.
     * Call getResult() and return its property.
     * 
     * @param string $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this->getResult()->$key;
    }
    
    /**
     * Parse HTTP response headers and extract all the cookies.
     * 
     * @param string $headers
     * @return array        Array(cookies, body)
     */
    private function _extractCookies($headers)
    {
        $cookies = array();
        foreach (preg_split('/\r?\n/s', $headers) as $header) {
            @list($headername, $headervalue) = explode(':', $header);
            if (strtolower($headername) == "set-cookie") {
                $cookie = $this->_parseCookieValue(trim($headervalue));
                $cookies[$cookie['name']] = $cookie['value'];
            }
        }
        return $cookies;
    }
    
    /**
     * Parse Set-Cookie: header value.
     * 
     * @param string $headervalue
     * @return array
     */
    private function _parseCookieValue($headervalue)
    {
        $cookie = array(
            'expires' => null,
            'domain'  => null,
            'path'    => null,
            'secure'  => false
        );
        if (!strpos($headervalue, ';')) {
            // Only a name=value pair.
            list($cookie['name'], $cookie['value']) = array_map('trim', explode('=', $headervalue));
            $cookie['name']  = urldecode($cookie['name']);
            $cookie['value'] = urldecode($cookie['value']);
        } else {
            // Some optional parameters are supplied.
            $elements = explode(';', $headervalue);
            list($cookie['name'], $cookie['value']) = array_map('trim', explode('=', $elements[0]));
            $cookie['name']  = urldecode($cookie['name']);
            $cookie['value'] = urldecode($cookie['value']);
            for ($i = 1; $i < count($elements); $i++) {
                if (false === strpos($elements[$i], '=')) {
                    continue;
                }
                list($elName, $elValue) = array_map('trim', explode('=', $elements[$i]));
                if ('secure' == $elName) {
                    $cookie['secure'] = true;
                } elseif ('expires' == $elName) {
                    $cookie['expires'] = str_replace('"', '', $elValue);
                } elseif ('path' == $elName OR 'domain' == $elName) {
                    $cookie[$elName] = urldecode($elValue);
                } else {
                    $cookie[$elName] = $elValue;
                }
            }
        }
        return $cookie;
    }
    
    /**
     * Return true if the cookie is valid in a context of $this->_url.
     * 
     * @param array $cookie
     * @return bool
     */
    private function _isCookieValid($cookie)
    {
        // TODO
        // Now we assume that all cookies are valid no mater on domein,
        // expires, path, secure etc.
        // Note that original SoapClient only checks: path, domain, secure,
        // but NOT expires.
        return true;
    }
}


/**
 * cURL multi-request manager.
 *
 * Allows callbacks to be called, either with the full response (use
 * 'callback' key in addRequest()'s $curlOptions) or only the body (use
 * 'body_callback').
 * Those callback can be used to:
 *   - be notified of a result arrival
 *   - rework the response
 * The callback MUST return the response (original or modified).
 * 
 * Also support connection retries and response validation. To
 * implement validation and connection retry, use 'response_validator' 
 * key in addRequest() method with callback value.  The callback 
 * is passed two arguments:
 *   - response data
 *   - number of connection attempts performed
 * It must:
 *   - return true if the response is valid;
 *   - return false if the response is invalid and the request 
 *     needs to be retried;
 *   - throw an exception if macimum retry count is reached.
 */
class Dklab_SoapClient_Curl
{
    /**
     * Emergency number of connect tries.
     * Used if a response validator function is broken.
     */
    const MAX_TRIES = 5;
    
    /**
     * Multi handler from curl_milti_init.
     *
     * @var resource
     */
    private $_handler;
    
    /**
     * Responses retrieved by key.
     *
     * @var array
     */
    private $_responses = array();
    
    /**
     * Active requests keyed by request key.
     * object(handle, copy, nRetries)
     *
     * @var array
     */
    private $_requests = array();
    
    /**
     * Maximum number of running queries.
     * Overflowing ones are put on wait until another request finishes.
     *
     * @var int|null
     */
    private $_maxRunners = null;

    /**
     * Request that could not be launched because our queue is full ($this->_maxRunners).
     *
     * @var array
     */
    private $_waiters = array();

    /**
     * Create a new manager.
     */
    function __construct()
    {
        $this->_handler = curl_multi_init();
    }
    
    /**
     * Limit concurrently running requests.
     *
     * @param int|null $maxRunners Number of running requests.
     */
    public function throttle($maxRunners)
    {
        $this->_maxRunners = $maxRunners > 0 ? $maxRunners : null;
    }

    /**
     * Add a cURL request to the queue.
     * Request is specified by its cURL options.
     * 
     * @param array $curlOptions   Options to pass to cURL.
     * @param array $clientOptions Options to pass to cURL, from the client.
     * @return string              Identifier of the added request. 
     */
    public function addRequest($curlOptions, $clientOptions)
    {
        // Extract custom options.
        $responseValidator = null;
        if (isset($curlOptions['response_validator']) && is_callable($curlOptions['response_validator'])) {
            $responseValidator = $curlOptions['response_validator'];
            unset($curlOptions['response_validator']);
        }        
        $requestOptionsKeys = array('callback' => true, 'body_callback' => true);
        $requestOptions = array_intersect_key($curlOptions, $requestOptionsKeys);
        $curlOptions = array_diff_key($curlOptions, $requestOptionsKeys);

        // Create a cURL handler.
        $curlHandler = $this->_createCurlHandler($curlOptions, $clientOptions);
        
        $key = is_object($curlHandler) ? spl_object_hash($curlHandler) : (string)$curlHandler;
        // Add it to the queue. Note that we NEVER USE curl_copy_handle(),
        // because it seems to be buggy and corrupts the memory.
        $request = $this->_requests[$key] = (object)(array(
            'handle'     => $curlHandler,
            'options'    => $curlOptions, 
            'tries'      => 1,
            'validator'  => $responseValidator,
        ) + $requestOptions);
        if (isset($this->_maxRunners) && count($this->_requests) - count($this->_waiters) > $this->_maxRunners) {
            $this->_waiters[$key] = $request;
            return $key;
        }
        // Begin the processing.
        $this->_addCurlRequest($request, $key);
        return $key;
    }

    /**
     * Wait for a request termination and return its data.
     * In additional to curl_getinfo() results, the following keys are added:
     *   - "result":          cURL curl_multi_info_read() result code;
     *   - "headers":         HTTP response headers;
     *   - "body":            HTTP body;
     *   - "result_timeout":  null or ("connect" or "data") if a timeout occurred.
     * 
     * @param string $key
     * @return array
     */
    public function getResult($key)
    {
        if (null !== ($response = $this->_extractResponse($key))) {
            return $response;
        }
        do {
			// Execute all the handles.
			$nRunning = $this->_execCurl(true);
            // Try to extract the response.
            if (null !== ($response = $this->_extractResponse($key))) {
                //echo sprintf("-- %d %d %d\n", count($this->_responses), count($this->_requests));
                return $response;
            }
        } while ($nRunning > 0);
        return null;
    }

    /**
     * Wait for activity and tells which requests got a result.
     *
     * @param boolean $doConsume If true, the returned results are consumed as done by getResult(); if false the caller has to call getResult().
     *
     * @return null|array Array of received responses (or null if all requests have finished, and results have been grasped).
     */
    public function getAvailableResults($doConsume = true)
    {
        if (!count($this->_requests) && !count($this->_responses)) {
            return null;
        }

        while (!count($this->_responses) && $this->_execCurl(true) > 0) {
            // For now, implement a non-blocking mode, so break here. Skipping the break would implement a waitAvailableResults.
            break;
        }

        if (!$doConsume) {
            return $this->_responses;
        }

        $results = array();
        foreach ($this->_responses as $key => $request) {
            $results[$key] = $this->getResult($key);
        }
        return $results;
    }

    /**
     * Wait for a connection is established.
     * If a timeout occurred, this method does not throw an exception:
     * it is done within getResult() call only.
     * 
     * @param string $key
     * @return void
     */
    public function waitForConnect($key)
    {
        // Perform processing cycle until the request is really sent
        // and we begin to wait for a response.
        while (1) {
            if (!isset($this->_requests[$key])) {
                // The request is already processed.
                return;
            }
            $request = $this->_requests[$key];
            if (curl_getinfo($request->handle, CURLINFO_REQUEST_SIZE) > 0) {
                // Request is sent (its size is defined).
                return;
            }
            // Wait for a socket activity.
            $this->_execCurl(true);
        }
    }

    /**
     * Query cURL and store all the responses in internal properties.
     * Also deletes finished connections.
     *
     * @param int &$nRunning   If a new request is added after a retry, this
     *                         variable is incremented.
     * @return void
     */
    private function _storeResponses(&$nRunning = null)
    {
        while ($done = curl_multi_info_read($this->_handler)) {
            // Get a key and request for this handle. 
            $key = is_object($handle = $done['handle']) ? spl_object_hash($handle) : (string)$handle;
            $request = $this->_requests[$key];
            // Build the full response array and remove the handle from queue.
            $response = curl_getinfo($request->handle);
            $response['result'] = $done['result'];
            $response['result_timeout'] = $response["result"] === CURLE_OPERATION_TIMEOUTED? ($response["request_size"] <= 0? 'connect' : 'data') : null;
            // Split headers from body. Problem is, when tunnelled through a proxy,
            // we will have multiple header blocks, e.g.:
            // HTTP/1.0 200 Connection established
            // 
            // HTTP/1.1 200 OK
            // Date: Fri, 07 Jun 2013 08:14:21 GMT
            // Content-Type: text/xml;charset=utf-8
            // 
            // <.xml version='1.0' encoding='utf-8'.>
            // <soapenv:Envelope ...
            $response['body'] = curl_multi_getcontent($request->handle);
            while (strncmp($response['body'], 'HTTP/', 5) == 0) { // Hurmph, is that the best way to detect an HTTP header block?
                @list($response['headers'], $response['body']) = preg_split('/\r?\n\r?\n/s', $response['body'], 2);
            }
            curl_multi_remove_handle($this->_handler, $request->handle);
            // Process validation and possibly retry procedure.
            if (
                $response['result_timeout'] !== 'data'
                && $request->tries < self::MAX_TRIES
                && $request->validator 
                && !call_user_func($request->validator, $response, $request->tries)
            ) {
                // Initiate the retry.
                $request->tries++;
                // It is safe to add the handle again back to perform a retry
                // (including timed-out transfers, not only timed-out connections).
                $this->_addCurlRequest($request, $key);
                $nRunning++;
            } else {
                // No tries left or this is a DATA timeout which is never retried.
                // Remove this request from queue and save the response.
                unset($this->_requests[$key]);

                // Attach the callbacks to the response; do not execute here, but
                // wait until getResult(), to avoid stack exhaust if the callback
                // adds new requests.
                if (isset($request->callback)) {
                    $response['callback'] = $request->callback;
                }
                if (isset($request->body_callback) && isset($response['body'])) {
                    $response['body_callback'] = $request->body_callback;
                }

                $this->_responses[$key] = $response;
                curl_close($request->handle);

                $this->_refill();
            }
        }
    }

    /**
     * Triggers a waiting request, if a slot gets freed.
     * /!\ This function may be called with reentrancy, as it calls _addCurlRequest which can trigger a
     *     curl_multi_exec that in turn detects newly finished requests and calls _refill().
     */
    private function _refill()
    {
        if (isset($this->_alreadyFilling)) {
            return;
        }
        $this->_alreadyFilling = true;
        while (($toRun = count($this->_waiters))) {
            // Stop if reaching the maximum number of concurrently running requests.
            if (
                isset($this->_maxRunners)
                // $this->_requests includes the ones remaining to run, so subtract to get the count of running tasks.
                && $this->_maxRunners <= (count($this->_requests) - $toRun)
            ) {
                    break;
                }
            // Shift to get both key and value of first item.
            foreach ($this->_waiters as $key => $request) {
                break;
            }
            unset($this->_waiters[$key]);
            $this->_addCurlRequest($request, $key);
        }
        unset($this->_alreadyFilling);
    }
    
    /**
     * Extract response data by its key. Note that a next call to
     * _extractResponse() with the same key will return null.
     * 
     * @param string $key
     * @return mixed
     */
    private function _extractResponse($key)
    {
        if (isset($this->_responses[$key])) {
            $result = $this->_responses[$key];
            unset($this->_responses[$key]);
            if (isset($result['callback'])) {
                $result = $this->_callback($result['callback'], $result);
            }
            if (isset($result['body_callback']) && isset($result['body'])) {
                $result['body'] = $this->_callback($result['body_callback'], $result['body']);
            }
            return $result;
        }
        return null;
    }

    /**
     * Calls a callback.
     * Tries hard to detect if $callback includes fixed args to pass to the callback, after the callable itself.
     */
    private function _callback($callback, $params)
    {
        $params = func_get_args();
        $callable = array_shift($params);
        if (is_array($callable)) {
            if (count($callable) > 2) {
                $params = array_merge(array_splice($callable, 2), $params);
            }
        }
        return call_user_func_array($callable, $params);
    }
    
    /**
     * Create a cURL handler by cURL options.
     * Do not use curl_copy_handle(), it corrupts the memory sometimes!
     * 
     * @param array $curlOptions
     * @return resource 
     */
    private function _createCurlHandler($curlOptions, $clientOptions)
    {
        // SOAP protocol encoding is always UTF8 according to RFC.
        // SOAP 1.1 would like text/xml, SOAP 1.2 application/soap+xml, and PHP SOAP implementation cannot determine it
        // from the SDL, so either the caller mentions it explicitely, or we're on the default implementation (looking
        // like a 1.1).
        // On the "good news" side, as this Java-world-emanating spec is overly complex, no implementation manages to be
        // 100% correct, so servers are quite permissive (in fact nobody cares).
        // Here would have been glad to detect soap_version from the SDL, however it would require a reparsing of the
        // WSDL as PHP C implementation itself does not detect, or detects and forgets to tell. And reparsing the thing
        // is a mess (the same WSDL can have two bindings, one in 1.1 and one in 1.2, for the same method).
        // So do a "randomly-best-effort", and in fine let the caller decide if she wants to.
        if(!empty($clientOptions[CURLOPT_HTTPHEADER]) && !empty($clientOptions[CURLOPT_HTTPHEADER]['Content-Type'])) {
            $curlOptions[CURLOPT_HTTPHEADER][] = $clientOptions[CURLOPT_HTTPHEADER]['Content-Type'];
        }
        else {
            // Default header
            $curlOptions[CURLOPT_HTTPHEADER][] =
                isset($clientOptions['soap_version']) && $clientOptions['soap_version'] == SOAP_1_2
                ? "Content-Type: application/soap+xml; charset=utf-8"
                : "Content-Type: text/xml; charset=utf-8"
            ;
        }
        
        // Allow to do a request through a proxy
        if (!empty($clientOptions['proxy_host']) && !empty($clientOptions['proxy_port'])) {
            $curlOptions[CURLOPT_PROXY] = $clientOptions['proxy_host'].':'.$clientOptions['proxy_port'];
        }
        
        // TODO : allow other authentication methods
        // We assume than the authentication method is "Basic"
        if (!empty($clientOptions['proxy_login']) && !empty($clientOptions['proxy_password'])) {
            $curlOptions[CURLOPT_HTTPHEADER][] = "Proxy-Authorization: Basic ".base64_encode($clientOptions['proxy_login'].':'.$clientOptions['proxy_password']);
        }
        
        // Disable "100 Continue" header sending. This avoids problems with large POST.
    	$curlOptions[CURLOPT_HTTPHEADER][] = 'Expect:';
        // ALWAYS fetch with headers!
        $curlOptions[CURLOPT_HEADER] = 1;
        // The following two options are very important for timeouted reconnects!
        $curlOptions[CURLOPT_FORBID_REUSE] = 1;
        $curlOptions[CURLOPT_FRESH_CONNECT] = 1;
        // To be on a safe side, disable redirects.
        $curlOptions[CURLOPT_FOLLOWLOCATION] = false;
        // More debugging.
        $curlOptions[CURLINFO_HEADER_OUT] = true;
        
        // By default, we don't want to check the certificate
        $curlOptions[CURLOPT_SSL_VERIFYPEER] = false;
        if (!empty($clientOptions[CURLOPT_SSL_VERIFYPEER])) {
            $curlOptions[CURLOPT_SSL_VERIFYPEER] = $clientOptions[CURLOPT_SSL_VERIFYPEER];
        }
        
    	// Init and return the handle.
        $curlHandler = curl_init();
        curl_setopt_array($curlHandler, $curlOptions);
        return $curlHandler;
    }
    
    /**
     * Add a cURL request to the queue with initial connection.
     *
     * @param resource $h
     * @param string $key
     * @return void
     */
    private function _addCurlRequest(stdClass $request, $key)
    {
    	// Add a handle to the queue.
    	$min = min(
    		isset($request->options[CURLOPT_TIMEOUT])? $request->options[CURLOPT_TIMEOUT] : 100000, 
    		isset($request->options[CURLOPT_CONNECTTIMEOUT])? $request->options[CURLOPT_CONNECTTIMEOUT] : 100000
    	);
    	$request->timeout_at = microtime(true) + $min;
        curl_multi_add_handle($this->_handler, $request->handle);
        // Run initial processing loop without select(), because there are no
        // sockets connected yet.
        $this->_execCurl(false);
    }
    
    /**
     * Return the minimum delay till the next timeout happened.
     * This function may be optimized in the future.
     *
     * @return float
     */
    private function _getCurlNextTimeoutDelay()
    {
    	$time = microtime(true);
    	$min = 100000;
    	foreach ($this->_requests as $request) {
            if (isset($request->timeout_at)) {
    		// May be negative value here in case when a request is timed out,
    		// it's a quite common case.
    		$min = min($min, $request->timeout_at - $time);
            }
    	}
    	// Minimum delay is 1 ms to be protected from busy wait.
    	$min = max($min, 0.001);
    	return $min;
    }
    
    /**
     * Execute cURL processing loop and store all ready responses.
     *
     * @param bool    $waitForAction  If true, a socket action is waited before executing.
     * @return int    A number of requests left in the queue.
     */
    private function _execCurl($waitForAction)
    {
        $nRunningCurrent = null;
        if ($waitForAction) {
            curl_multi_select($this->_handler, $this->_getCurlNextTimeoutDelay());
        }
    	while (curl_multi_exec($this->_handler, $nRunningCurrent) == CURLM_CALL_MULTI_PERFORM);
        // Store appeared responses if present.
    	$this->_storeResponses($nRunningCurrent);
    	return $nRunningCurrent;
    }
}
