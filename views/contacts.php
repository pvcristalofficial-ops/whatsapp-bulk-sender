<?php
// views/contacts.php
Admin::checkAuth();

require_once __DIR__ . '/../models/Contact.php';
$contactModel = new Contact();

$message = '';
$messageType = 'success'; // success, error

// -------------------------------------------------------------
// POST / GET Action Router
// -------------------------------------------------------------
$action = $_GET['action'] ?? '';

// CSV Export Action (Triggers file download, exits immediately)
if ($action === 'export') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=contacts_export_' . date('Ymd_His') . '.csv');
    $output = fopen('php://output', 'w');
    
    // Output headers
    fputcsv($output, ['Name', 'Phone', 'City', 'Course', 'Status', 'Imported Date']);
    
    // Fetch all contacts
    $db = Database::getConnection();
    $stmt = $db->query("SELECT name, phone, city, course, status, created_at FROM contacts ORDER BY id DESC");
    while ($row = $stmt->fetch()) {
        fputcsv($output, [
            $row['name'],
            $row['phone'],
            $row['city'],
            $row['course'],
            $row['status'],
            $row['created_at']
        ]);
    }
    fclose($output);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF verification
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = 'CSRF validation failed.';
        $messageType = 'danger';
    } else {
        // Create Contact
        if ($action === 'create') {
            $name = trim($_POST['name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $city = trim($_POST['city'] ?? '');
            $course = trim($_POST['course'] ?? '');
            $status = $_POST['status'] ?? 'Active';

            if (empty($name) || empty($phone)) {
                $message = 'Name and phone are required.';
                $messageType = 'danger';
            } elseif ($contactModel->phoneExists($phone)) {
                $message = 'A contact with this phone number already exists.';
                $messageType = 'danger';
            } else {
                $res = $contactModel->create([
                    'name' => $name,
                    'phone' => $phone,
                    'city' => $city,
                    'course' => $course,
                    'status' => $status
                ]);
                if ($res) {
                    $message = 'Contact added successfully.';
                } else {
                    $message = 'Failed to add contact. Please verify the phone number format.';
                    $messageType = 'danger';
                }
            }
        }
        
        // Update Contact
        elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $city = trim($_POST['city'] ?? '');
            $course = trim($_POST['course'] ?? '');
            $status = $_POST['status'] ?? 'Active';

            if (empty($name) || empty($phone)) {
                $message = 'Name and phone are required.';
                $messageType = 'danger';
            } elseif ($contactModel->phoneExists($phone, $id)) {
                $message = 'A contact with this phone number already exists.';
                $messageType = 'danger';
            } else {
                $res = $contactModel->update($id, [
                    'name' => $name,
                    'phone' => $phone,
                    'city' => $city,
                    'course' => $course,
                    'status' => $status
                ]);
                if ($res) {
                    $message = 'Contact updated successfully.';
                } else {
                    $message = 'Failed to update contact. Verify phone number.';
                    $messageType = 'danger';
                }
            }
        }

        // Single Delete
        elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($contactModel->delete($id)) {
                $message = 'Contact deleted successfully.';
            } else {
                $message = 'Failed to delete contact.';
                $messageType = 'danger';
            }
        }

        // Bulk Delete
        elseif ($action === 'bulk_delete') {
            $idsStr = $_POST['ids'] ?? '';
            if (!empty($idsStr)) {
                $ids = explode(',', $idsStr);
                if ($contactModel->bulkDelete($ids)) {
                    $message = count($ids) . ' contacts deleted successfully.';
                } else {
                    $message = 'Failed to delete selected contacts.';
                    $messageType = 'danger';
                }
            }
        }

        // File Import Action
        elseif ($action === 'import') {
            if (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
                $fileName = $_FILES['import_file']['name'];
                $tempPath = $_FILES['import_file']['tmp_name'];
                $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $detectDuplicates = isset($_POST['detect_duplicates']);

                if (in_array($ext, ['csv', 'xlsx'])) {
                    $importResult = $contactModel->importFromFile($tempPath, $ext, $detectDuplicates);
                    if ($importResult['success']) {
                        $message = sprintf(
                            "Import Finished! Imported: %d, Duplicates Skipped: %d, Invalid Records: %d",
                            $importResult['imported'],
                            $importResult['skipped'],
                            $importResult['invalid']
                        );
                    } else {
                        $message = 'Import failed: ' . $importResult['error'];
                        $messageType = 'danger';
                    }
                } else {
                    $message = 'Invalid file type. Only CSV and Excel (.xlsx) are allowed.';
                    $messageType = 'danger';
                }
            } else {
                $message = 'Please select a valid file to import.';
                $messageType = 'danger';
            }
        }
    }
}

