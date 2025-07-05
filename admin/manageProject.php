<?php
// Adding project ownership validation to project management.
$page_title = "Manage Project";
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once 'includes/pageSecurity.php';

$current_admin = get_current_admin();
$project_id = (int)($_GET['id'] ?? 0);

// Validate project ownership
if (!$project_id) {
    header('Location: projects.php?error=invalid_project');
    exit;
}

require_project_ownership($project_id, 'manage_projects');

require_admin();
$current_admin = get_current_admin();

// Check project management permission
require_permission('manage_projects');

// Get project ID from URL
$project_id = intval($_GET['id'] ?? 0);
if ($project_id <= 0) {
    header('Location: projects.php');
    exit();
}

// Fetch project details
$project = get_project_by_id($project_id);
if (!$project) {
    header('Location: projects.php');
    exit();
}

// Check if the current admin has permission to manage this specific project
if (!can_manage_project($project_id)) {
    header('Location: projects.php?error=' . urlencode('You do not have permission to manage this project.'));
    exit();
}

// Handle all form submissions (POST requests)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        $result = null;

        try {
            switch ($action) {
                case 'update_step':
                    $step_id = intval($_POST['step_id'] ?? 0);
                    $status = $_POST['status'] ?? '';
                    $step_name = sanitize_input($_POST['step_name'] ?? '');
                    $description = sanitize_input($_POST['description'] ?? '');
                    $expected_end_date = !empty($_POST['expected_end_date']) ? $_POST['expected_end_date'] : null;

                    if (!in_array($status, ['pending', 'in_progress', 'completed', 'skipped'])) {
                        throw new Exception('Invalid step status.');
                    }
                    if (empty($step_name)) {
                        throw new Exception('Step name cannot be empty.');
                    }

                    $pdo->beginTransaction();

                    // Fetch the current step to check dates
                    $stmt_check = $pdo->prepare("SELECT start_date, actual_end_date FROM project_steps WHERE id = ?");
                    $stmt_check->execute([$step_id]);
                    $current_step = $stmt_check->fetch();

                    $sql = "UPDATE project_steps SET status = ?, step_name = ?, description = ?, expected_end_date = ?";
                    $params = [$status, $step_name, $description, $expected_end_date];

                    if ($status === 'completed' && empty($current_step['actual_end_date'])) {
                        $sql .= ", actual_end_date = CURDATE()";
                    } elseif ($status === 'in_progress' && empty($current_step['start_date'])) {
                        $sql .= ", start_date = COALESCE(start_date, CURDATE())";
                    }

                    $sql .= " WHERE id = ? AND project_id = ?";
                    $params[] = $step_id;
                    $params[] = $project_id;

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);

                    update_project_progress($project_id);
                    $pdo->commit();
                    log_activity("Updated project step for project ID: $project_id", $current_admin['id']);
                    $success = 'Step updated successfully.';
                    break;

                case 'update_status_only':
                    $step_id = intval($_POST['step_id'] ?? 0);
                    $status = $_POST['status'] ?? '';

                    if (!in_array($status, ['pending', 'in_progress', 'completed', 'skipped'])) {
                        throw new Exception('Invalid step status.');
                    }

                    $pdo->beginTransaction();

                    // Fetch the current step to check dates
                    $stmt_check = $pdo->prepare("SELECT start_date, actual_end_date FROM project_steps WHERE id = ?");
                    $stmt_check->execute([$step_id]);
                    $current_step = $stmt_check->fetch();

                    $sql = "UPDATE project_steps SET status = ?";
                    $params = [$status];

                    if ($status === 'completed' && empty($current_step['actual_end_date'])) {
                        $sql .= ", actual_end_date = CURDATE()";
                    } elseif ($status === 'in_progress' && empty($current_step['start_date'])) {
                        $sql .= ", start_date = COALESCE(start_date, CURDATE())";
                    }

                    $sql .= " WHERE id = ? AND project_id = ?";
                    $params[] = $step_id;
                    $params[] = $project_id;

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);

                    require_once '../includes/projectProgressCalculator.php';
                    update_project_progress_and_status($project_id);
                    $pdo->commit();
                    log_activity("Updated step status for project ID: $project_id", $current_admin['id']);
                    $success = 'Step status updated successfully.';
                    break;

                case 'add_step':
                    $step_name = sanitize_input($_POST['step_name'] ?? '');
                    $description = sanitize_input($_POST['description'] ?? '');
                    $expected_end_date = !empty($_POST['expected_end_date']) ? $_POST['expected_end_date'] : null;

                    if (empty($step_name)) {
                        throw new Exception('Step name is required.');
                    }

                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("SELECT COALESCE(MAX(step_number), 0) + 1 FROM project_steps WHERE project_id = ?");
                    $stmt->execute([$project_id]);
                    $step_number = $stmt->fetchColumn();

                    $stmt = $pdo->prepare("INSERT INTO project_steps (project_id, step_number, step_name, description, expected_end_date) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$project_id, $step_number, $step_name, $description, $expected_end_date]);

                    update_project_progress($project_id);
                    $pdo->commit();
                    log_activity("Added project step for project ID: $project_id", $current_admin['id']);
                    $success = 'Step added successfully.';
                    break;

                case 'delete_step':
                    $step_id = intval($_POST['step_id'] ?? 0);

                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("DELETE FROM project_steps WHERE id = ? AND project_id = ?");
                    $stmt->execute([$step_id, $project_id]);

                    // Renumber remaining steps to maintain order
                    $stmt = $pdo->prepare("SELECT id FROM project_steps WHERE project_id = ? ORDER BY step_number");
                    $stmt->execute([$project_id]);
                    $remaining_steps = $stmt->fetchAll(PDO::FETCH_COLUMN);

                    foreach ($remaining_steps as $index => $remaining_step_id) {
                        $stmt_update = $pdo->prepare("UPDATE project_steps SET step_number = ? WHERE id = ?");
                        $stmt_update->execute([$index + 1, $remaining_step_id]);
                    }

                    update_project_progress($project_id);
                    $pdo->commit();
                    log_activity("Deleted project step for project ID: $project_id", $current_admin['id']);
                    $success = 'Step deleted successfully.';
                    break;

                case 'update_visibility':
                    $visibility = $_POST['visibility'] ?? '';
                    if (!in_array($visibility, ['private', 'published'])) {
                        throw new Exception('Invalid visibility option.');
                    }
                    $stmt = $pdo->prepare("UPDATE projects SET visibility = ? WHERE id = ?");
                    $stmt->execute([$visibility, $project_id]);
                    log_activity("Updated project visibility to '$visibility' for project ID: $project_id", $current_admin['id']);
                    $success = 'Project visibility updated successfully.';
                    break;

                case 'delete_project':
                    $project_name_to_delete = $project['project_name']; // Get name before deleting

                    $pdo->beginTransaction();
                    // Delete associated records first
                    $pdo->prepare("DELETE FROM project_steps WHERE project_id = ?")->execute([$project_id]);
                    $pdo->prepare("DELETE FROM feedback WHERE project_id = ?")->execute([$project_id]);

                    // Delete the project itself
                    $stmt = $pdo->prepare("DELETE FROM projects WHERE id = ?");
                    $stmt->execute([$project_id]);
                    $pdo->commit();

                    log_activity("Project deleted: " . $project_name_to_delete, $current_admin['id']);
                    header('Location: projects.php?success=' . urlencode('Project deleted successfully'));
                    exit();

                default:
                    $error = 'Invalid action specified.';
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Manage Project Error: " . $e->getMessage());
            $error = 'An error occurred. Please check the logs or try again.';
        }

        // After a POST action, refresh data to show the latest state
        $project = get_project_by_id($project_id);
    }
}

