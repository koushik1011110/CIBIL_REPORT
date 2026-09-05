<?php require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/onboarding_db_init.php';
function u(){return $_SESSION['user']??null;}

function login($x){$_SESSION['user']=$x;}
function out(){session_destroy();header('Location:'.url('/login.php'));exit;}
function auth(){if(!u()){header('Location:'.url('/login.php'));exit;}}
function role(...$r){auth();if(!in_array(u()['role'],$r,true)){http_response_code(403);exit('Forbidden');}}
function e($x){return htmlspecialchars((string)$x,ENT_QUOTES,'UTF-8');}
function money($x){return '₹'.number_format((float)$x,2);}