// -------------------------------------------------------------
// Get Filters & Load Contacts
// -------------------------------------------------------------
$search = $_GET['search'] ?? '';
$cityFilter = $_GET['filter_city'] ?? '';
$courseFilter = $_GET['filter_course'] ?? '';
$statusFilter = $_GET['filter_status'] ?? '';

// Load contacts (we paginate natively with DataTable pagination or SQL LIMIT)
// To keep it highly performant, we pull all matching filtered contacts for DT to handle on client side, 
// or pull limit 1000 for standard display. DT handles client pagination perfectly up to 10k rows.
$contacts = $contactModel->getAll(2000, 0, $search, $cityFilter, $courseFilter, $statusFilter);
$uniqueCities = $contactModel->getUniqueCities();
$uniqueCourses = $contactModel->getUniqueCourses();
?>

<?php if (!empty($message)): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            Swal.fire({
                title: '<?php echo $messageType === 'success' ? 'Success!' : 'Notice'; ?>',
                text: '<?php echo addslashes($message); ?>',
                icon: '<?php echo $messageType === 'success' ? 'success' : 'error'; ?>',
                confirmButtonColor: '#0d6efd'
            });
        });
    </script>
<?php endif; ?>

<!-- Top Action Buttons -->
<div class="d-flex flex-wrap gap-2 mb-4 justify-content-between align-items-center">
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCreateContact">
            <i class="fas fa-plus me-1"></i> Add Contact
        </button>
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalImportContacts">
            <i class="fas fa-file-import me-1"></i> Import CSV/Excel
        </button>
        <a href="index.php?page=contacts&action=export" class="btn btn-outline-secondary">
            <i class="fas fa-file-export me-1"></i> Export CSV
        </a>
    </div>

    <!-- Hidden Bulk Delete form -->
    <form id="bulkDeleteForm" action="index.php?page=contacts&action=bulk_delete" method="POST" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="ids" id="bulkDeleteIds" value="">
        <button type="submit" id="btnBulkDelete" class="btn btn-danger d-none">
            <i class="fas fa-trash-alt me-1"></i> Delete Selected
        </button>
    </form>
</div>

<!-- Filters Panel -->
<div class="card p-3 mb-4">
    <form method="GET" action="index.php" class="row g-2 align-items-end">
        <input type="hidden" name="page" value="contacts">
        
        <div class="col-md-3">
            <label class="form-label small text-secondary fw-semibold">City</label>
            <select name="filter_city" class="form-select">
                <option value="">All Cities</option>
                <?php foreach ($uniqueCities as $city): ?>
                    <option value="<?php echo htmlspecialchars($city); ?>" <?php echo $cityFilter === $city ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($city); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-3">
            <label class="form-label small text-secondary fw-semibold">Course</label>
            <select name="filter_course" class="form-select">
                <option value="">All Courses</option>
                <?php foreach ($uniqueCourses as $course): ?>
                    <option value="<?php echo htmlspecialchars($course); ?>" <?php echo $courseFilter === $course ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($course); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-3">
            <label class="form-label small text-secondary fw-semibold">Status</label>
            <select name="filter_status" class="form-select">
                <option value="">All Statuses</option>
                <option value="Active" <?php echo $statusFilter === 'Active' ? 'selected' : ''; ?>>Active</option>
                <option value="Inactive" <?php echo $statusFilter === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
            </select>
        </div>

        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary flex-grow-1">
                <i class="fas fa-filter me-1"></i> Apply Filter
            </button>
            <a href="index.php?page=contacts" class="btn btn-outline-secondary">
                <i class="fas fa-undo"></i>
            </a>
        </div>
    </form>
