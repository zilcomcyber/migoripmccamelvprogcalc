
<?php
/**
 * Project Progress Calculator
 * Implements step progress (50%) + financial progress (50%) calculation
 * with automatic status updates and proper edit behavior
 */

/**
 * Calculate complete project progress using step progress (50%) + financial progress (50%)
 */
function calculate_complete_project_progress($project_id) {
    global $pdo;

    try {
        // Get step progress (50% of total)
        $step_progress = calculate_step_progress_component($project_id);
        
        // Get financial progress (50% of total)
        $financial_progress = calculate_financial_progress_component($project_id);
        
        // Total progress
        $total_progress = $step_progress + $financial_progress;
        
        return min(100, max(0, round($total_progress, 2)));
        
    } catch (Exception $e) {
        error_log("Calculate complete project progress error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Calculate step progress component (50% of total project progress)
 */
function calculate_step_progress_component($project_id) {
    global $pdo;

    try {
        // Get all steps for this project
        $stmt = $pdo->prepare("SELECT status FROM project_steps WHERE project_id = ?");
        $stmt->execute([$project_id]);
        $steps = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($steps)) {
            return 0;
        }

        $total_steps = count($steps);
        $step_current_score = 0;
        
        // Calculate current score based on step statuses
        foreach ($steps as $status) {
            switch ($status) {
                case 'pending':
                    $step_current_score += 0; // 0 points
                    break;
                case 'in_progress':
                    $step_current_score += 1; // 1 point
                    break;
                case 'completed':
                case 'complete':
                    $step_current_score += 2; // 2 points
                    break;
            }
        }

        // Virtual total: each step can have maximum 2 points
        $step_virtual_total = $total_steps * 2;
        
        // Step progress is 50% of total project progress
        $step_progress = ($step_virtual_total > 0) ? ($step_current_score / $step_virtual_total) * 50 : 0;

        return round($step_progress, 2);

    } catch (Exception $e) {
        error_log("Calculate step progress component error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Calculate financial progress component (50% of total project progress)
 */
function calculate_financial_progress_component($project_id) {
    global $pdo;

    try {
        // Get approved budget from total_budget table first, fallback to project table
        $stmt = $pdo->prepare("SELECT budget_amount FROM total_budget WHERE project_id = ? AND approval_status = 'approved' AND is_active = 1 ORDER BY version DESC LIMIT 1");
        $stmt->execute([$project_id]);
        $approved_budget = $stmt->fetchColumn();
        
        // If no approved budget in total_budget table, check project table
        if (!$approved_budget) {
            $stmt = $pdo->prepare("SELECT total_budget, approved_cost FROM projects WHERE id = ?");
            $stmt->execute([$project_id]);
            $project = $stmt->fetch();
            $approved_budget = $project['approved_cost'] ?? $project['total_budget'] ?? 0;
        }
        
        $approved_cost = $approved_budget;
        
        if ($approved_cost <= 0) {
            return 0;
        }

        // Get total expenditure from transactions (only active ones)
        $stmt = $pdo->prepare("
            SELECT SUM(amount) as total_expenditure
            FROM project_transactions 
            WHERE project_id = ? AND transaction_type = 'expenditure' 
            AND (transaction_status IS NULL OR transaction_status = 'active')
        ");
        $stmt->execute([$project_id]);
        $result = $stmt->fetch();
        
        $total_expenditure = $result['total_expenditure'] ?? 0;
        
        // Financial progress is 50% of total project progress, capped at 50%
        $financial_progress = min(($total_expenditure / $approved_cost) * 50, 50);
        
        return round($financial_progress, 2);

    } catch (Exception $e) {
        error_log("Calculate financial progress component error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Determine project status based on progress percentage
 */
function calculate_status_from_progress($progress_percentage) {
    if ($progress_percentage == 0) {
        return 'planning';
    } elseif ($progress_percentage > 0 && $progress_percentage < 100) {
        return 'ongoing';
    } elseif ($progress_percentage >= 100) {
        return 'completed';
    }
    
    return 'planning'; // Default fallback
}

/**
 * Update project progress and status automatically
 * This is the main function that should be called whenever:
 * - Step status changes
 * - Expenditure is updated
 * - Project is edited
 */
function update_project_progress_and_status($project_id, $force_private_visibility = false) {
    global $pdo;

    try {
        $pdo->beginTransaction();

        // Calculate new progress
        $new_progress = calculate_complete_project_progress($project_id);
        
        // Determine new status based on progress
        $new_status = calculate_status_from_progress($new_progress);
        
        // Get current project data for step counts
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_steps,
                COUNT(CASE WHEN status = 'completed' OR status = 'complete' THEN 1 END) as completed_steps
            FROM project_steps 
            WHERE project_id = ?
        ");
        $stmt->execute([$project_id]);
        $step_data = $stmt->fetch();

        // Check if approved_cost column exists
        $stmt = $pdo->prepare("SHOW COLUMNS FROM projects LIKE 'approved_cost'");
        $stmt->execute();
        $approved_cost_exists = $stmt->fetch();
        
        // Prepare update query
        $update_fields = [
            "progress_percentage = ?",
            "status = ?",
            "completed_steps = ?",
            "total_steps = ?",
            "updated_at = NOW()"
        ];
        $params = [
            $new_progress,
            $new_status,
            $step_data['completed_steps'] ?? 0,
            $step_data['total_steps'] ?? 0
        ];
        
        // Add approved_cost update if column exists and we have approved budget
        if ($approved_cost_exists) {
            $stmt = $pdo->prepare("SELECT budget_amount FROM total_budget WHERE project_id = ? AND approval_status = 'approved' AND is_active = 1 ORDER BY version DESC LIMIT 1");
            $stmt->execute([$project_id]);
            $approved_budget = $stmt->fetchColumn();
            if ($approved_budget) {
                $update_fields[] = "approved_cost = ?";
                $params[] = $approved_budget;
            }
        }

        // If forcing private visibility (like on edit)
        if ($force_private_visibility) {
            $update_fields[] = "visibility = 'private'";
        }

        $update_fields[] = "id = ?";
        $params[] = $project_id;

        $sql = "UPDATE projects SET " . implode(", ", $update_fields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $pdo->commit();

        return [
            'success' => true,
            'progress' => $new_progress,
            'status' => $new_status,
            'step_progress' => calculate_step_progress_component($project_id),
            'financial_progress' => calculate_financial_progress_component($project_id)
        ];

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Update project progress and status error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Update step status and trigger project recalculation
 */
function update_step_status_with_progress_recalc($step_id, $new_status, $start_date = null, $end_date = null, $notes = null) {
    global $pdo;

    try {
        $pdo->beginTransaction();

        // Get step and project info
        $stmt = $pdo->prepare("SELECT project_id FROM project_steps WHERE id = ?");
        $stmt->execute([$step_id]);
        $step = $stmt->fetch();

        if (!$step) {
            throw new Exception("Step not found");
        }

        // Validate status
        $valid_statuses = ['pending', 'in_progress', 'completed', 'complete'];
        if (!in_array($new_status, $valid_statuses)) {
            throw new Exception("Invalid step status");
        }

        // Update step
        $update_fields = ["status = ?"];
        $params = [$new_status];

        if ($start_date !== null) {
            $update_fields[] = "start_date = ?";
            $params[] = $start_date;
        }

        if ($end_date !== null) {
            $update_fields[] = "actual_end_date = ?";
            $params[] = $end_date;
        } elseif ($new_status === 'completed' || $new_status === 'complete') {
            $update_fields[] = "actual_end_date = NOW()";
        } elseif ($new_status !== 'completed' && $new_status !== 'complete') {
            $update_fields[] = "actual_end_date = NULL";
        }

        if ($new_status === 'in_progress' && $start_date === null) {
            $update_fields[] = "start_date = COALESCE(start_date, NOW())";
        }

        if ($notes !== null) {
            $update_fields[] = "notes = ?";
            $params[] = $notes;
        }

        $update_fields[] = "updated_at = NOW()";
        $params[] = $step_id;

        $sql = "UPDATE project_steps SET " . implode(", ", $update_fields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        // Update project progress and status
        $result = update_project_progress_and_status($step['project_id']);

        $pdo->commit();
        return $result;

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error updating step status with progress recalc: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Handle project edit with proper progress recalculation and visibility update
 */
function handle_project_edit_with_progress_update($project_id) {
    // Force visibility to private and recalculate progress/status
    return update_project_progress_and_status($project_id, true);
}
?>
