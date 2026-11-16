<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

$routes->group('', ['filter' => 'nostore'], static function (RouteCollection $routes) {
    $routes->get('register', 'AuthController::registerForm', ['filter' => 'csrf']);
    $routes->post('register', 'AuthController::register', ['filter' => ['csrf', 'authattempts:register']]);
    $routes->get('login', 'AuthController::loginForm', ['filter' => 'csrf']);
    $routes->post('login', 'AuthController::login', ['filter' => ['csrf', 'authattempts:login']]);
    $routes->post('logout', 'AuthController::logout', ['filter' => ['csrf', 'authenticated']]);
    $routes->get('borrower/dashboard', 'DashboardController::borrower', ['filter' => ['authenticated:borrower', 'csrf']]);
    $routes->get('admin/dashboard', 'DashboardController::admin', ['filter' => ['authenticated:admin', 'csrf']]);
});