</div>

<!-- Contacts Table List -->
<div class="card p-3">
    <div class="table-responsive">
        <table id="tblContacts" class="table table-hover align-middle small mb-0 w-100">
            <thead>
                <tr>
                    <th style="width: 40px;" class="no-sort">
                        <input type="checkbox" class="form-check-input" id="selectAllContacts">
                    </th>
                    <th>Name</th>
                    <th>Phone (WhatsApp ID)</th>
                    <th>City</th>
                    <th>Course</th>
                    <th>Status</th>
                    <th style="width: 100px;" class="no-sort text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($contacts as $contact): ?>
                    <tr>
                        <td>
                            <input type="checkbox" class="form-check-input contact-checkbox" value="<?php echo $contact['id']; ?>">
                        </td>
                        <td class="fw-bold"><?php echo htmlspecialchars($contact['name']); ?></td>
                        <td>
                            <a href="https://wa.me/<?php echo $contact['phone']; ?>" target="_blank" class="text-decoration-none text-success">
                                <i class="fab fa-whatsapp me-1"></i> +<?php echo $contact['phone']; ?>
                            </a>
                        </td>
                        <td><?php echo htmlspecialchars($contact['city'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($contact['course'] ?? '-'); ?></td>
                        <td>
                            <span class="badge <?php echo $contact['status'] === 'Active' ? 'bg-success' : 'bg-danger'; ?>">
                                <?php echo $contact['status']; ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <div class="d-flex gap-1 justify-content-end">
                                <button type="button" class="btn btn-outline-primary btn-sm btn-edit-contact" 
                                        data-id="<?php echo $contact['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($contact['name']); ?>"
                                        data-phone="<?php echo htmlspecialchars($contact['phone']); ?>"
                                        data-city="<?php echo htmlspecialchars($contact['city'] ?? ''); ?>"
                                        data-course="<?php echo htmlspecialchars($contact['course'] ?? ''); ?>"
                                        data-status="<?php echo $contact['status']; ?>"
                                        data-bs-toggle="modal" data-bs-target="#modalEditContact">
                                    <i class="fas fa-edit"></i>
                                </button>
                                
                                <form action="index.php?page=contacts&action=delete" method="POST" class="d-inline-block delete-contact-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="id" value="<?php echo $contact['id']; ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm btn-delete-submit">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- =============================================================
     MODAL: CREATE CONTACT
     ============================================================= -->
<div class="modal fade" id="modalCreateContact" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form action="index.php?page=contacts&action=create" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Create Single Contact</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Contact Name *</label>
                        <input type="text" class="form-control" name="name" placeholder="John Doe" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Phone Number (With Country Code) *</label>
                        <input type="text" class="form-control" name="phone" placeholder="e.g. 919876543210" required>
                        <div class="form-text text-muted" style="font-size:0.75rem;">Include country code without + or leading 0s.</div>
                    </div>
                    <div class="row g-2">
                        <div class="col-sm-6 mb-3">
                            <label class="form-label small fw-semibold">City</label>
                            <input type="text" class="form-control" name="city" placeholder="Mumbai">
                        </div>
                        <div class="col-sm-6 mb-3">
                            <label class="form-label small fw-semibold">Course</label>
                            <input type="text" class="form-control" name="course" placeholder="Spoken English">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Status</label>
                        <select class="form-select" name="status">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Contact</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- =============================================================
     MODAL: EDIT CONTACT
     ============================================================= -->
<div class="modal fade" id="modalEditContact" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form action="index.php?page=contacts&action=update" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="id" id="editContactId" value="">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Edit Contact</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Contact Name *</label>
                        <input type="text" class="form-control" name="name" id="editContactName" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Phone Number *</label>
                        <input type="text" class="form-control" name="phone" id="editContactPhone" required>
                        <div class="form-text text-muted" style="font-size:0.75rem;">Include country code without + or leading 0s.</div>
                    </div>
                    <div class="row g-2">
                        <div class="col-sm-6 mb-3">
                            <label class="form-label small fw-semibold">City</label>
                            <input type="text" class="form-control" name="city" id="editContactCity">
                        </div>
                        <div class="col-sm-6 mb-3">
                            <label class="form-label small fw-semibold">Course</label>
                            <input type="text" class="form-control" name="course" id="editContactCourse">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Status</label>
                        <select class="form-select" name="status" id="editContactStatus">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- =============================================================
     MODAL: IMPORT CSV/EXCEL
     ============================================================= -->
<div class="modal fade" id="modalImportContacts" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form action="index.php?page=contacts&action=import" method="POST" enctype="multipart/form-data" id="importForm">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Import Contacts</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Select CSV or Excel (.xlsx) File</label>
                        <input type="file" class="form-control" name="import_file" accept=".csv, .xlsx" required>
                    </div>
                    <div class="mb-3 form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="detect_duplicates" id="importDuplicates" checked>
                        <label class="form-check-label small fw-semibold" for="importDuplicates">Duplicate Detection (Skip existing phone numbers)</label>
                    </div>

                    <div class="bg-light p-3 rounded small border mb-0 text-secondary">
                        <h6 class="fw-bold text-dark small mb-2"><i class="fas fa-info-circle me-1"></i> File Guidelines:</h6>
                        <ul class="mb-0 ps-3">
                            <li>Column sequence must be: <strong>Name, Phone, City, Course, Status</strong></li>
                            <li><strong>Name</strong> and <strong>Phone</strong> are mandatory fields.</li>
                            <li>Phone numbers must contain the country code without plus signs or spaces (e.g. <code>919876543210</code>).</li>
                            <li>Status can be <code>Active</code> or <code>Inactive</code> (default is Active).</li>
                            <li>You can download sample templates:
                                <a href="sample.csv" download class="badge bg-secondary text-decoration-none ms-1">CSV Template</a>
                                <a href="sample.xlsx" download class="badge bg-secondary text-decoration-none ms-1">Excel Template</a>
                            </li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success" id="btnImportSubmit">
                        <i class="fas fa-file-import me-1"></i> Import Now
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Page script setup -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Move modals to body root to prevent backdrop z-index overlay issue
    ['modalCreateContact', 'modalEditContact', 'modalImportContacts'].forEach(id => {
        const modalEl = document.getElementById(id);
        if (modalEl) {
            document.body.appendChild(modalEl);
        }
    });

    // Initialize DataTable
    $('#tblContacts').DataTable({
        "columnDefs": [
            { "orderable": false, "targets": 'no-sort' }
        ],
        "order": [[1, "asc"]],
        "language": {
            "search": "Search Contacts:"
        }
    });

    // Handle Edit Contact Modal Binding
    const editModal = document.getElementById('modalEditContact');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const id = button.getAttribute('data-id');
            const name = button.getAttribute('data-name');
            const phone = button.getAttribute('data-phone');
            const city = button.getAttribute('data-city');
            const course = button.getAttribute('data-course');
            const status = button.getAttribute('data-status');

            document.getElementById('editContactId').value = id;
            document.getElementById('editContactName').value = name;
            document.getElementById('editContactPhone').value = phone;
            document.getElementById('editContactCity').value = city;
            document.getElementById('editContactCourse').value = course;
            document.getElementById('editContactStatus').value = status;
        });
    }

    // Confirm individual deletion
    const deleteForms = document.querySelectorAll('.delete-contact-form');
    deleteForms.forEach(form => {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            Swal.fire({
                title: 'Are you sure?',
                text: "Do you really want to delete this contact?",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });

    // Import Loader overlay
    const importForm = document.getElementById('importForm');
    if (importForm) {
        importForm.addEventListener('submit', function () {
            // Disable submit button and add loader spinner
            const btn = document.getElementById('btnImportSubmit');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Importing...';
        });
    }
});
</script>
