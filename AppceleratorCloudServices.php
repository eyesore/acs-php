<?php // File: lib/AppceleratorCloudServices.php
/**
 * This library creates temporary files in which to store authentication cookies from ACS.
 * To properly secure your account, you should regularly run the companion temp file cleanup script.
 */

function pr($anything) {
    echo '<pre>';
    print_r($anything);
    echo '</pre>';
}

class AppceleratorCloudServices {
    /**
     * Your App Key
     * @var String
     */
    private $apiKey;

    /**
     * Username for usersLogin
     * @var String
     */
    private $username;

    /**
     * Password for login
     * @var String
     */
    private $password;

    /**
     * Curl Handle for Http request
     * @var cURL Handle
     */
    private $http;

    /**
     * Location of cookiejar and any other temp files.
     * @var string
     */
    private $tempDir;

    /**
     * Path to cookiejar
     * @var string
     */
    private $_cookieJar;

    /**
     * Base URI for API requests.
     * @var string
     */
    private $uriBase = 'https://api.cloud.appcelerator.com/v1';

    private $returnHeaders = false;

    /**
     * ACS query object
     * @constructor
     * @param {array} $options Constructor options. Must include username and password for ACS login.
     * @param {string} $options['username'] required ACS Username
     * @param {string} $options['password'] required ACS password
     * @param {string} $options['apiKey'] required ACS App Key
     * @param {string} $options['uriBase'] Base URI for requests - default = https://api.cloud.appcelerator.com/v1
     * @param {boolean} $options['returnHeaders'] Return request headers in response or not - default is false
     */
    public function __construct($options) {
        if(!isset($options['username']) || !isset($options['password']) || !isset($options['apiKey']))
            throw new InvalidArgumentException('ACS App Key, username, and password are required!');

        if(session_id() == '')
            session_start();

        // process options
        $this->username = $options['username'];
        $this->password = $options['password'];
        $this->apiKey = $options['apiKey'];
        if(isset($options['uriBase']))
            $this->uriBase = $options['uriBase'];
        if(isset($options['returnHeader']))
            $this->returnHeaders = $this->options['returnHeaders'];

        $this->tempDir = sys_get_temp_dir();

        $this->_prepareCookieJar();
        $this->_prepareRequest();
    }

    // public methods

    // MUTATE
    public function setApiKey($key) {
        $this->apiKey = $key;
    }

    public function setUsername($username) {
        $this->username = $username;
    }

    public function setPassword($password) {
        $this->password = $password;
    }

    public function setUriBase($uri) {
        $this->uriBase = $uri;
    }

    public function setReturnHeaders($bool) {
        $this->returnHeaders = $bool;
    }

    // ACCESS
    public function getApiKey() {
        return $this->apiKey;
    }

    public function getUser() {
        return $this->username;
    }

    public function getUriBase() {
        return $this->uriBase;
    }

    /**
     * Access the cURL handle to be used in requests.  Set additional options as desired.
     * @return {cURL handle} cURL handle used for all requests except login.
     */
    public function getHttp() {
        return $this->http;
    }

    public function getReturnHeaders() {
        return $this->returnHeaders;
    }

    public function getCurrentUser() {
        if(!empty($_SESSION['AppceleratorCloudServices']['current_user']))
            return $_SESSION['AppceleratorCloudServices']['current_user'];
        else
            return null;
    }

    // BEGIN API METHODS
    // USERS
    public function usersLogin() {
        // login uses its own handle
        $httpLogin = $this->_prepareLoginRequest();
        $url = $this->_appendApiKey($this->uriBase . '/users/login.json');
        $data = array('login' => $this->username, 'password' => $this->password);
        return $this->_processRequest($httpLogin, $url, 'post', $data);
    }

    // TODO must login as user being deleted
    // public function usersDelete($keep_photo = false) {
    //     $url = $this->_appendApiKey($this->uriBase . '/users/delete.json');

    // }

    public function usersQuery($options = array()) {
        $url = $this->_appendApiKey($this->uriBase . '/users/query.json');
        if(!empty($options))
            $url = $this->_appendParams($url, $options);
        return $this->_processRequest($this->http, $url);
    }

    public function usersSearch($options = array()) {
        $url = $this->_appendApiKey($this->uriBase . '/users/search.json');  // TODO move appendApiKey to process? tj
        if(!empty($options))
            $url = $this->_appendParams($url, $options);  // TODO move appendParams to process based on method
        return $this->_processRequest($this->http, $url);
    }

