<?php

/**
 * ISPsystem module for interaction with API
 *
 * PHP version 5
 *
 * @category  WHMCS_Plugins
 * @package   ISPsystem
 * @author    ISPsystem LLC <support@ispsystem.com>
 * @copyright 2019 ISPsystem LLC
 * @license   Proprietary http://www.ispsystem.com/
 * @link      http://www.ispsystem.com/
 */

namespace ISPsystem;

/**
 * Error representation
 *
 * @category  API
 * @package   ISPsystem
 * @author    ISPsystem LLC <support@ispsystem.com>
 * @copyright 2019 ISPsystem LLC
 * @license   Proprietary http://www.ispsystem.com/
 * @link      http://www.ispsystem.com/
 */
class APIError extends \Exception // phpcs:ignore
{
    /**
     * Constract error object
     *
     * @param int       $code     - error code
     * @param string    $message  - error message
     * @param \Exception $previous - previous error
     */
    public function __construct($code, $message, $previous = null)
    {
          parent::__construct($message, $code, $previous);
    }

    /**
     * Format error to string
     *
     * @return string
     */
    public function __toString()
    {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}

/**
 * Logic error representation
 *
 * @category  API
 * @package   ISPsystem
 * @author    ISPsystem LLC <support@ispsystem.com>
 * @copyright 2019 ISPsystem LLC
 * @license   Proprietary http://www.ispsystem.com/
 * @link      http://www.ispsystem.com/
 */
class LogicError extends \Exception // phpcs:ignore
{
    /**
     * Constract LogicError object
     *
     * @param string    $message  - error message
     * @param \Exception $previous - previous error
     */
    public function __construct($message, $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Format error to string
     *
     * @return string
     */
    public function __toString()
    {
        return __CLASS__ . ": {$this->message}\n";
    }
}

/**
 * HttpResult collection
 *
 * @category  API
 * @package   ISPsystem
 * @author    ISPsystem LLC <support@ispsystem.com>
 * @copyright 2019 ISPsystem LLC
 * @license   Proprietary http://www.ispsystem.com/
 * @link      http://www.ispsystem.com/
 */
class HttpResult // phpcs:ignore
{
    public $code = 0;
    public $body = "";
    public $url = "";

    /**
     * Constract HttpResult object
     *
     * @param int    $code - HTTP response code
     * @param string $body - body of response
     * @param string $url  - request URL
     */
    public function __construct($code, $body, $url)
    {
        $this->code = $code;
        $this->body = $body;
        $this->url = $url;
    }
}

/**
 * Http query
 *
 * @category  API
 * @package   ISPsystem
 * @author    ISPsystem LLC <support@ispsystem.com>
 * @copyright 2019 ISPsystem LLC
 * @license   Proprietary http://www.ispsystem.com/
 * @link      http://www.ispsystem.com/
 */
class Http // phpcs:ignore
{
    private $_curl = null;
    private $_url = "";
    private $_timeout = 0;

    /**
     * Constract Http object
     *
     * @param string $url - root url for querying
     */
    public function __construct($url)
    {
        if (strlen($url) == 0) {
            throw new LogicError("empty url");
        }
        $this->_url = $url;
        $this->_curl = curl_init();
    }

    /**
     * Destruct Http object
     */
    public function __destruct()
    {
        if ($this->_curl) {
            curl_close($this->_curl);
        }
    }

