<?php
chdir("C:\\Users\\dougl\\workspace6\\backend");
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$kernel = $app->make("Illuminate\\Contracts\\Http\\Kernel");
$request = \Illuminate\Http\Request::create("/horizon", "GET");
$response = $kernel->handle($request);
echo "Status: " . $response->getStatusCode() . PHP_EOL;
echo substr($response->getContent(), 0, 300) . PHP_EOL;
