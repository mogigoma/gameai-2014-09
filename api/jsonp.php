<?php

function failure($msg) {
  echo json_encode([
    "result" => "failure",
    "reason" => $msg
  ]);
  exit();
}

// Hide my shame at using PHP.
header_remove("X-Powered-By");

// This API method only works with GET, and needs some params.
if ($_SERVER["REQUEST_METHOD"] !== "GET" || empty($_GET))
{
  header("Content-type: text/html");
  echo(file_get_contents("jsonp.html"));
  exit();
}

// Below this point, everything is JSON, though not necessarily JSONP.
header("Content-type: application/json");

// There needs to be a callback parameter, otherwise this request is wacky.
if (!array_key_exists("callback", $_GET))
  failure("No 'callback' parameter was given, which essential for JSONP.");
$callback = $_GET["callback"];

// There needs to be a method parameter, otherwise this request is wacky.
if (!array_key_exists("method", $_GET))
  failure("No 'method' parameter was given, which essential for proxying.");
$method = $_GET["method"];

// Confirm that the callback is a valid function name.
$methods = ["bid", "login", "new-game", "play", "register", "status"];
if (!in_array($method, $methods))
  failure("The method '$method' is not one of the supported API endpoints.");

// Remove callback from the GET parameters, so we don't send it to the endpoint.
unset($_GET["callback"]);

// Create the stream context, with our options embedded.
$ctx = stream_context_create([
  "http" => [
    "method" => "POST",
    "header"  => "Content-type: application/json",
    "content" => json_encode($_GET)
  ]
]);

// Execute the HTTP request, hopefully we're safe against unintended SSRF!
$json = file_get_contents("http://gameai.skullspace.ca/api/$method/", false, $ctx);

// JSONP it up!
echo "$callback($json);";

?>
