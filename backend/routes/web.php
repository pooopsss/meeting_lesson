<?php

/** @var \Laravel\Lumen\Routing\Router $router */

$router->get('/', function () use ($router) {
    return response()->json([
        'app' => 'Lesson 2 Backend',
        'version' => $router->app->version(),
        'status' => 'ok',
    ]);
});

$router->get('/api/hello', 'ExampleController@hello');

$router->post('/api/register', 'AuthController@register');
$router->post('/api/login', 'AuthController@login');

$router->group(['middleware' => 'auth'], function () use ($router) {
    $router->get('/api/meetings', 'MeetingController@index');
    $router->post('/api/meetings', 'MeetingController@store');
    $router->get('/api/meetings/{id}', 'MeetingController@show');
});
