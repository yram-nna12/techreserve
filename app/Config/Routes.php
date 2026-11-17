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

$routes->group('admin/resources', ['filter' => ['nostore', 'authenticated:admin', 'csrf']], static function (RouteCollection $routes) {
    $routes->get('', 'ResourceController::adminIndex');
    $routes->get('new', 'ResourceController::create');
    $routes->post('', 'ResourceController::store');
    $routes->get('(:num)/edit', 'ResourceController::edit/$1');
    $routes->post('(:num)/edit', 'ResourceController::update/$1');
    $routes->get('(:num)/archive', 'ResourceController::archiveForm/$1');
    $routes->post('(:num)/archive', 'ResourceController::archive/$1');
    $routes->get('(:num)', 'ResourceController::adminShow/$1');
});

$routes->group('borrower/resources', ['filter' => ['nostore', 'authenticated:borrower', 'csrf']], static function (RouteCollection $routes) {
    $routes->get('', 'ResourceController::catalog');
    $routes->get('(:num)', 'ResourceController::show/$1');
});

$routes->group('borrower/reservations', ['filter' => ['nostore', 'authenticated:borrower', 'csrf']], static function (RouteCollection $routes) {
    $routes->get('', 'ReservationController::index');
    $routes->get('new', 'ReservationController::create');
    $routes->post('preview', 'ReservationController::preview');
    $routes->post('', 'ReservationController::store');
    $routes->get('(:num)', 'ReservationController::show/$1');
    $routes->post('(:num)/cancel', 'ReservationController::cancel/$1');
    $routes->post('(:num)/payments', 'PaymentController::initiate/$1');
    $routes->post('(:num)/payments/(:num)/verify', 'PaymentController::verify/$1/$2');
});
$routes->group('borrower/payments', ['filter' => ['nostore', 'authenticated:borrower', 'csrf']], static function (RouteCollection $routes) {
    $routes->get('return', 'PaymentController::returnFromGateway');
});
$routes->group('notifications', ['filter' => ['nostore', 'authenticated', 'csrf']], static function (RouteCollection $routes) {
    $routes->get('', 'NotificationController::index');
    $routes->post('read-all', 'NotificationController::markAllRead');
    $routes->post('(:num)/read', 'NotificationController::markRead/$1');
});
$routes->group('admin/reservations', ['filter' => ['nostore', 'authenticated:admin', 'csrf']], static function (RouteCollection $routes) {
    $routes->get('', 'ReservationController::adminIndex');
    $routes->get('(:num)', 'ReservationController::adminShow/$1');
    $routes->post('(:num)/decision', 'ReservationController::decide/$1');
});
