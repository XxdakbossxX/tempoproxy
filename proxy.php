<?php
session_start();

// Set CORS headers to allow cross-origin requests
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Handle OPTIONS request for CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("HTTP/1.1 204 No Content");
    exit();
}

// Ensure a URL parameter is provided
if (!isset($_GET['url'])) {
    die(json_encode(["error" => "No URL specified."]));
}

// Decode and validate the URL parameter
$url = urldecode($_GET['url']);
if (stripos($url, "http://") !== 0 && stripos($url, "https://") !== 0) {
    $url = "https://" . $url;
}
$url = filter_var($url, FILTER_VALIDATE_URL);
if (!$url) {
    die(json_encode(["error" => "Invalid URL."]));
}

// Set up session-based cookies
$cookieFile = sys_get_temp_dir() . "/proxy_cookie_" . session_id() . ".txt";

// Initialize cURL
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Prevent long-running requests

// Forward POST data if applicable
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
}

// Execute the request
$response = curl_exec($ch);
if ($response === false) {
    die(json_encode(["error" => curl_error($ch)]));
}
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

// Ensure JSON output for API mode
if (isset($_GET['api']) && $_GET['api'] === 'true') {
    header("Content-Type: application/json");
    echo $response;
    exit();
}

// RAW mode: Serve raw content without HTML processing
if (isset($_GET['raw']) && $_GET['raw'] === 'true') {
    header("Content-Type: text/plain");
    echo $response;
    exit();
}

// Process HTML pages for proxy routing
if (strpos($contentType, "text/html") !== false) {
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML($response, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();

    // Rewrite all links to pass through the proxy
    $proxyBase = "http://temproxy.whf.bz/proxy.php?url=";
    $tags = ['a' => 'href', 'img' => 'src', 'script' => 'src', 'link' => 'href', 'form' => 'action'];
    
    foreach ($tags as $tag => $attribute) {
        foreach ($doc->getElementsByTagName($tag) as $element) {
            $attrValue = $element->getAttribute($attribute);
            if (!empty($attrValue)) {
                $element->setAttribute($attribute, $proxyBase . urlencode($attrValue));
            }
        }
    }

    $response = $doc->saveHTML();
}

// Return final processed content
header("Content-Type: $contentType");
echo $response;
?>
