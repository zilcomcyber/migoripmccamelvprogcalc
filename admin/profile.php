<?php
$page_title = "Profile Settings";
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// All roles can manage their own profile
require_admin(); 

$current_admin = get_current_admin();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'update_profile') {
            $name = sanitize_input($_POST['name'] ?? '');
            $email = sanitize_input($_POST['email'] ?? '');
            
            if (empty($name) || empty($email)) {
                $error = 'Name and email are required';
            } else {
                try {
                    // Check if email is already taken by another admin
                    $stmt = $pdo->prepare("SELECT id FROM admins WHERE email = ? AND id != ?");
                    $stmt->execute([$email, $current_admin['id']]);
                    
                    if ($stmt->fetch()) {
                        $error = 'Email is already taken by another admin';
                    } else {
                        $stmt = $pdo->prepare("UPDATE admins SET name = ?, email = ? WHERE id = ?");
                        $stmt->execute([$name, $email, $current_admin['id']]);
                        
                        // Update session
                        $_SESSION['admin_name'] = $name;
                        $_SESSION['admin_username'] = $email;
                        
                        $success = 'Profile updated successfully';
                    }
                } catch (Exception $e) {
                    error_log("Profile update error: " . $e->getMessage());
                    $error = 'An error occurred while updating profile';
                }
            }
        } elseif ($action === 'change_password') {
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            
            if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                $error = 'All password fields are required';
            } elseif ($new_password !== $confirm_password) {
                $error = 'New passwords do not match';
            } elseif (strlen($new_password) < 6) {
                $error = 'New password must be at least 6 characters long';
            } else {
                try {
                    // Verify current password
                    $stmt = $pdo->prepare("SELECT password_hash FROM admins WHERE id = ?");
                    $stmt->execute([$current_admin['id']]);
                    $admin = $stmt->fetch();
                    
                    if (!$admin || !password_verify($current_password, $admin['password_hash'])) {
                        $error = 'Current password is incorrect';
                    } else {
                        $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE admins SET password_hash = ? WHERE id = ?");
                        $stmt->execute([$new_password_hash, $current_admin['id']]);
                        
                        $success = 'Password changed successfully';
                    }
                } catch (Exception $e) {
                    error_log("Password change error: " . $e->getMessage());
                    $error = 'An error occurred while changing password';
                }
            }
        }
    }
}

// Get current admin details
$stmt = $pdo->prepare("SELECT name, email, role, created_at, last_login FROM admins WHERE id = ?");
$stmt->execute([$current_admin['id']]);
$admin_details = $stmt->fetch();

include 'includes/adminHeader.php';
?>

<div class="admin-content">
    <!-- Breadcrumbs -->
    <div class="mb-6">
        <nav class="flex" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2">
                <li><a href="index.php" class="text-gray-500 hover:text-gray-700">Dashboard</a></li>
                <li><span class="text-gray-400">/</span></li>
                <li><span class="text-gray-900">Profile Settings</span></li>
            </ol>
        </nav>
    </div>

    <!-- Page Header -->
    <div class="mb-8">
        <h1 class="text-2xl font-bold text-gray-900">Profile Settings</h1>
        <p class="text-gray-600 mt-2">Manage your account information and security settings</p>
    </div>

    <!-- Messages -->
    <?php if (isset($success)): ?>
        <div class="mb-6 rounded-md bg-green-50 p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-check-circle text-green-400"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm text-green-700"><?php echo htmlspecialchars($success); ?></p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="mb-6 rounded-md bg-red-50 p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-exclamation-circle text-red-400"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm text-red-700"><?php echo htmlspecialchars($error); ?></p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Profile Information -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6">
        <div class="p-6 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">Profile Information</h3>
            <p class="text-sm text-gray-600 mt-1">Update your account information</p>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
            <input type="hidden" name="action" value="update_profile">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Name *</label>
                    <input type="text" name="name" value="<?php echo htmlspecialchars($admin_details['name']); ?>" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($admin_details['email']); ?>" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
                    <input type="text" value="<?php echo ucfirst(str_replace('_', ' ', $admin_details['role'])); ?>" readonly 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm bg-gray-50 text-gray-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Member Since</label>
                    <input type="text" value="<?php echo format_date($admin_details['created_at']); ?>" readonly 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm bg-gray-50 text-gray-500">
                </div>
            </div>
            
            <div class="mt-6 flex justify-end">
                <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 transition-colors">
                    <i class="fas fa-save mr-2"></i>
                    Update Profile
                </button>
            </div>
        </form>
    </div>

    <!-- Change Password -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200">
        <div class="p-6 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">Change Password</h3>
            <p class="text-sm text-gray-600 mt-1">Update your account password</p>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
            <input type="hidden" name="action" value="change_password">
            
            <div class="space-y-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Current Password *</label>
                    <input type="password" name="current_password" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">New Password *</label>
                    <input type="password" name="new_password" required minlength="6"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                    <p class="text-xs text-gray-500 mt-1">Minimum 6 characters</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password *</label>
                    <input type="password" name="confirm_password" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>
            
            <div class="mt-6 flex justify-end">
                <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 transition-colors">
                    <i class="fas fa-key mr-2"></i>
                    Change Password
                </button>
            </div>
        </form>
    </div>

    <?php if ($current_admin['role'] === 'super_admin'): ?>
    <!-- Admin Management -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 mt-6">
        <div class="p-6 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">Administrator Management</h3>
            <p class="text-sm text-gray-600 mt-1">Manage system administrators and their roles</p>
        </div>
        <div class="p-6">
            <a href="manageAdmins.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 transition-colors">
                <i class="fas fa-users-cog mr-2"></i>
                Manage All Administrators
            </a>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include 'includes/adminFooter.php'; ?>