    /**
     * Prepare HTTP query (curl common initialisation)
     *
     * @param string $url     - concrete URL for querying
     * @param array  $headers - query headers
     * @param array  $cookies - query cookies
     * @param int    $timeout - query timeout
     *
     * @return void
     */
    private function init($url, $headers, $cookies, $timeout)
    {
        $this->_timeout = $timeout;
        $http_or_https = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        curl_reset($this->_curl);
        curl_setopt($this->_curl, CURLOPT_URL, $url);
        curl_setopt($this->_curl, CURLOPT_CONNECTTIMEOUT, $this->_timeout);
        curl_setopt($this->_curl, CURLOPT_TIMEOUT, $this->_timeout);
        curl_setopt($this->_curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->_curl, CURLOPT_HEADER, false);
        curl_setopt($this->_curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($this->_curl, CURLOPT_MAXREDIRS, 30);
        curl_setopt($this->_curl, CURLOPT_PROTOCOLS, $http_or_https);
        curl_setopt($this->_curl, CURLOPT_COOKIEFILE, "");
        if (substr($this->_url, 0, 5) == "https") {
            curl_setopt($this->_curl, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($this->_curl, CURLOPT_SSL_VERIFYPEER, 0);
        }
        $val = array();
        foreach ($headers as $key => $value) {
            array_push($val, "{$key}: {$value}");
        }
        foreach ($cookies as $key => $value) {
            array_push($val, "Cookie: {$key}={$value}");
        }
        curl_setopt($this->_curl, CURLOPT_HTTPHEADER, $val);
    }

    /**
     * Execute HTTP query
     *
     * @return HttpResult
     */
    private function exec()
    {
        $start = time();
        do {
            $result = curl_exec($this->_curl);
            if ($result === false) {
                $error = curl_error($this->_curl);
                throw new LogicError("can not perform operation: {$error}");
            }
            $code = curl_getinfo($this->_curl, CURLINFO_RESPONSE_CODE);
            if ($code != 503) {
                return new HttpResult(
                    $code,
                    $result,
                    curl_getinfo($this->_curl, CURLINFO_EFFECTIVE_URL)
                );
            }
            // Sleep for 500 ms
            usleep(500 * 1000);
        } while ((time() - $start) < $this->_timeout);

        throw new LogicError("connection timeout"); // @codeCoverageIgnore
    }

    /**
     * Perform POST request to resource
     *
     * @param string $path    - path to server resource
     * @param string $data    - POST data
     * @param array  $headers - HTTP headers
     * @param array  $cookies - HTTP cookies
     * @param int    $timeout - HTTP timeout
     *
     * @return HttpResult
     */
    public function post(
        $path = "",
        $data = "",
        $headers = array(),
        $cookies = array(),
        $timeout = 60
    ) {
        $url = $this->_url;
        if ($path) {
            $url = "{$url}/{$path}";
        }
        $this->init(
            $url,
            $headers,
            $cookies,
            $timeout
        );
        curl_setopt($this->_curl, CURLOPT_POST, true);
        curl_setopt($this->_curl, CURLOPT_POSTFIELDS, $data);
        return $this->exec();
    }

    /**
     * Perform GET request to resource
     *
     * @param string $path    - path to server resource
     * @param array  $params  - query params
     * @param array  $headers - HTTP headers
     * @param array  $cookies - HTTP cookies
     * @param int    $timeout - HTTP timeout
     *
     * @return HttpResult
     */
    public function get(
        $path = "",
        $params = array(),
        $headers = array(),
        $cookies = array(),
        $timeout = 60
    ) {
        $query_param = http_build_query($params);
        $url = $this->_url;
        if ($path) {
            $url = "{$url}/{$path}";
        }
        $url = count($params) > 0
               ? "{$url}?{$query_param}"
               : "{$url}";
        $this->init(
            $url,
            $headers,
            $cookies,
            $timeout
        );
        curl_setopt($this->_curl, CURLOPT_HTTPGET, true);
        return $this->exec();
    }

    /**
     * Perform DELETE request to resource
     *
     * @param string $path    - path to server resource
     * @param string $data    - request data
     * @param array  $headers - HTTP headers
     * @param array  $cookies - HTTP cookies
     * @param int    $timeout - HTTP timeout
     *
     * @return HttpResult
     */
    public function delete(
        $path,
        $data,
        $headers,
        $cookies,
        $timeout = 60
    ) {
        $this->init(
            "{$this->_url}/{$path}",
            $headers,
            $cookies,
            $timeout
        );
        curl_setopt($this->_curl, CURLOPT_CUSTOMREQUEST, "DELETE");
        if (strlen($data) > 0) {
            curl_setopt($this->_curl, CURLOPT_POSTFIELDS, $data);
        }
        return $this->exec();
    }
}

/**
 * Logic error representation
 *
 * @category  API
 * @package   ISPsystem
 * @author    ISPsystem LLC <support@ispsystem.com>
 * @copyright 2019 ISPsystem LLC
 * @license   Proprietary http://www.ispsystem.com/
 * @link      http://www.ispsystem.com/
 */
class API // phpcs:ignore
{
    const ERROR_REQUEST_HANDLER_NOT_FOUND = 1106;
    const ERROR_INSTANCE_NOT_FOUND = 3080;

    private $_url = "";
    private $_headers = array();
    private $_cookies = array();

    /**
     * Constract API object
     *
     * @param string $addr - server address
     * @param int    $port - server port
     * @param bool   $ssl  - using SSL connection
     */
    public function __construct($addr, $port = 443, $ssl = true)
    {
        if (strlen($addr) == 0) {
            throw new LogicError("invalid API address");
        }
        $this->_url = ($ssl ? "https://" : "http://") . $addr;
        if (($ssl && $port != 443) || (!$ssl && $port != 80)) {
            $this->_url .= ":$port";
        }
    }

    /**
     * Add header to request
     *
     * @param string $name  - header name
     * @param string $value - header value
     *
     * @return void
     */
    public function addHeader($name, $value)
    {
        $this->_headers[$name] = $value;
    }

    /**
     * Add cookie to request
     *
     * @param string $name  - cookie name
     * @param string $value - cookie value
     *
     * @return void
     */
    public function addCookie($name, $value)
    {
        $this->_cookies[$name] = $value;
    }

    /**
     * Get response of request in object representation
     *
     * @param Httpresult $r - http response
     *
     * @return object
     */
    private function getResponse($r)
    {
        $response = json_decode($r->body);
        if ($r->code >= 400) {
            if (!empty($response) && property_exists($response, "error")) {
                $error = $response->{"error"};
                $code = property_exists($error, "code") ? (int)$error->{"code"} : 0;
                $msg = property_exists($error, "msg") ? (string)$error->{"msg"} : "";
                throw new APIError($code, empty($msg) ? "unknown error $code" : $msg);
            }
            throw new APIError($r->code, "unknown error");
        }
        return $response;
    }

    /**
     * Perform POST request to API
     *
     * @param string $path - path to server resource
     * @param string $data - POST data
     *
     * @return HttpResult
     */
    public function post($path, $data = "{}")
    {
        $http = new Http($this->_url);
        if (gettype($data) != "string") {
            $data = json_encode($data);
        }
        $r = $http->post($path, $data, $this->_headers, $this->_cookies);
        logModuleCall(
            "ISPsystem\API",
            __METHOD__,
            array(
                "headers" => $this->_headers,
                "cookies" => $this->_cookies,
                "data" => $data,
            ),
            $r
        );
        return $this->getResponse($r, $data);
    }

    /**
     * Perform GET request to API
     *
     * @param string $path   - path to server resource
     * @param array  $params - request params
     *
     * @return HttpResult
     */
    public function get($path, $params = array())
    {
        $http = new Http($this->_url);
        $r = $http->get($path, $params, $this->_headers, $this->_cookies);
        logModuleCall(
            "ISPsystem\API",
            __METHOD__,
            array(
                "headers" => $this->_headers,
                "cookies" => $this->_cookies,
                "data" => $params,
            ),
            $r
        );
        return $this->getResponse($r, $params);
    }

    /**
     * Perform DELETE request to API
     *
     * @param string $path - path to server resource
     * @param string $data - POST data
     *
     * @return HttpResult
     */
    public function delete($path, $data = "")
    {
        $http = new Http($this->_url);
        $r = $http->delete($path, $data, $this->_headers, $this->_cookies);
        logModuleCall(
            "ISPsystem\API",
            __METHOD__,
            array(
                "headers" => $this->_headers,
                "cookies" => $this->_cookies,
                "data" => $data,
            ),
            $r
        );
        return $this->getResponse($r, $data);
    }

    /**
     * Get API url
     *
     * @return string
     */
    public function url()
    {
        return $this->_url;
    }
}

/**
 * Auth|connection to API
 *
 * @category  API
 * @package   ISPsystem
 * @author    ISPsystem LLC <support@ispsystem.com>
 * @copyright 2019 ISPsystem LLC
 * @license   Proprietary http://www.ispsystem.com/
 * @link      http://www.ispsystem.com/
 */
class Auth // phpcs:ignore
{
    private $_api = null;
    private $_email = "";
    private $_session = "";

    /**
     * Construct Auth object
     *
     * @param API    $api   - API connection
     * @param string $email - email of API user
     */
    public function __construct($api, $email)
    {
        $this->_api = clone $api;
        $this->_email = $email;
    }

    /**
     * Desctruct Auth object and close session
     */
    public function __destruct()
    {
        if (strlen($this->_session) > 0) {
            $this->_api->delete("auth/v3/session/{$this->_session}");
        }
    }

    /**
     * Init auth object by user password
     *
     * @param string $password - user password
     *
     * @return void
     */
    private function initByPassword($password)
    {
        $response = $this->_api->post(
            "auth/v3/auth",
            array(
                "email" => $this->_email,
                "password" => $password
            )
        );
        $this->_session = (string)($response->{"session"});
        $this->_api->addHeader("ISP-Session", $this->_session);
        $this->_api->addCookie("ses6", $this->_session);
    }

    /**
     * Init auth object by existing admin connection
     *
     * @param Auth $admin - admin connection
     *
     * @return void
     */
    private function initByAdmin($admin)
    {
        $encoded_email = urlencode($this->_email);
        $key_response = $admin->post("auth/v3/user/{$encoded_email}/key");
        $key = (string)($key_response->{"key"});
        if (strlen($key) == 0) {
            throw new LogicError("can not get auth key"); // @codeCoverageIgnore
        }
        $response = $this->_api->post(
            "auth/v3/auth_by_key",
            array(
                "key" => $key
            )
        );
        $this->_session = (string)$response->{"session"};
        $this->_api->addHeader("ISP-Session", $this->_session);
        $this->_api->addCookie("ses6", $this->_session);
    }

    /**
     * Get admin connection by admin email and password
     *
     * @param API    $api      - API connection
     * @param string $email    - admin email
     * @param string $password - admin password
     *
     * @return Auth
     */
    public static function admin($api, $email, $password)
    {
        $i = new self($api, $email);
        $i->initByPassword($password);
        return $i;
    }

    /**
     * Get user connection by user email and admin connection
     *
     * @param Auth   $admin - admin auth object
     * @param string $email - admin email
     *
     * @return Auth
     */
    public static function user($admin, $email)
    {
        $instance = new self($admin->api(), $email);
        $instance->initByAdmin($admin);
        return $instance;
    }

    /**
     * Perform POST request to API via auth object
     *
     * @param string $path - path to server resource
     * @param string $data - POST data
     *
     * @return HttpResult
     */
    public function post($path, $data = "{}")
    {
        return $this->_api->post($path, $data);
    }

    /**
     * Perform GET request to API via auth object
     *
     * @param string $path   - path to server resource
     * @param string $params - query params
     *
     * @return HttpResult
     */
    public function get($path, $params = array())
    {
        return $this->_api->get($path, $params);
    }

    /**
     * Perform DELETE request to API via auth object
     *
     * @param string $path - path to server resource
     * @param string $data - data
     *
     * @return HttpResult
     */
    public function delete($path, $data = "")
    {
        return $this->_api->delete($path, $data);
    }

    /**
     * Get API connection
     *
     * @return API
     */
    public function api()
    {
        return $this->_api;
    }

    /**
     * Get user session
     *
     * @return string
     */
    public function session()
    {
        return $this->_session;
    }

    /**
     * Get user session and clear it in object
     *
     * @return string
     */
    public function clearSession()
    {
        $session = $this->session();
        $this->_session = "";
        return $session;
    }
}

/**
 * Auth|connection to API v4
 *
 * @category  API
 * @package   ISPsystem
 * @author    ISPsystem LLC <support@ispsystem.com>
 * @copyright 2021 ISPsystem LLC
 * @license   Proprietary http://www.ispsystem.com/
 * @link      http://www.ispsystem.com/
 */
class AuthV4 // phpcs:ignore
{
    private $_api = null;
    private $_email = "";
    private $_token = "";

    /**
     * Construct AuthV4 object
     *
     * @param API    $api   - API connection
     * @param string $email - email of API user
     */
    public function __construct($api, $email)
    {
        $this->_api = clone $api;
        $this->_email = $email;
    }

    /**
     * Desctruct AuthV4 object and close delete session token
     */
    public function __destruct()
    {
        if (strlen($this->_token) > 0) {
            $this->_api->delete("auth/v4/token/{$this->_token}");
        }
    }

    /**
     * Get admin connection by admin email and password
     *
     * @param API    $api      - API connection
     * @param string $email    - admin email
     * @param string $password - admin password
     *
     * @return AuthV4
     */
    public static function admin($api, $email, $password)
    {
        $i = new self($api, $email);
        $i->initByPassword($password);
        return $i;
    }

    /**
     * Get user connection by user email and admin connection
     *
     * @param Auth   $admin - admin auth object
     * @param string $email - admin email
     *
     * @return Auth
     */
    public static function user($admin, $email)
    {
        $instance = new self($admin->api(), $email);
        $instance->initByAdmin($admin);
        return $instance;
    }

    /**
     * Perform POST request to API via auth v4 object
     *
     * @param string $path - path to server resource
     * @param string $data - POST data
     *
     * @return HttpResult
     */
    public function post($path, $data = "{}")
    {
        return $this->_api->post($path, $data);
    }

    /**
     * Perform GET request to API via auth object
     *
     * @param string $path   - path to server resource
     * @param string $params - query params
     *
     * @return HttpResult
     */
    public function get($path, $params = array())
    {
        return $this->_api->get($path, $params);
    }

    /**
     * Perform DELETE request to API via auth object
     *
     * @param string $path - path to server resource
     * @param string $data - data
     *
     * @return HttpResult
     */
    public function delete($path, $data = "")
    {
        return $this->_api->delete($path, $data);
    }

    /**
     * Get API connection
     *
     * @return API
     */
    public function api()
    {
        return $this->_api;
    }

    /**
     * Get user session token
     *
     * @return string
     */
    public function session()
    {
        return $this->_token;
    }

    /**
     * Get user session token and clear it in object
     *
     * @return string
     */
    public function clearSession()
    {
        $token = $this->session();
        $this->_token = "";
        return $token;
    }

    /**
     * Init Auth v4 object by user e-mail and password
     *
     * @param string $password - user password
     *
     * @return void
     */
    private function initByPassword($password)
    {
        $response = $this->_api->post(
            "auth/v4/public/token",
            array(
                "email" => $this->_email,
                "password" => $password
            )
        );
        $this->_token = (string)($response->{"token"});
        $this->_api->addHeader("ISP-Session", $this->_token);
        $this->_api->addHeader("x-xsrf-token", $this->_token);
        $this->_api->addCookie("ses6", $this->_token);
    }

    /**
     * Init Auth v4 object by existing admin connection
     *
     * @param Auth $admin - admin connection
     *
     * @return void
     */
    private function initByAdmin($admin)
    {
        $encoded_email = urlencode($this->_email);
        $key_response = $admin->post("auth/v4/user/{$encoded_email}/key");
        $key = (string)($key_response->{"key"});
        if (strlen($key) == 0) {
            throw new LogicError("can not get auth key"); // @codeCoverageIgnore
        }
        $response = $this->_api->post(
            "auth/v4/public/key",
            array(
                "key" => $key
            )
        );
        $this->_token = (string)$response->{"token"};
        $this->_api->addHeader("ISP-Session", $this->_token);
        $this->_api->addHeader("x-xsrf-token", $this->_token);
        $this->_api->addCookie("ses6", $this->_token);
    }
}