// Get project steps after any potential updates
$stmt = $pdo->prepare("SELECT * FROM project_steps WHERE project_id = ? ORDER BY step_number");
$stmt->execute([$project_id]);
$steps = $stmt->fetchAll();

include 'includes/adminHeader.php';
?>

<!-- Main Content -->
<div class="admin-content">
    <div class="space-y-6 lg:space-y-8">

        <!-- Success/Error Messages -->
        <?php if (isset($success)): ?>
        <div class="rounded-md bg-green-50 p-4 border border-green-200">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-green-800"><?php echo htmlspecialchars($success); ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
        <div class="rounded-md bg-red-50 p-4 border border-red-200">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-red-800"><?php echo htmlspecialchars($error); ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Manage Project</h1>
                <p class="mt-1 text-sm text-gray-600">Editing project: <span class="font-semibold"><?php echo htmlspecialchars($project['project_name']); ?></span></p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="editProject.php?id=<?php echo $project['id']; ?>" class="inline-flex items-center justify-center w-full sm:w-auto px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                    <i class="fas fa-edit mr-2"></i> Edit Details
                </a>
                <button type="button" data-modal-target="deleteProjectModal" class="inline-flex items-center justify-center w-full sm:w-auto px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-colors">
                    <i class="fas fa-trash mr-2"></i> Delete Project
                </button>
            </div>
        </div>

        <!-- Project Overview Card -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
            <div class="p-6">
                <div class="flex flex-col md:flex-row md:justify-between md:items-start gap-4 mb-6">
                    <div>
                        <h3 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars($project['project_name']); ?></h3>
                        <span class="mt-1 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo get_status_badge_class($project['status']); ?>">
                            <?php echo ucfirst($project['status']); ?>
                        </span>
                    </div>
                    <div class="w-full md:w-1/3">
                        <dt class="text-sm font-medium text-gray-500">Progress</dt>
                        <dd class="text-sm text-gray-900 mt-1">
                            <div class="flex items-center">
                                <div class="flex-1">
                                    <div class="w-full bg-gray-200 rounded-full h-2.5">
                                        <div class="h-2.5 rounded-full <?php echo get_progress_color_class($project['progress_percentage']); ?>" style="width: <?php echo $project['progress_percentage']; ?>%"></div>
                                    </div>
                                </div>
                                <span class="ml-3 text-sm font-medium"><?php echo round($project['progress_percentage']); ?>%</span>
                            </div>
                        </dd>
                    </div>
                </div>
                <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-8">
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Department</dt>
                        <dd class="mt-1 text-sm text-gray-900"><?php echo htmlspecialchars($project['department_name']); ?></dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Location</dt>
                        <dd class="mt-1 text-sm text-gray-900"><?php echo htmlspecialchars($project['ward_name'] . ', ' . $project['sub_county_name']); ?></dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Visibility</dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $project['visibility'] === 'published' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'; ?>">
                                <?php echo $project['visibility'] === 'published' ? 'Public' : 'Private'; ?>
                            </span>
                        </dd>
                    </div>
                </dl>
            </div>
        </div>

        <!-- Project Visibility Card -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
            <div class="p-6 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">Project Visibility</h3>
                <p class="text-sm text-gray-600 mt-1">Control whether this project is visible on the public-facing website.</p>
            </div>
            <form method="POST">
                <div class="p-6">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="action" value="update_visibility">
                        <select name="visibility" class="w-full sm:w-auto px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-blue-500 focus:border-blue-500">
                            <option value="private" <?php echo $project['visibility'] === 'private' ? 'selected' : ''; ?>>Private (Visible to Admins Only)</option>
                            <option value="published" <?php echo $project['visibility'] === 'published' ? 'selected' : ''; ?>>Published (Visible to Public)</option>
                        </select>
                        <button type="submit" class="w-full sm:w-auto inline-flex justify-center items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 transition-colors">
                            Update Visibility
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Project Steps Management Card -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
            <div class="p-6 border-b border-gray-200">
                <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">
                    <h3 class="text-lg font-semibold text-gray-900">Project Steps</h3>
                    <button type="button" data-modal-target="stepModal" data-modal-action="add" class="w-full sm:w-auto inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 transition-colors">
                        <i class="fas fa-plus mr-2"></i> Add Step
                    </button>
                </div>
            </div>

            <?php if (empty($steps)): ?>
                <div class="text-center p-12">
                    <i class="fas fa-list-ol text-4xl text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900">No Steps Defined</h3>
                    <p class="text-gray-600 mt-1 mb-4">This project doesn't have any steps yet. Add the first one to get started.</p>
                    <button type="button" data-modal-target="stepModal" data-modal-action="add" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 transition-colors">
                        <i class="fas fa-plus mr-2"></i> Add First Step
                    </button>
                </div>
            <?php else: ?>
                <div class="divide-y divide-gray-200">
                    <?php foreach ($steps as $step): ?>
                        <div class="p-6">
                            <div class="flex flex-col md:flex-row md:justify-between gap-6">
                                <!-- Step Details -->
                                <div class="flex-grow">
                                    <div class="flex items-center mb-3">
                                        <span class="flex-shrink-0 inline-flex items-center justify-center w-8 h-8 rounded-full text-sm font-bold text-white <?php echo $step['status'] === 'completed' ? 'bg-green-500' : ($step['status'] === 'in_progress' ? 'bg-blue-500' : ($step['status'] === 'skipped' ? 'bg-gray-500' : 'bg-yellow-500')); ?>">
                                            <?php echo $step['step_number']; ?>
                                        </span>
                                        <div class="ml-4">
                                            <h4 class="text-lg font-medium text-gray-900"><?php echo htmlspecialchars($step['step_name']); ?></h4>
                                            <span class="text-xs font-medium inline-flex items-center px-2 py-0.5 rounded <?php echo $step['status'] === 'completed' ? 'bg-green-100 text-green-800' : ($step['status'] === 'in_progress' ? 'bg-blue-100 text-blue-800' : ($step['status'] === 'skipped' ? 'bg-gray-100 text-gray-800' : 'bg-yellow-100 text-yellow-800')); ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $step['status'])); ?>
                                            </span>
                                        </div>
                                    </div>

                                    <?php if (!empty($step['description'])): ?>
                                    <p class="text-sm text-gray-600 mb-4 ml-12"><?php echo nl2br(htmlspecialchars($step['description'])); ?></p>
                                    <?php endif; ?>

                                    <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-4 gap-y-2 text-sm ml-12">
                                        <?php if ($step['start_date']): ?><div><dt class="font-medium text-gray-500">Started:</dt><dd class="text-gray-900"><?php echo format_date($step['start_date']); ?></dd></div><?php endif; ?>
                                        <?php if ($step['expected_end_date']): ?><div><dt class="font-medium text-gray-500">Expected:</dt><dd class="text-gray-900"><?php echo format_date($step['expected_end_date']); ?></dd></div><?php endif; ?>
                                        <?php if ($step['actual_end_date']): ?><div><dt class="font-medium text-gray-500">Completed:</dt><dd class="text-gray-900"><?php echo format_date($step['actual_end_date']); ?></dd></div><?php endif; ?>
                                    </dl>
                                </div>

                                <!-- Step Actions -->
                                <div class="flex-shrink-0 flex flex-col items-stretch justify-center gap-3 md:ml-6 md:border-l md:pl-6 border-gray-200">
                                    <form method="POST" class="w-full">
                                         <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                         <input type="hidden" name="action" value="update_status_only">
                                         <input type="hidden" name="step_id" value="<?php echo $step['id']; ?>">
                                         <label class="sr-only" for="status-<?php echo $step['id']; ?>">Update Status</label>
                                         <select name="status" id="status-<?php echo $step['id']; ?>" onchange="this.form.submit()" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-blue-500 focus:border-blue-500">
                                            <option value="pending" <?php echo $step['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="in_progress" <?php echo $step['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                            <option value="completed" <?php echo $step['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                            <option value="skipped" <?php echo $step['status'] === 'skipped' ? 'selected' : ''; ?>>Skipped</option>
                                        </select>
                                    </form>

                                    <button type="button" data-modal-target="stepModal" data-modal-action="edit" data-step='<?php echo htmlspecialchars(json_encode($step), ENT_QUOTES, "UTF-8"); ?>' class="w-full inline-flex items-center justify-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors" title="Edit Step Details">
                                        <i class="fas fa-edit fa-fw mr-2"></i>Edit
                                    </button>
                                    <button type="button" onclick="openDeleteStepModal(<?php echo $step['id']; ?>)" class="w-full inline-flex items-center justify-center px-3 py-2 border border-transparent text-sm font-medium rounded-md text-red-700 bg-red-100 hover:bg-red-200" title="Delete Step">
                                        <i class="fas fa-trash fa-fw mr-2"></i>Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add/Edit Step Modal -->
<div id="stepModal" class="fixed inset-0 bg-gray-800 bg-opacity-75 hidden z-50 transition-opacity" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen p-4 text-center sm:block sm:p-0">
        <!-- Background element, appears when modal is open -->
        <div class="fixed inset-0" aria-hidden="true"></div>
        <!-- Modal panel -->
        <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
            <form id="stepForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" id="stepAction" value="add_step">
                <input type="hidden" name="step_id" id="stepId" value="">

                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">Add New Step</h3>
                    <div class="mt-4 space-y-4">
                        <div>
                            <label for="step_name" class="block text-sm font-medium text-gray-700">Step Name *</label>
                            <input type="text" name="step_name" id="step_name" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                            <textarea name="description" id="description" rows="3" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"></textarea>
                        </div>
                        <div>
                            <label for="expected_end_date" class="block text-sm font-medium text-gray-700">Expected End Date</label>
                            <input type="date" name="expected_end_date" id="expected_end_date" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                         <div>
                            <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                            <select name="status" id="status" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                <option value="pending">Pending</option>
                                <option value="in_progress">In Progress</option>
                                <option value="completed">Completed</option>
                                <option value="skipped">Skipped</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                    <button type="submit" id="stepSubmitButton" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-blue-600 text-base font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:ml-3 sm:w-auto sm:text-sm">
                        Add Step
                    </button>
                    <button type="button" data-modal-close="stepModal" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none sm:mt-0 sm:w-auto sm:text-sm">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Step Confirmation Modal -->
<div id="deleteStepModal" class="fixed inset-0 bg-gray-800 bg-opacity-75 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
            <div class="p-6 text-center">
                <i class="fas fa-exclamation-triangle text-4xl text-red-500 mx-auto mb-4"></i>
                <h3 class="text-lg font-semibold text-gray-900">Delete Step</h3>
                <p class="text-sm text-gray-600 mt-2">Are you sure you want to delete this step? This action cannot be undone.</p>
            </div>
            <form method="POST" class="flex justify-end space-x-3 p-4 bg-gray-50 border-t border-gray-200">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="delete_step">
                <input type="hidden" name="step_id" id="delete_step_id" value="">
                <button type="button" data-modal-close="deleteStepModal" class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700">Delete Step</button>
            </form>
        </div>
    </div>
</div>

<!-- Delete Project Confirmation Modal -->
<div id="deleteProjectModal" class="fixed inset-0 bg-gray-800 bg-opacity-75 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
            <div class="p-6 text-center">
                <i class="fas fa-exclamation-triangle text-4xl text-red-500 mx-auto mb-4"></i>
                <h3 class="text-lg font-semibold text-gray-900">Delete Project</h3>
                <p class="text-sm text-gray-600 mt-2">Are you sure you want to permanently delete this project? All associated steps and feedback will also be removed. This action cannot be undone.</p>
            </div>
            <form method="POST" class="flex justify-end space-x-3 p-4 bg-gray-50 border-t border-gray-200">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="delete_project">
                <button type="button" data-modal-close="deleteProjectModal" class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700">Yes, Delete Project</button>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- Modal Control ---
    const modalTriggers = document.querySelectorAll('[data-modal-target]');
    const modalClosers = document.querySelectorAll('[data-modal-close]');

    modalTriggers.forEach(trigger => {
        trigger.addEventListener('click', (e) => {
            const modalId = trigger.getAttribute('data-modal-target');
            const modal = document.getElementById(modalId);
            if (modal) {
                // Handle step modal specifically
                if(modalId === 'stepModal') {
                    const action = trigger.getAttribute('data-modal-action');
                    if (action === 'edit') {
                        const stepData = JSON.parse(trigger.getAttribute('data-step'));
                        openEditStepModal(stepData);
                    } else {
                        openAddStepModal();
                    }
                }
                modal.classList.remove('hidden');
                document.body.style.overflow = 'hidden';
            }
        });
    });

    modalClosers.forEach(closer => {
        closer.addEventListener('click', () => {
            const modal = closer.closest('.fixed.inset-0');
            if (modal) {
                modal.classList.add('hidden');
                document.body.style.overflow = 'auto';
            }
        });
    });

    // Close modal on escape key press
    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            document.querySelectorAll('.fixed.inset-0:not(.hidden)').forEach(modal => {
                modal.classList.add('hidden');
            });
            document.body.style.overflow = 'auto';
        }
    });

    // --- Step Modal Logic ---
    const stepModal = document.getElementById('stepModal');
    const stepForm = document.getElementById('stepForm');
    const modalTitle = document.getElementById('modal-title');
    const stepAction = document.getElementById('stepAction');
    const stepSubmitButton = document.getElementById('stepSubmitButton');

    function openAddStepModal() {
        stepForm.reset();
        modalTitle.textContent = 'Add New Step';
        stepAction.value = 'add_step';
        document.getElementById('stepId').value = '';
        stepSubmitButton.textContent = 'Add Step';
    }

    function openEditStepModal(stepData) {
        stepForm.reset();
        modalTitle.textContent = 'Edit Step';
        stepAction.value = 'update_step';
        stepSubmitButton.textContent = 'Save Changes';

        document.getElementById('stepId').value = stepData.id;
        document.getElementById('step_name').value = stepData.step_name;
        document.getElementById('description').value = stepData.description || '';
        document.getElementById('expected_end_date').value = stepData.expected_end_date || '';
        document.getElementById('status').value = stepData.status || 'pending';
    }

    // Function to open the delete confirmation modal for a step
    window.openDeleteStepModal = function(stepIdToDelete) {
        document.getElementById('delete_step_id').value = stepIdToDelete;
        document.getElementById('deleteStepModal').classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }
});
</script>

<?php
include 'includes/adminFooter.php';
?>