    /**
     * Get data for a single user or users
     * @param  {mixed}  $user_ids Array of user ids, or a single user id (string or int)
     * @param  {integer} $response_json_depth
     * @return {object} ACS response
     */
    public function usersShow($user_ids, $response_json_depth = 3) {
        $url = $this->_appendApiKey($this->uriBase . '/users/show.json');
        $data = array('response_json_depth' => $response_json_depth);
        if(is_array($userIds))
            $data['user_ids'] = implode(',', $user_ids);
        else
            $data['user_id'] = $user_ids;
        $url = $this->_appendParams($url, $data);
        return $this->_processRequest($this->http, $url);
    }

    // PUSH NOTIFICATIONS
    public function pushNotificationNotify($channel, $payload, $ids = null, $friends = null) {
        $url = $this->_appendApiKey($this->uriBase . '/push_notification/notify.json');
        $data = array(
            'channel' => $channel, // required
            'payload' => json_encode($payload),
            );
        if(!is_null($ids))
            $data['ids'] = implode(',', $ids);
        if(!is_null($friends))
            $data['friends'] = $friends;

        return $this->_processRequest($this->http, $url, 'post', $data);
    }

    // END API METHODS

    // private methods
    private function _prepareCookieJar() {
        if(!empty($_SESSION['AppceleratorCloudServices']['cookie_jar'])) {
            // update modified time so it doesn't get cleaned up
            pr('Skipping login');
            $this->_cookieJar = $_SESSION['AppceleratorCloudServices']['cookie_jar'];
            return touch($this->_cookieJar);
        }

        $cookieFile = tempnam($this->tempDir, 'ACS');
        if(!$cookieFile)
            throw new UnexpectedValueException('There was a problem storing your authentication cookie.');

        $this->_cookieJar = $cookieFile;
        $loginResponse = $this->usersLogin();

        $_SESSION['AppceleratorCloudServices']['cookie_jar'] = $this->_cookieJar;
        $_SESSION['AppceleratorCloudServices']['current_user'] = $loginResponse->response->users[0];
        return true;
    }

    private function _appendApiKey($url) {
        return $url . "?key={$this->apiKey}";
    }

    private function _appendParams($url, $params) {
        foreach($params as $k => $v) {
            $url .= "&{$k}={$v}";
        }
        return $url;
    }

    private function _prepareRequest() {
        $this->http = curl_init();
        curl_setopt($this->http, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->http, CURLOPT_COOKIEFILE, $this->_cookieJar);
    }

    private function _prepareLoginRequest() {
        $httpLogin = curl_init();
        curl_setopt($httpLogin, CURLOPT_COOKIEJAR, $this->_cookieJar);
        curl_setopt($httpLogin, CURLOPT_FORBID_REUSE, true);
        return $httpLogin;
    }

    /**
     * Execute the request, and process the response.
     * @param  {cURL handle} $http Curl handle to be used for request. TODO real obj type tj
     * @param {string} url for request, including query string
     * @param {string} method case insensitive http verb
     * @param {string} data Post data to be sent with request
     * @return {string} JSON response from ACS.
     */
    private function _processRequest($http, $url, $method = 'get', $data = array()) {
        curl_setopt($http, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($http, CURLOPT_HEADER, true);
        curl_setopt($http, CURLOPT_FAILONERROR, false);
        curl_setopt($http, CURLOPT_URL, $url);
        switch(strtolower($method)) {
            case 'post':
                curl_setopt($http, CURLOPT_POST, true);
            break;
            case 'put':
                curl_setopt($http, CURLOPT_PUT, true);
            break;
            case 'delete':
                curl_setopt($http, CURLOPT_CUSTOMREQUEST, 'DELETE');
            break;
            case 'connect':
                curl_setopt($http, CURLOPT_CUSTOMREQUEST, 'CONNECT');
            break;
            case 'get':
            default:
                curl_setopt($http, CURLOPT_HTTPGET, true);
        }
        if(!empty($data))
            curl_setopt($http, CURLOPT_POSTFIELDS, $data);

        // GO!
        $response = curl_exec($http);
        $requestMeta = curl_getinfo($http);
        $error = curl_error($http);
        if(!empty($error))
            throw new RuntimeException($error);

        // server often returns status 100 continue, so work backwards...
        $responseArray = explode("\r\n\r\n", $response);
        $body = array_pop($responseArray);
        $headers = array_pop($responseArray);
        $body = json_decode($body);
        if($body->meta->status !== 'ok')
            throw new RuntimeException($body->meta->message);

        return $this->returnHeaders ? compact('headers', 'body') : $body;
    }
}