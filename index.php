<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Origin, Content-Type, X-Auth-Token');

// Mostramos todos os erros
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('America/Sao_Paulo');

// Corrige $_POST
if (empty($_POST)) {
    $_POST = json_decode(file_get_contents('php://input'), true);
}

// Define algumas constantes
define('SCRIPTS_PATH', __DIR__ . "/chats");
define('STORE_PATH', __DIR__ . "/data");

// Inclui a classe principal
require __DIR__ . '/lib/botJSON.php';

// Inicia o bot
new botJSON();