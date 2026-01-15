<?php
session_start();
header("Content-Type: application/json; charset=UTF-8");

// Falls Token noch nicht existiert, neu erzeugen
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

echo json_encode(["token" => $_SESSION["csrf_token"]]);
