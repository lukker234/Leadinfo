<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/vendor/autoload.php';

// Instantiate App
$app = AppFactory::create();

// Add error middleware
$app->addErrorMiddleware(true, true, true);

// Add routes
$app->get('/', function (Request $request, Response $response) {
    $response->getBody()->write('Welcome');
    return $response;
});

// localhost:8081/api
// -> all companies in all tables
// localhost:8081/api?sort[name]=ASC
// -> all companies in all tables sorted by name
// localhost:8081/api?filter[country]=NL&sort[city]=ASC
// -> companies filtered by country sorted by city
// localhost:8081/api?filter[name]=Connell&sort[city]=ASC
// -> companies which have Connell (tip: mysql LIKE) in the name sorted by city
// output format needs to be JSON array with individual records

$app->get('/api', function (Request $request, Response $response) {
    $db = new PDO('mysql:host=db;dbname=db;port=3306', 'root', 'password');

    $rs = $db->query("SELECT company.id, company_nl.* FROM company INNER JOIN company_nl ON company.data_table = 'company_nl' AND company.data_unique_id = company_nl.unique_id");

    $result = $rs->fetchAll(PDO::FETCH_ASSOC);

    $response->getBody()->write(json_encode($result, JSON_PRETTY_PRINT));
    return $response;
});

$app->run();