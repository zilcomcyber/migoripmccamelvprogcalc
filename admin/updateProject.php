<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';

require_admin();
$current_admin = get_current_admin();

// Get project ID and validate ownership
$project_id = (int)($_POST['project_id'] ?? 0);
if (!$project_id) {
    header('Location: projects.php?error=invalid_project');
    exit;
}

// Validate ownership before allowing update
require_project_ownership($project_id, 'edit_projects');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: projects.php');
    exit();
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    header('Location: projects.php?error=Invalid security token');
    exit();
}

try {
    $project_name = sanitize_input($_POST['project_name']);
    $description = sanitize_input($_POST['description'] ?? '');
    $department_id = intval($_POST['department_id']);
    $project_year = intval($_POST['project_year']);
    $county_id = intval($_POST['county_id']);
    $sub_county_id = intval($_POST['sub_county_id']);
    $ward_id = intval($_POST['ward_id']);
    $location_address = sanitize_input($_POST['location_address'] ?? '');
    $location_coordinates = sanitize_input($_POST['location_coordinates'] ?? '');
    $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
    $expected_completion_date = !empty($_POST['expected_completion_date']) ? $_POST['expected_completion_date'] : null;
    $contractor_name = sanitize_input($_POST['contractor_name'] ?? '');
    $contractor_contact = sanitize_input($_POST['contractor_contact'] ?? '');

    $project_id = intval($_POST['project_id']);

    // Validate required fields
    $required_fields = ['project_name', 'department_id', 'project_year', 'county_id', 'sub_county_id', 'ward_id'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            header("Location: editProject.php?id=$project_id&error=Please fill in all required fields");
            exit();
        }
    }

    // Check if project exists
    $stmt = $pdo->prepare("SELECT id FROM projects WHERE id = ?");
    $stmt->execute([$project_id]);
    if (!$stmt->fetch()) {
        header('Location: projects.php?error=Project not found');
        exit();
    }

    // Update project
        $stmt = $pdo->prepare("
            UPDATE projects 
            SET project_name = ?, description = ?, department_id = ?, project_year = ?, 
                county_id = ?, sub_county_id = ?, ward_id = ?, location_address = ?, 
                location_coordinates = ?, start_date = ?, expected_completion_date = ?, 
                contractor_name = ?, contractor_contact = ?, updated_at = NOW()
            WHERE id = ? AND id IN (SELECT project_id FROM project_user_assignments WHERE user_id = ?)
        ");

        $update_result = $stmt->execute([
            $project_name, $description, $department_id, $project_year,
            $county_id, $sub_county_id, $ward_id, $location_address,
            $location_coordinates, $start_date, $expected_completion_date,
            $contractor_name, $contractor_contact, $project_id, $current_admin['id']
        ]);

        // Handle project edit with proper progress recalculation and visibility update
        require_once '../includes/projectProgressCalculator.php';
        handle_project_edit_with_progress_update($project_id);

    if ($update_result) {
        log_activity("Project updated: " . $_POST['project_name'], $current_admin['id']);
        header("Location: projects.php?success=Project updated successfully");
    } else {
        header("Location: editProject.php?id=$project_id&error=Failed to update project");
    }

} catch (Exception $e) {
    error_log("Project update error: " . $e->getMessage());
    header("Location: editProject.php?id=" . ($_POST['project_id'] ?? 0) . "&error=An error occurred while updating the project");
}
?>