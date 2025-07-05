
<?php
require_once 'config.php';
require_once 'includes/projectProgressCalculator.php';

try {
    // Get all projects
    $stmt = $pdo->query("SELECT id, project_name FROM projects");
    $projects = $stmt->fetchAll();
    
    echo "Updating progress for " . count($projects) . " projects...\n";
    
    foreach ($projects as $project) {
        echo "Updating project ID {$project['id']}: {$project['project_name']}... ";
        
        $result = update_project_progress_and_status($project['id']);
        
        if ($result['success']) {
            echo "Progress: {$result['progress']}%, Status: {$result['status']}\n";
        } else {
            echo "Failed: {$result['message']}\n";
        }
    }
    
    echo "Done!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
