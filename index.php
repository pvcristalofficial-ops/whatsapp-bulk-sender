<?php
// index.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/models/Admin.php';

// Handle global controller actions
$action = $_GET['action'] ?? '';
if ($action === 'logout') {
    Admin::logout();
}

// Router
$page = $_GET['page'] ?? 'dashboard';
$validPages = ['dashboard', 'contacts', 'templates', 'campaigns', 'reports', 'settings', 'logs', 'login'];

if (!in_array($page, $validPages)) {
    $page = 'dashboard';
}

// Login page handles its own authentication checks and lacks layout headers/sidebar
if ($page === 'login') {
    $pageTitle = 'Login - WhatsApp Bulk Sender Pro';
    require_once __DIR__ . '/views/login.php';
    exit();
}

// Enforce authentication for all other admin routes
Admin::checkAuth();

// Establish page titles
$pageTitle = 'WhatsApp Bulk Sender Pro';
switch ($page) {
    case 'contacts': $pageTitle = 'Contacts - WhatsApp Bulk Sender Pro'; break;
    case 'templates': $pageTitle = 'Templates - WhatsApp Bulk Sender Pro'; break;
    case 'campaigns': $pageTitle = 'Campaigns - WhatsApp Bulk Sender Pro'; break;
    case 'reports': $pageTitle = 'Reports - WhatsApp Bulk Sender Pro'; break;
    case 'settings': $pageTitle = 'Settings - WhatsApp Bulk Sender Pro'; break;
    case 'logs': $pageTitle = 'Logs - WhatsApp Bulk Sender Pro'; break;
}

// Load views with layouts
require_once __DIR__ . '/views/layout/header.php';
require_once __DIR__ . '/views/layout/sidebar.php';

$viewPath = __DIR__ . "/views/{$page}.php";
if (file_exists($viewPath)) {
    require_once $viewPath;
} else {
    echo '<div class="alert alert-danger">Error: View file not found.</div>';
}

require_once __DIR__ . '/views/layout/footer.php';
