<?php

/** @var \Laravel\Lumen\Routing\Router $router */

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
*/

$router->get('/', function () use ($router) {
    return $router->app->version();
});

$router->get('welcome', function () use ($router) {
    return "Hisssssss";
});

$router->get('orders/failed/last-15-mins', 'OrderController@failedLast15Minutes');
$router->get('/decrypt-csv', 'DecryptController@decryptCsv');
$router->post('/decrypt-csv', 'DecryptController@decryptCsv');

// Daily previous-day business report (Flight & Hotel) - hit by cron each morning (generates + sends WhatsApp).
$router->get('reports/daily-business', 'ReportController@dailyBusinessReport');

// On-demand PDF download - hit any time to get the PDF in the browser (no WhatsApp send).
// Optional ?date=YYYY-MM-DD, defaults to previous day.
$router->get('reports/business/download', 'ReportController@downloadBusinessReport');

// Daily bank report (Flight & Hotel success/failure) - cron hits this each
// morning; runs 4 queries, builds a CSV, emails it to the bank recipients.
$router->get('reports/daily-bank-csv', 'DailyBankReportController@sendDailyBankReport');

// On-demand CSV download in the browser (no email). Optional ?date=YYYY-MM-DD.
$router->get('reports/daily-bank-csv/download', 'DailyBankReportController@downloadDailyBankReport');

// MySQL query results -> real .xlsx workbook. Optional ?date=YYYY-MM-DD.
// download = streams the file to the browser; email = attaches it to a mail.
$router->get('reports/excel/download', 'ExcelReportController@download');
$router->get('reports/excel/email', 'ExcelReportController@email');

