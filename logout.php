<?php
session_start();

// Remove all session variables.
$_SESSION = [];

// Remove the session cookie from the browser, if one exists.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy the current session.
session_destroy();

// Return to the login page.
header('Location: login.php');
exit;
