<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Require valid patient session.
 */
function requirePatientLogin()
{
    if (empty($_SESSION['patient_id'])) {
        header("Location: /hospital-queue-system/public/patient/login.php");
        exit;
    }
}

function getPatientId()
{
    return $_SESSION['patient_id'] ?? null;
}

function getPatientName()
{
    return $_SESSION['patient_name'] ?? 'Patient';
}

/**
 * Require valid Admin session.
 */
function requireAdminLogin()
{
    if (empty($_SESSION['admin_id'])) {
        header("Location: /hospital-queue-system/public/admin/login.php");
        exit;
    }
}

/**
 * Require valid Staff session.
 */
function requireStaffLogin()
{
    if (empty($_SESSION['staff_id'])) {
        header("Location: /hospital-queue-system/public/staff/login.php");
        exit;
    }
}

/**
 * Require valid Doctor session.
 */
function requireDoctorLogin()
{
    if (empty($_SESSION['doctor_id'])) {
        header("Location: /hospital-queue-system/public/doctor/login.php");
        exit;
    }
}

/**
 * Cleanly destroy current session and delete cookie.
 */
function destroyUserSession($redirectUrl = '/hospital-queue-system/public/index.php')
{
    $_SESSION = [];
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
    session_destroy();
    header("Location: " . $redirectUrl);
    exit;
}