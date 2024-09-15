<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use DI\ContainerBuilder;
use App\Controllers\CompanyController;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/vendor/autoload.php';

$app = AppFactory::create();
$app->addErrorMiddleware(true, true, true);

function createPDO(): PDO {
    return new PDO('mysql:host=db;dbname=db;port=3306', 'root', 'password', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions([
    'db' => function () {
        return createPDO();
    },
    CompanyController::class => function ($c) {
        return new CompanyController($c->get('db'));
    }
]);
$container = $containerBuilder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();
$app->addErrorMiddleware(true, true, true);

$app->get('/api', function (Request $request, Response $response) {
    $controller = $this->get(CompanyController::class);
    return $controller->getCompanies($request, $response);
});

$app->run();
