<?php
// views/layout/sidebar.php
$currentPage = $_GET['page'] ?? 'dashboard';
?>
<!-- Sidebar -->
<div class="border-end" id="sidebar-wrapper">
    <div class="sidebar-heading">
        <i class="fab fa-whatsapp text-success fs-3"></i>
        <span>Bulk Sender Pro</span>
    </div>
    <div class="list-group list-group-flush mt-3">
        <a href="index.php?page=dashboard" class="list-group-item list-group-item-action <?php echo $currentPage === 'dashboard' ? 'active' : ''; ?>">
            <i class="fas fa-chart-pie"></i> Dashboard
        </a>
        <a href="index.php?page=contacts" class="list-group-item list-group-item-action <?php echo $currentPage === 'contacts' ? 'active' : ''; ?>">
            <i class="fas fa-users"></i> Contacts
        </a>
        <a href="index.php?page=templates" class="list-group-item list-group-item-action <?php echo $currentPage === 'templates' ? 'active' : ''; ?>">
            <i class="fas fa-file-invoice"></i> Templates
        </a>
        <a href="index.php?page=campaigns" class="list-group-item list-group-item-action <?php echo $currentPage === 'campaigns' ? 'active' : ''; ?>">
            <i class="fas fa-bullhorn"></i> Campaigns
        </a>
        <a href="index.php?page=reports" class="list-group-item list-group-item-action <?php echo $currentPage === 'reports' ? 'active' : ''; ?>">
            <i class="fas fa-file-alt"></i> Reports
        </a>
        <a href="index.php?page=logs" class="list-group-item list-group-item-action <?php echo $currentPage === 'logs' ? 'active' : ''; ?>">
            <i class="fas fa-terminal"></i> API Logs
        </a>
        <a href="index.php?page=settings" class="list-group-item list-group-item-action <?php echo $currentPage === 'settings' ? 'active' : ''; ?>">
            <i class="fas fa-sliders-h"></i> Meta Settings
        </a>
        <hr class="text-white-50 my-4 mx-3">
        <a href="index.php?action=logout" class="list-group-item list-group-item-action text-danger mt-auto">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>
</div>
<!-- /#sidebar-wrapper -->

<!-- Page Content -->
<div id="page-content-wrapper" class="d-flex flex-column">
    <!-- Top Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light top-navbar px-4 py-3">
        <div class="container-fluid p-0">
            <h4 class="m-0 fw-bold text-dark">
                <?php 
                switch ($currentPage) {
                    case 'contacts': echo 'Contact Directory'; break;
                    case 'templates': echo 'Template Repository'; break;
                    case 'campaigns': echo 'Campaign Control Center'; break;
                    case 'reports': echo 'Analytics Reports'; break;
                    case 'logs': echo 'System API Logs'; break;
                    case 'settings': echo 'Meta API Credentials'; break;
                    default: echo 'Dashboard Overview';
                }
                ?>
            </h4>
            <div class="d-flex align-items-center gap-3 ms-auto">
                <span class="text-secondary small d-none d-sm-inline">
                    <i class="fas fa-user-circle me-1"></i> 
                    Logged in as: <strong><?php echo htmlspecialchars($_SESSION['admin_name'] ?? 'Admin'); ?></strong>
                </span>
            </div>
        </div>
    </nav>
    
    <!-- Main Content Area -->
    <div class="container-fluid p-4 fade-in-element">
