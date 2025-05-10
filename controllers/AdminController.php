<?php

namespace app\controllers;

use Yii;
use app\models\Packages;
use app\models\GroupMembers;
use app\models\ActivityLogs;
use app\models\AdminActivityLogs;
use app\models\Subscription;
use app\components\Helper;
use app\models\AdminUsers;
use app\models\PayoutSchedule;
use app\models\PayoutScheduleSetting;
use app\models\ContributionSchedule;
use app\models\Notifications;
use app\models\Payments;
use app\models\Groups;
use app\models\Pledges;
use yii\rest\Controller;
use yii\web\HttpException;
use yii\web\UploadedFile;
use app\models\User;
use app\models\NotificationRecipient;
use yii\filters\AccessControl;
use yii\filters\RateLimiter;
use yii\filters\VerbFilter;

class AdminController extends Controller
{
    public $controller = 'Admin Controller';

    public $user_id = null;
    public $mobile = null;
    public $email = null;
    public $role = null;

    public function behaviors()
    {
        $behaviors = parent::behaviors();

        // remove authentication filter necessary because we need to 
        // add CORS filter and it should be added after the CORS
        unset($behaviors['authenticator']);

        // add CORS filter
        $behaviors['corsFilter'] = [
            'class' => '\yii\filters\Cors',
            'cors' => [
                'Origin' => ['*'],
                'Access-Control-Request-Method' => ['POST', 'OPTIONS'],
                'Access-Control-Request-Headers' => ['*'],
            ],
        ];

        //re-add authentication filter of your choce
        $behaviors['authenticator'] = [
            'class' => \yii\filters\auth\HttpBearerAuth::class
        ];

        // avoid authentication on CORS-pre-flight requests (HTTP OPTIONS method)
        $behaviors['authenticator']['except'] = ['options'];

        // Add rate limiter for sensitive endpoints
        $behaviors['rateLimiter'] = [
            'class' => RateLimiter::class,
            'enableRateLimitHeaders' => true,
            'user' => Yii::$app->user,
        ];

        // Add verb filter
        $behaviors['verbFilter'] = [
            'class' => VerbFilter::class,
            'actions' => [
                'add-admin-user' => ['POST'],
                'change-password' => ['POST'],
                'create-package' => ['POST'],
                'update-package' => ['POST'],
                'activate-package' => ['POST'],
                'diactivate-package' => ['POST'],
                'packages' => ['GET'],
                'all-packages' => ['GET'],
                'add-client' => ['POST'],
                'package-details' => ['POST'],
                'admin-users' => ['GET'],
                'client-details' => ['POST'],
                'update-client-details' => ['POST'],
                'clients' => ['GET'],
                'active-clients' => ['POST'],
                'subscriptions' => ['GET'],
                'subscription-details' => ['POST'],
                'groups' => ['GET'],
                'group-details' => ['POST'],
                'payments' => ['GET'],
                'payment-details' => ['POST'],
                'notifications' => ['GET'],
                'notification-details' => ['POST'],
                'client-audit' => ['GET'],
                'activity-details' => ['POST'],
                'admin-activity' => ['GET'],
                'admin-activity-details' => ['POST'],
            ],
        ];

        // Add access control
        $behaviors['access'] = [
            'class' => AccessControl::class,
            'rules' => [
                [
                    'allow' => true,
                    'roles' => ['@'],
                    'matchCallback' => function ($rule, $action) {
                        return $this->role === 'super_admin';
                    }
                ],
            ],
        ];

        return $behaviors;
    }
    public function init()
    {
        $method = Yii::$app->request->method;
        if ($method == 'POST' || $method == 'GET'){
            $user = new AdminUsers;
            $auth = $user->validateToken();
            if ($auth['status'])
            {
                $user_data = $auth['data'];
                $this->user_id = $user_data->user_id;
                $this->mobile = $user_data->mobile;
                $this->email = $user_data->email;
                $this->role = $user_data->role;
    
    
                //log the activity of a user
                $user_id = $this->user_id;
                $request_url = yii::$app->request->url;
                $remoteIP = Yii::$app->request->getUserIP();
                $requestParams = json_encode(Yii::$app->request->queryParams); // GET parameters
                $postParams = json_encode(Yii::$app->request->post());
                $headers = Yii::$app->request->headers;
                
                $data = [
                    'user_id' => $user_id,
                    'request_url' => $request_url,
                    'remoteIP' => $remoteIP,
                    'requestParams' => $requestParams,
                    'postParams' => $postParams,
                    'headers' => $headers
                ];
                $type = 'Admin';
    
                $helper = new Helper();
                $record = $helper->logActivity($data, $type);
    
                if ($this->role != 'super_admin') throw new HttpException(400, $auth['data']);
            }
            else
            {
                Yii::error("Failed to validate token due to: ". json_encode($auth['data']));
                throw new HttpException(403, 'Access denied');
            }  
        } 
    }

    public function actionTest(){

        return $this->controller . " IT WORKS FINE";
    }

    # DASHBOARD REGION

    # Dashboard Data
    public function actionDashboardData(){
        // Get basic stats
        $stats = [
            'clients' => (string)User::find()->count(),
            'groups' => (string)Groups::find()->count(),
            'subscriptions' => (string)Subscription::find()->count(),
            'packages' => (string)Packages::find()->count()
        ];

        // Get recent users (last 4)
        $recentUsers = User::find()
            ->select(['id', 'name', 'email', 'created_at as date'])
            ->orderBy(['created_at' => SORT_DESC])
            ->limit(4)
            ->asArray()
            ->all();

        // Get recent packages (last 3)
        $recentPackages = Packages::find()
            ->select(['id', 'name', 'price', 'created_at as date'])
            ->orderBy(['created_at' => SORT_DESC])
            ->limit(3)
            ->asArray()
            ->all();

        // Get revenue by package
        $packages = Packages::find()->all();
        $revenueByPackage = [
            'labels' => [],
            'data' => [],
            'backgroundColor' => []
        ];

        foreach ($packages as $package) {
            $revenueByPackage['labels'][] = $package->name;
            // Count subscriptions for this package
            $count = Subscription::find()
                ->where(['package_id' => $package->id])
                ->count();
            $revenueByPackage['data'][] = $count;
            $revenueByPackage['backgroundColor'][] = $package->color_code;
        }

        // Get growth trends (last 6 months)
        $growthTrends = [
            'labels' => [],
            'clients' => [],
            'revenue' => []
        ];

        // Get the first day of the current month
        $currentMonth = date('Y-m-01');
        
        // Generate last 6 months data
        for ($i = 5; $i >= 0; $i--) {
            // Calculate start and end dates for each month
            $startDate = date('Y-m-01 00:00:00', strtotime("-$i months", strtotime($currentMonth)));
            $endDate = date('Y-m-t 23:59:59', strtotime("-$i months", strtotime($currentMonth)));
            
            // Add month label
            $growthTrends['labels'][] = date('M', strtotime($startDate));
            
            // Get client count for this month
            $clientCount = User::find()
                ->where(['between', 'created_at', $startDate, $endDate])
                ->count();
            $growthTrends['clients'][] = (string)$clientCount;

            // Get revenue for this month
            $revenue = Subscription::find()
                ->select(['COALESCE(SUM(packages.price), 0) as total'])
                ->leftJoin('packages', 'packages.id = subscriptions.package_id')
                ->where(['between', 'subscriptions.created_at', $startDate, $endDate])
                ->scalar();
            $growthTrends['revenue'][] = (int)$revenue;
        }

        $response = [
            'success' => true,
            'code' => 0,
            'message' => 'Dashboard data fetched successfully',
            'data' => [
                'stats' => $stats,
                'recentUsers' => $recentUsers,
                'recentPackages' => $recentPackages,
                'revenueByPackage' => $revenueByPackage,
                'growthTrends' => $growthTrends
            ]
        ];

        return $response;
    }

    # ADMIN REGION

    # Add Admin User
    public function actionAddAdminUser()
    {
        try {
            // Validate input
            $email = Yii::$app->request->post('email');
            $name = Yii::$app->request->post('name');
            $role = Yii::$app->request->post('role');
            $mobile = Yii::$app->request->post('mobile');

            if (!$email || !$name || !$role || !$mobile) {
                throw new \Exception('Missing required fields');
            }

            // Validate email format
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \Exception('Invalid email format');
            }

            // Validate role
            if (!in_array($role, ['admin', 'super_admin'])) {
                throw new \Exception('Invalid role');
            }

            // Validate mobile number format (basic validation)
            if (!preg_match('/^[0-9]{10}$/', $mobile)) {
                throw new \Exception('Invalid mobile number format');
            }

            // Check if email already exists
            $existingUser = AdminUsers::findOne(['email' => $email]);
            if ($existingUser) {
                throw new \Exception('Email already registered');
            }

            // Generate secure random password
            $password = Yii::$app->security->generateRandomString(12);
            $hashedPassword = Yii::$app->security->generatePasswordHash($password);

            // Create new admin user
            $adminUser = new AdminUsers();
            $adminUser->email = $email;
            $adminUser->name = $name;
            $adminUser->role = $role;
            $adminUser->mobile = $mobile;
            $adminUser->password = $hashedPassword;
            $adminUser->status = 1;
            $adminUser->created_at = date('Y-m-d H:i:s');
            $adminUser->updated_at = date('Y-m-d H:i:s');

            if (!$adminUser->save()) {
                throw new \Exception('Failed to create admin user: ' . json_encode($adminUser->errors));
            }

            // Log the action
            Yii::info("New admin user created: {$email} with role: {$role}", 'admin');

            // Send email with credentials
            $this->sendAdminCredentials($email, $name, $password);

            return [
                'success' => true,
                'code' => 0,
                'message' => 'Admin user created successfully',
                'data' => [
                    'id' => $adminUser->id,
                    'email' => $adminUser->email,
                    'name' => $adminUser->name,
                    'role' => $adminUser->role,
                    'mobile' => $adminUser->mobile,
                    'status' => $adminUser->status,
                    'created_at' => $adminUser->created_at,
                ]
            ];

        } catch (\Exception $e) {
            Yii::error("Failed to create admin user: " . $e->getMessage(), 'admin');
            return [
                'success' => false,
                'code' => 1,
                'message' => $e->getMessage()
            ];
        }
    }

    private function sendAdminCredentials($email, $name, $password)
    {
        try {
            $subject = 'Your Admin Account Credentials';
            $body = "Dear {$name},\n\n";
            $body .= "Your admin account has been created. Here are your login credentials:\n\n";
            $body .= "Email: {$email}\n";
            $body .= "Password: {$password}\n\n";
            $body .= "Please change your password after your first login.\n\n";
            $body .= "Best regards,\nAdmin Team";

            Yii::$app->mailer->compose()
                ->setTo($email)
                ->setSubject($subject)
                ->setTextBody($body)
                ->send();

            Yii::info("Admin credentials sent to: {$email}", 'admin');
        } catch (\Exception $e) {
            Yii::error("Failed to send admin credentials: " . $e->getMessage(), 'admin');
            throw $e;
        }
    }

    # Change Password
    public function actionChangePassword()
    {
        //Yii::$app->helper->postRequestParams('change_password', Yii::$app->request->post());
        try {
            // Validate input
            $currentPassword = Yii::$app->request->post('currentPassword');
            $newPassword = Yii::$app->request->post('newPassword');
            $confirmPassword = Yii::$app->request->post('confirmPassword');

            if (!$currentPassword || !$newPassword || !$confirmPassword) {
                throw new \Exception('Missing required fields');
            }

            // Validate password length and complexity
            if (strlen($newPassword) < 8) {
                throw new \Exception('Password must be at least 8 characters long');
            }

            if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/', $newPassword)) {
                throw new \Exception('Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character');
            }

            if ($newPassword !== $confirmPassword) {
                throw new \Exception('New password and confirm password do not match');
            }

           

            // Get current user
            $user = AdminUsers::findOne($this->user_id);
            if (!$user) {
                throw new \Exception('User not found');
            }

            // Verify current password
            if (!Yii::$app->security->validatePassword($currentPassword, $user->password)) {
                throw new \Exception('Current password is incorrect');
            }

            // Check if new password is same as current password
            if ($currentPassword === $newPassword) {
                throw new \Exception('New password must be different from current password');
            }

            // Update password
            $user->password = Yii::$app->security->generatePasswordHash($newPassword);
            $user->updated_at = date('Y-m-d H:i:s');

            if (!$user->save()) {
                throw new \Exception('Failed to update password: ' . json_encode($user->errors));
            }

            // Log the action
            Yii::info("Password changed for user: {$user->email}", 'admin');

            // Send email notification
            $this->sendPasswordChangeNotification($user->email, $user->name);

            return [
                'success' => true,
                'code' => 0,
                'message' => 'Password changed successfully'
            ];

        } catch (\Exception $e) {
            Yii::error("Failed to change password: " . $e->getMessage(), 'admin');
            return [
                'success' => false,
                'code' => 1,
                'message' => $e->getMessage()
            ];
        }
    }

    private function sendPasswordChangeNotification($email, $name)
    {
        try {
            $subject = 'Password Changed Successfully';
            $body = "Dear {$name},\n\n";
            $body .= "Your password has been changed successfully.\n\n";
            $body .= "If you did not make this change, please contact the administrator immediately.\n\n";
            $body .= "Best regards,\nAdmin Team";

            Yii::$app->mailer->compose()
                ->setFrom(Yii::$app->params['sender_email'])  
                ->setTo($email)
                ->setSubject($subject)
                ->setTextBody($body)
                ->send();

            Yii::info("Password change notification sent to: {$email}", 'admin');
        } catch (\Exception $e) {
            Yii::error("Failed to send password change notification: " . $e->getMessage(), 'admin');
            // Don't throw the exception as this is not critical
        }
    }

    # List of Admin Users
    public function actionAdminUsers(){
        $admin_users = AdminUsers::find()
        ->select(['id', 'name', 'email', 'mobile', 'role', 'status', 'created_at', 'updated_at', 'profile'])
        ->orderBy(['created_at' => SORT_DESC])
        ->all();
        $response = [
            'success' => true,
            'code' => 0,
            'admin_users' => $admin_users
        ];

        return $response;
            
    }

    # Create Package
    public function actionCreatePackage()
    {
        try {
            // Log the sensitive operation
            $this->logSensitiveOperation('create_package', Yii::$app->request->post());

            // Validate required fields
            $name = Yii::$app->request->post('name');
            $groupLimit = Yii::$app->request->post('group_limit');
            $memberLimit = Yii::$app->request->post('member_limit');
            $duration = Yii::$app->request->post('duration');
            $price = Yii::$app->request->post('price');
            $status = Yii::$app->request->post('status');
            $colorCode = Yii::$app->request->post('color_code');
            $notificationSupport = Yii::$app->request->post('notification_support');

            if (!$name || !$groupLimit || !$memberLimit || !$duration || !$price || !$status || !$colorCode) {
                throw new \Exception('Missing required fields');
            }

            // Validate name
            if (strlen($name) < 3 || strlen($name) > 50) {
                throw new \Exception('Package name must be between 3 and 50 characters');
            }

            // Validate numeric fields
            if (!is_numeric($groupLimit) || $groupLimit <= 0) {
                throw new \Exception('Group limit must be a positive number');
            }
            if (!is_numeric($memberLimit) || $memberLimit <= 0) {
                throw new \Exception('Member limit must be a positive number');
            }
            if (!is_numeric($duration) || $duration <= 0) {
                throw new \Exception('Duration must be a positive number');
            }
            if (!is_numeric($price) || $price <= 0) {
                throw new \Exception('Price must be a positive number');
            }

            // Validate status
            if (!in_array($status, [1, 2])) {
                throw new \Exception('Invalid status value');
            }

            // Validate color code format
            if (!preg_match('/^#([0-9a-fA-F]{6})$/', $colorCode)) {
                throw new \Exception('Invalid color code format');
            }

            // Check if color code is already in use
            $existingPackage = Packages::findOne(['color_code' => $colorCode]);
            if ($existingPackage) {
                throw new \Exception('Color code is already in use');
            }

            // Check if package name already exists
            $packageName = strtoupper($name);
            $existingPackage = Packages::findOne(['name' => $packageName]);
            if ($existingPackage) {
                throw new \Exception('Package with this name already exists');
            }

            // Validate notification support
            if (empty($notificationSupport)) {
                $notificationSupport = 'email'; // default
            } else {
                $validNotifications = Yii::$app->params['support_notifications'];
                if (!in_array($notificationSupport, $validNotifications)) {
                    throw new \Exception('Invalid notification type');
                }
            }

            // Create new package
            $package = new Packages();
            $package->name = $packageName;
            $package->group_limit = (int)$groupLimit;
            $package->member_limit = (int)$memberLimit;
            $package->duration = (int)$duration;
            $package->price = (float)$price;
            $package->status = (int)$status;
            $package->color_code = $colorCode;
            $package->notification_support = $notificationSupport;
            $package->created_at = date('Y-m-d H:i:s');
            $package->updated_at = date('Y-m-d H:i:s');

            if (!$package->save()) {
                throw new \Exception('Failed to create package: ' . json_encode($package->errors));
            }

            // Log the action
            Yii::info("New package created: {$packageName} with price: {$price}", 'admin');

            return [
                'success' => true,
                'code' => 0,
                'message' => 'Package created successfully',
                'data' => [
                    'id' => $package->id,
                    'name' => $package->name,
                    'group_limit' => $package->group_limit,
                    'member_limit' => $package->member_limit,
                    'duration' => $package->duration,
                    'price' => $package->price,
                    'status' => $package->status,
                    'color_code' => $package->color_code,
                    'notification_support' => $package->notification_support,
                    'created_at' => $package->created_at
                ]
            ];

        } catch (\Exception $e) {
            Yii::error("Failed to create package: " . $e->getMessage(), 'admin');
            return [
                'success' => false,
                'code' => 1,
                'message' => $e->getMessage()
            ];
        }
    }

    # List of Active Packages
    public function actionPackages(){
        $packages = Packages::find()->where(['status' => 2])->asArray()->all();

        $response = [
            'success' => true,
            'code' => 0,
            'packages' => $packages
        ];

        return $response;

    }

      # List of All Packages
      public function actionAllPackages(){
        $packages = Packages::find()->orderBy(['created_at' => SORT_DESC])->asArray()->all();

        $response = [
            'success' => true,
            'code' => 0,
            'packages' => $packages
        ];

        return $response;
    }

    # Diactivate Package
    public function actionDiactivatePackage()
    {
        try {
            // Log the sensitive operation
            $this->logSensitiveOperation('diactivate_package', Yii::$app->request->post());

            // Validate package ID
            $packageId = Yii::$app->request->post('packageId');
            if (empty($packageId)) {
                throw new \Exception('Package ID is required');
            }

            // Get package
            $package = Packages::findOne($packageId);
            if (!$package) {
                throw new \Exception('Package not found');
            }

            // Check current status
            if ($package->status != 2) {
                throw new \Exception('Package is not active');
            }

            // Update status
            $package->status = 1; // inactive status
            $package->updated_at = date('Y-m-d H:i:s');

            if (!$package->save()) {
                throw new \Exception('Failed to deactivate package: ' . json_encode($package->errors));
            }

            // Log the action
            Yii::info("Package deactivated: {$package->name}", 'admin');

            return [
                'success' => true,
                'code' => 0,
                'message' => 'Package deactivated successfully',
                'data' => [
                    'id' => $package->id,
                    'name' => $package->name,
                    'status' => $package->status,
                    'updated_at' => $package->updated_at
                ]
            ];

        } catch (\Exception $e) {
            Yii::error("Failed to deactivate package: " . $e->getMessage(), 'admin');
            return [
                'success' => false,
                'code' => 1,
                'message' => $e->getMessage()
            ];
        }
    }

    # Activate Package
    public function actionActivatePackage()
    {
        try {
            // Log the sensitive operation
            $this->logSensitiveOperation('activate_package', Yii::$app->request->post());

            // Validate package ID
            $packageId = Yii::$app->request->post('packageId');
            if (empty($packageId)) {
                throw new \Exception('Package ID is required');
            }

            // Get package
            $package = Packages::findOne($packageId);
            if (!$package) {
                throw new \Exception('Package not found');
            }

            // Check current status
            if ($package->status == 2) {
                throw new \Exception('Package is already active');
            }

            // Update status
            $package->status = 2; // active status
            $package->updated_at = date('Y-m-d H:i:s');

            if (!$package->save()) {
                throw new \Exception('Failed to activate package: ' . json_encode($package->errors));
            }

            // Log the action
            Yii::info("Package activated: {$package->name}", 'admin');

            return [
                'success' => true,
                'code' => 0,
                'message' => 'Package activated successfully',
                'data' => [
                    'id' => $package->id,
                    'name' => $package->name,
                    'status' => $package->status,
                    'updated_at' => $package->updated_at
                ]
            ];

        } catch (\Exception $e) {
            Yii::error("Failed to activate package: " . $e->getMessage(), 'admin');
            return [
                'success' => false,
                'code' => 1,
                'message' => $e->getMessage()
            ];
        }
    }

    # Package Details
    public function actionPackageDetails(){
        //Yii::$app->helper->postRequestParams('Package Details', Yii::$app->request->post());
        $package_id = Yii::$app->request->post('id');
        if (empty($package_id)) throw new HttpException(255, 'Package ID is required', 01);

        $package = Packages::findOne($package_id);
        if (empty($package)) throw new HttpException(255, 'Package with this id is not found', 5);

        $response = [
            'success' => true,
            'code' => 0,
            'package' => $package
        ];

        return $response;
    }

    # Update Package
    public function actionUpdatePackage()
    {
        try {
            // Log the sensitive operation
            $this->logSensitiveOperation('update_package', Yii::$app->request->post());

            // Validate package ID
            $packageId = Yii::$app->request->post('id');
            if (empty($packageId)) {
                throw new \Exception('Package ID is required');
            }

            // Get existing package
            $package = Packages::findOne($packageId);
            if (!$package) {
                throw new \Exception('Package not found');
            }

            // Get update fields
            $name = Yii::$app->request->post('name');
            $groupLimit = Yii::$app->request->post('group_limit');
            $memberLimit = Yii::$app->request->post('member_limit');
            $price = Yii::$app->request->post('price');
            $status = Yii::$app->request->post('status');
            $colorCode = Yii::$app->request->post('color_code');
            $notificationSupport = Yii::$app->request->post('notification_support');

            // Check if any fields are provided for update
            if (empty($name) && empty($groupLimit) && empty($memberLimit) && 
                empty($price) && empty($status) && empty($notificationSupport) && empty($colorCode)) {
                throw new \Exception('No fields provided for update');
            }

            // Validate and update name
            if (!empty($name)) {
                if (strlen($name) < 3 || strlen($name) > 50) {
                    throw new \Exception('Package name must be between 3 and 50 characters');
                }
                $packageName = strtoupper($name);
                $existingPackage = Packages::findOne(['name' => $packageName]);
                if ($existingPackage && $existingPackage->id != $packageId) {
                    throw new \Exception('Package with this name already exists');
                }
                $package->name = $packageName;
            }

            // Validate and update group limit
            if (!empty($groupLimit)) {
                if (!is_numeric($groupLimit) || $groupLimit <= 0) {
                    throw new \Exception('Group limit must be a positive number');
                }
                $package->group_limit = (int)$groupLimit;
            }

            // Validate and update member limit
            if (!empty($memberLimit)) {
                if (!is_numeric($memberLimit) || $memberLimit <= 0) {
                    throw new \Exception('Member limit must be a positive number');
                }
                $package->member_limit = (int)$memberLimit;
            }

            // Validate and update price
            if (!empty($price)) {
                if (!is_numeric($price) || $price <= 0) {
                    throw new \Exception('Price must be a positive number');
                }
                $package->price = (float)$price;
            }

            // Validate and update status
            if (!empty($status)) {
                if (!in_array($status, [1, 2])) {
                    throw new \Exception('Invalid status value');
                }
                $package->status = (int)$status;
            }

            // Validate and update color code
            if (!empty($colorCode)) {
                if (!preg_match('/^#([0-9a-fA-F]{6})$/', $colorCode)) {
                    throw new \Exception('Invalid color code format');
                }
                $existingPackage = Packages::findOne(['color_code' => $colorCode]);
                if ($existingPackage && $existingPackage->id != $packageId) {
                    throw new \Exception('Color code is already in use');
                }
                $package->color_code = $colorCode;
            }

            // Validate and update notification support
            if (!empty($notificationSupport)) {
                $validNotifications = Yii::$app->params['support_notifications'];
                if (!in_array($notificationSupport, $validNotifications)) {
                    throw new \Exception('Invalid notification type');
                }
                $package->notification_support = $notificationSupport;
            }

            // Update timestamp
            $package->updated_at = date('Y-m-d H:i:s');

            if (!$package->save()) {
                throw new \Exception('Failed to update package: ' . json_encode($package->errors));
            }

            // Log the action
            Yii::info("Package updated: {$package->name}", 'admin');

            return [
                'success' => true,
                'code' => 0,
                'message' => 'Package updated successfully',
                'data' => [
                    'id' => $package->id,
                    'name' => $package->name,
                    'group_limit' => $package->group_limit,
                    'member_limit' => $package->member_limit,
                    'duration' => $package->duration,
                    'price' => $package->price,
                    'status' => $package->status,
                    'color_code' => $package->color_code,
                    'notification_support' => $package->notification_support,
                    'updated_at' => $package->updated_at
                ]
            ];

        } catch (\Exception $e) {
            Yii::error("Failed to update package: " . $e->getMessage(), 'admin');
            return [
                'success' => false,
                'code' => 1,
                'message' => $e->getMessage()
            ];
        }
    }

    # Update User Package
    public function actionUpdateUserPackage(){
        $user_mobile = Yii::$app->request->post('mobile');
        if (empty($user_mobile)) throw new HttpException(255, 'Mobile number of user is required', 01);
        $package_id = Yii::$app->request->post("packageId");
        if (empty($package_id)) throw new HttpException(255, 'Package Id is required', 01);

        $cus_mob = $this->helper->validateMobile($user_mobile);

        if (!$cus_mob['success']) throw new HttpException(255, $cus_mob['message'], 02);

        $user = User::find()->where(['phone_number' => $cus_mob['cus_mob']])->one();
        if (empty($user)) throw new HttpException(255, 'User not found ', 5);

        $package = Packages::findOne($package_id);
        if (empty($package)) throw new HttpException(255, 'Package with this Id not found', 5);


        if ($package->status != 2) throw new HttpException(255, 'Package is not active', 7);

        $user->package_id = $package_id;
        if ($user->save()){
            $response = [
                'success' => true,
                'code' => 0,
                'message' => 'User Package Updated successful'
            ];
        }
        else{
            Yii::error("Failed to update user Package due to: ". json_encode($user->errors));

            throw new HttpException(255, 'Failed to update User Package', 9);
        }

        return $response;
    }

    # Diactive Client
    public function actionDiactivateUser()
    {
        try {
            // Log the sensitive operation
            $this->logSensitiveOperation('diactivate_user', Yii::$app->request->post());

            // Validate phone number
            $phone = Yii::$app->request->post('mobile');
            if (empty($phone)) {
                throw new \Exception('Mobile number is required');
            }

            // Validate mobile number format
            $mobileValidation = Yii::$app->helper->validateMobile($phone);
            if (!$mobileValidation['success']) {
                throw new \Exception($mobileValidation['message']);
            }
            $phone = $mobileValidation['cus_mob'];

            // Get user
            $user = User::findOne(['phone_number' => $phone]);
            if (!$user) {
                throw new \Exception('User not found');
            }

            // Check current status
            if ($user->status !== 'active') {
                throw new \Exception('User is not active');
            }

            // Update status
            $user->status = 'inactive';
            $user->updated_at = date('Y-m-d H:i:s');

            if (!$user->save()) {
                throw new \Exception('Failed to deactivate user: ' . json_encode($user->errors));
            }

            // Generate notification
            $params = [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'lang' => $user->language,
                'type' => 'email',
                'process' => 'account_deactivation',
            ];

            $notificationSent = Yii::$app->helper->generateNotification($params);
            if (!$notificationSent) {
                Yii::error("Failed to generate deactivation notification for user: {$user->id}", 'admin');
            }

            // Log the action
            Yii::info("User deactivated: {$user->name} ({$user->phone_number})", 'admin');

            return [
                'success' => true,
                'code' => 0,
                'message' => 'User deactivated successfully',
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'phone_number' => $user->phone_number,
                    'status' => $user->status,
                    'updated_at' => $user->updated_at
                ]
            ];

        } catch (\Exception $e) {
            Yii::error("Failed to deactivate user: " . $e->getMessage(), 'admin');
            return [
                'success' => false,
                'code' => 1,
                'message' => $e->getMessage()
            ];
        }
    }

    # Active Client
    public function actionActivateUser()
    {
        try {
            // Log the sensitive operation
            $this->logSensitiveOperation('activate_user', Yii::$app->request->post());

            // Validate phone number
            $phone = Yii::$app->request->post('mobile');
            if (empty($phone)) {
                throw new \Exception('Mobile number is required');
            }

            // Validate mobile number format
            $mobileValidation = Yii::$app->helper->validateMobile($phone);
            if (!$mobileValidation['success']) {
                throw new \Exception($mobileValidation['message']);
            }
            $phone = $mobileValidation['cus_mob'];

            // Get user
            $user = User::findOne(['phone_number' => $phone]);
            if (!$user) {
                throw new \Exception('User not found');
            }

            // Check current status
            if ($user->status === 'active') {
                throw new \Exception('User is already active');
            }

            // Update status
            $user->status = 'active';
            $user->updated_at = date('Y-m-d H:i:s');

            if (!$user->save()) {
                throw new \Exception('Failed to activate user: ' . json_encode($user->errors));
            }

            // Generate notification
            $params = [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'lang' => $user->language,
                'type' => 'email',
                'process' => 'account_activation',
            ];

            $notificationSent = Yii::$app->helper->generateNotification($params);
            if (!$notificationSent) {
                Yii::error("Failed to generate activation notification for user: {$user->id}", 'admin');
            }

            // Log the action
            Yii::info("User activated: {$user->name} ({$user->phone_number})", 'admin');

            return [
                'success' => true,
                'code' => 0,
                'message' => 'User activated successfully',
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'phone_number' => $user->phone_number,
                    'status' => $user->status,
                    'updated_at' => $user->updated_at
                ]
            ];

        } catch (\Exception $e) {
            Yii::error("Failed to activate user: " . $e->getMessage(), 'admin');
            return [
                'success' => false,
                'code' => 1,
                'message' => $e->getMessage()
            ];
        }
    }

    # Genereate Contribution schedule for group
    public function actionGenerateContributionSchedule(){
        $group_id = Yii::$app->request->post('groupId');
        if (empty($group_id)) throw new HttpException(255, 'Group Id is required', 01);


        $generate = Helper::generateContributionSchedule($group_id);
        if ($generate){
            $response = [
                'success' => true,
                'code' => 0,
                'message' => 'Contribution generated Successful'
            ];
        }
        else throw new HttpException(255, 'Failed to genereate contribution schedule', 50);

        return $response;
    }

    # Clients 
    public function actionClients(){
        $clients = User::find()
        ->select(['id', 'name', 'email', 'role', 'status', 'created_at as joinDate'])
        ->orderBy(['created_at' => SORT_DESC])
        ->asArray()
        ->all();

        $response = [
            'success' => true,
            'code' => 0,
            'message' => 'fetch successful',
            'data' => $clients
        ];

        return $response;
    }

    # Active Clients 
    public function actionActiveClients(){
        $clients = User::find()
        ->select(['id', 'name', 'email', 'role', 'status', 'created_at as joinDate'])
        ->where(['status' => 'active'])
        ->orderBy(['created_at' => SORT_DESC])
        ->asArray()
        ->all();

        $response = [
            'success' => true,
            'code' => 0,
            'message' => 'fetch successful',
            'data' => $clients
        ];

        return $response;
    }

    # Add Client
    public function actionAddClient()
    {
        try {
            // Validate required fields
            $name = Yii::$app->request->post('name');
            $email = Yii::$app->request->post('email');
            $phone = Yii::$app->request->post('phone_number');
            $status = Yii::$app->request->post('status');
            $password = Yii::$app->request->post('password');
            $confirmPassword = Yii::$app->request->post('confirmPassword');

            if (!$name || !$phone || !$status || !$password || !$confirmPassword) {
                throw new \Exception('Missing required fields');
            }

            // Validate name
            if (strlen($name) < 2 || strlen($name) > 50) {
                throw new \Exception('Name must be between 2 and 50 characters');
            }

            // Validate email if provided
            if (!empty($email)) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new \Exception('Invalid email format');
                }
                // Check if email already exists
                $existingUser = User::findOne(['email' => $email]);
                if ($existingUser) {
                    throw new \Exception('Email already registered');
                }
            }

            // Validate phone number
            $mobileValidation = Yii::$app->helper->validateMobile($phone);
            if (!$mobileValidation['success']) {
                throw new \Exception($mobileValidation['message']);
            }
            $phone = $mobileValidation['cus_mob'];

            // Check if phone number already exists
            $existingUser = User::findOne(['phone_number' => $phone]);
            if ($existingUser) {
                throw new \Exception('Phone number already registered');
            }

            // Validate status
            if (!in_array($status, ['active', 'inactive'])) {
                throw new \Exception('Invalid status value');
            }

            // Validate password
            if (strlen($password) < 8) {
                throw new \Exception('Password must be at least 8 characters long');
            }

            if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/', $password)) {
                throw new \Exception('Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character');
            }

            if ($password !== $confirmPassword) {
                throw new \Exception('Password and confirm password do not match');
            }

            // Create new user
            $user = new User();
            $user->name = strtoupper($name);
            $user->email = $email;
            $user->phone_number = $phone;
            $user->password_hash = Yii::$app->security->generatePasswordHash($password);
            $user->status = $status;
            $user->created_at = date('Y-m-d H:i:s');
            $user->package_id = 1; // default package
            $user->profile_picture = 'default.jpg';
            $user->role = 'Client';

            if (!$user->save()) {
                throw new \Exception('Failed to create user: ' . json_encode($user->errors));
            }

            // Create subscription
            $subscription = new Subscription();
            $subscription->user_id = $user->id;
            $subscription->package_id = $user->package_id;
            $subscription->duration = 30; // default duration 1 month
            $subscription->activated_at = date('Y-m-d H:i:s');
            $subscription->expire_at = date('Y-m-d H:i:s', strtotime('+30 days'));
            $subscription->created_at = date('Y-m-d H:i:s');
            $subscription->updated_at = date('Y-m-d H:i:s');
            $subscription->created_by = $this->user_id;

            if (!$subscription->save()) {
                // Log the error but don't throw exception as user is already created
                Yii::error("Failed to create subscription: " . json_encode($subscription->errors), 'admin');
            }

            // Generate notification
            $package = Packages::findOne($user->package_id);
            if (!$package) {
                Yii::error("Package not found for user: {$user->id}", 'admin');
            } else {
                $params = [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'lang' => $user->language,
                    'type' => $package->notification_support,
                    'process' => 'registration',
                ];

                $notificationSent = Yii::$app->helper->generateNotification($params);
                if (!$notificationSent) {
                    Yii::error("Failed to generate notification for user: {$user->id}", 'admin');
                }
            }

            // Log the action
            Yii::info("New client created: {$user->name} ({$user->phone_number})", 'admin');

            return [
                'success' => true,
                'code' => 0,
                'message' => 'Client created successfully',
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone_number' => $user->phone_number,
                    'status' => $user->status,
                    'created_at' => $user->created_at
                ]
            ];

        } catch (\Exception $e) {
            Yii::error("Failed to create client: " . $e->getMessage(), 'admin');
            return [
                'success' => false,
                'code' => 1,
                'message' => $e->getMessage()
            ];
        }
    }

    # Client Details
    public function actionClientDetails()
    {
        //Yii::$app->helper->postRequestParams('client Details', Yii::$app->request->post());
        $client_id = Yii::$app->request->post('id');
        if (empty($client_id)) throw new HttpException(255, 'Client id is required', 01);


        $Client = User::find()
        ->select(['users.*', 'packages.name as package_name'])
        ->leftJoin('packages', 'packages.id = users.package_id')
        ->where(['users.id' => $client_id])
        ->asArray()
        ->one();
        if (empty($Client)) throw new HttpException(255, 'Client not found', 5);

        $subscriptions = Subscription::find()
        ->select(['subscriptions.*', 'packages.name as package_name'])
        ->leftJoin('packages', 'packages.id = subscriptions.package_id')
        ->where(['user_id' => $client_id])
        ->asArray()
        ->one();

        $groups = GroupMembers::find()->where(['user_id' => $client_id])->all();
        
        $group_ids = array_column($groups, 'group_id');
        $groups = Groups::find()
            ->select([
                'groups.*',
                'users.name as creator_name',
                'group_members.joined_at'
            ])
            ->leftJoin('users', 'users.id = groups.created_by')
            ->leftJoin('group_members', 'group_members.group_id = groups.id AND group_members.user_id = :client_id', [':client_id' => $client_id])
            ->where(['groups.id' => $group_ids])
            ->orderBy(['groups.created_at' => SORT_DESC])
            ->asArray()
            ->all();

        //payments
        $payments = Payments::find()
            ->select([
                'payments.*',
                'groups.name as group_name',
                'users.name as verified_by_name',
                'payer.name as payer_name'
            ])
            ->leftJoin('groups', 'groups.id = payments.group_id')
            ->leftJoin('users', 'users.id = payments.verified_by')
            ->leftJoin(['payer' => 'users'], 'payer.id = payments.user_id')
            ->where(['payments.user_id' => $client_id])
            ->orderBy(['payments.payment_date' => SORT_DESC])
            ->asArray()
            ->all();

        //pledges
        $pledges = Pledges::find()
            ->select([
                'pledges.*',
                'groups.name as group_name'
            ])
            ->leftJoin('groups', 'groups.id = pledges.group_id')
            ->where(['pledges.user_id' => $client_id])
            ->orderBy(['pledges.pledge_date' => SORT_DESC])
            ->asArray()
            ->all();

        //contribution schedule
        $contributionSchedule = ContributionSchedule::find()
            ->select([
                'contribution_schedule.*',
                'groups.name as group_name'
            ])
            ->leftJoin('groups', 'groups.id = contribution_schedule.group_id')
            ->where(['contribution_schedule.user_id' => $client_id])
            ->orderBy(['contribution_schedule.due_date' => SORT_ASC])
            ->asArray()
            ->all();

        //payout schedules
        $payoutSchedule = PayoutSchedule::find()
            ->select([
                'payout_schedule.*',
                'groups.name as group_name'
            ])
            ->leftJoin('groups', 'groups.id = payout_schedule.group_id')
            ->where(['payout_schedule.user_id' => $client_id])
            ->orderBy(['payout_schedule.scheduled_date' => SORT_ASC])
            ->asArray()
            ->all();

        //notifications
        $notifications = NotificationRecipient::find()
            ->select([
                'notification_recipient.*',
                'notifications.title',
                'notifications.message',
                'notifications.type',
                'notifications.sent_at',
                'notifications.group_id',
                'groups.name as group_name'
            ])
            ->leftJoin('notifications', 'notifications.id = notification_recipient.notification_id')
            ->leftJoin('groups', 'groups.id = notifications.group_id')
            ->where(['notification_recipient.user_id' => $client_id])
            ->orderBy(['notification_recipient.created_at' => SORT_DESC])
            ->asArray()
            ->all();

        $response = [
            'success' => true,
            'code' => 0,
            'message'=> 'fetch successful',
            'data' => $Client,
            'subscription' => $subscriptions,
            'groups' => $groups,
            'payments' => $payments,
            'pledges' => $pledges,
            'contribution_schedule' => $contributionSchedule,
            'payout_schedule' => $payoutSchedule,
            'notifications' => $notifications
        ];

        return $response;
    }

    # UPDATE CLIENT DETAILS
    public function actionUpdateClientDetails(){
        //Yii::$app->helper->postRequestParams('update details', Yii::$app->request->post());
        $client_id = Yii::$app->request->post('id');
        if (empty($client_id)) throw new HttpException(255, 'Client ID is required', 01);
        $package = Yii::$app->request->post('package_id');
        $status = Yii::$app->request->post('status');

        $client = User::find()->where(['id' => $client_id])->one();
        if (empty($client)) throw new HttpException(255, 'Client not found', 5);

        // Validate and update language if provided
        if (!empty($status)) {
            if (!in_array($status, ['active', 'inactive'])) {
                throw new HttpException(255, 'Invalid status value', 16);
            }
            $client->status = $status;
        }

        if (!empty($package)){
            $client->package_id = $package;

            //get user subscription
            $subscription = Subscription::find()->where(['user_id' => $client_id])->one();
            if (empty($subscription)) throw new HttpException(255, 'Subscription not found', 5);

            $subscription->package_id = $package;
            $subscription->save(false);
        }

        if ($client->save()){
            $response = [
                'success' => true,
                'code' => 0,
                'message' => 'User details updated successfully',
            ];
        }
        else{
            Yii::error("Save failed with errors: " . json_encode($client->errors));
            throw new HttpException(255, 'User details update failed', 9);
        }

        return $response;

    }

    # Subscriptions
    public function actionSubscriptions(){
        $subscriptions = Subscription::find()
        ->select([
            'subscriptions.*',
            'packages.name as package_name',
            'users.name as user_name'
        ])
        ->leftJoin('packages', 'packages.id = subscriptions.package_id')
        ->leftJoin('users', 'users.id = subscriptions.user_id')
        ->orderBy(['created_at' => SORT_DESC])
        ->asArray()
        ->all();

        $response = [
            'success' => true,
            'code' => 0,
            'message' => 'Subscriptions fetched successfully',
            'data' => $subscriptions
        ];

        return $response;
    }

    # Subscription Details
    public function actionSubscriptionDetails(){
        //Yii::$app->helper->postRequestParams('Subscription Details', Yii::$app->request->post());
        $subscription_id = Yii::$app->request->post('id');
        if (empty($subscription_id)) throw new HttpException(255, 'Subscription Id is required', 01);

        $subscriptions = Subscription::find()
        ->select([
            'subscriptions.*',
            'packages.name as package_name',
            'users.name as user_name',
            'admin_users.name as creator_name',
            'updater.name as updater_name'
        ])
        ->leftJoin('packages', 'packages.id = subscriptions.package_id')
        ->leftJoin('users', 'users.id = subscriptions.user_id')
        ->leftJoin('admin_users', 'admin_users.id = subscriptions.created_by')
        ->leftJoin('admin_users as updater', 'updater.id = subscriptions.updated_by')
        ->where(['subscriptions.id' => $subscription_id])
        ->orderBy(['created_at' => SORT_DESC])
        ->asArray()
        ->one();

        if (empty($subscriptions)) throw new HttpException(255, 'Subscription not found', 5);

        $response = [
            'success' => true,
            'code' => 0,
            'message' => 'Subscriptions fetched successfully',
            'data' => $subscriptions
        ];
        
        return $response;
    }

    # new Subscription
    public function actionNewSubscription(){
        //Yii::$app->helper->postRequestParams('New Subscription', Yii::$app->request->post());
        $user_id = Yii::$app->request->post('user_id');
        if (empty($user_id)) throw new HttpException(255, 'User Id is required', 01);
        $package_id = Yii::$app->request->post('package_id');
        if (empty($package_id)) throw new HttpException(255, 'Package Id is required', 01);
        $duration = Yii::$app->request->post('duration');
        if (empty($duration)) throw new HttpException (255, 'Duration is required', 01);
        $activated_at = Yii::$app->request->post('activated_at');
        if (empty($activated_at)) throw new HttpException(255, 'Acivation date is required', 01);
        $expire_at = Yii::$app->request->post('expire_at');
        if (empty($expire_at)) throw new HttpException(255, 'Expire date is required', 01);

        //check if the user is found
        $user = User::find()->where(['id' => $user_id])->one();
        if (empty($user)) throw new HttpException(255, 'User not found', 5);

        //check if package is found
        $package =  Packages::find()->where(['id' => $package_id])->one();
        if (empty($package)) throw new HttpException(255, 'Package not found', 5);

        //check if user is found in subscription table
        $subscription = Subscription::find()->where(['user_id' => $user_id])->one();

        if (empty($subscription)){
            $subscription = new Subscription();
            $subscription->user_id = $user_id;
            $subscription->created_at = date('Y-m-d H:i:s');
            $subscription->created_by = $this->user_id;
        }
        else{
            $subscription->updated_at = date('Y-m-d H:i:s');
            $subscription->updated_by = $this->user_id;
        }

        $subscription->package_id = $package_id;
        $subscription->activated_at = $activated_at;
        $subscription->expire_at = $expire_at;
        $subscription->duration = $duration;
        if ($subscription->save()){
            $response = [
                'success' => true,
                'code' => 0,
                'message' => 'New subscription created successfully'
            ];

            return $response;
        }
        else{
            $errorMessages = '';
            foreach ($subscription->errors as $errors) {
                $errorMessages .= implode(', ', $errors) . ', ';
            }
            $errorMessages = rtrim($errorMessages, ', ');
             
            //log custom error
            Yii::$app->helper->customErrors('New Subscriptions', $errorMessages, $this->controller);
 
            throw new HttpException(255, "Failed To Save Subscription", 04);
        }       
    }

    # Group Lists
    public function actionGroups(){
        $groups = Groups::find()
        ->select([
            'groups.*',
            'users.name as creator_name'
        ])
        ->leftJoin('users', 'users.id = groups.created_by')
        ->orderBy(['groups.created_at' => SORT_DESC])
        ->asArray()
        ->all();

        $response = [
            'success' => true,
            'code' => 0,
            'message' => 'Groups fetched successfully',
            'data' => $groups
        ];

        return $response;
    }

    # Group Details
    public function actionGroupDetails(){
        //Yii::$app->helper->postRequestParams('Group Details', Yii::$app->request->post());
        $group_id = Yii::$app->request->post('id');
        if (empty($group_id)) throw new HttpException(255, 'Group Id is required', 01);

        $group = Groups::find()
            ->select(['groups.*', 'users.name as creator_name'])
            ->leftJoin('users', 'users.id = groups.created_by')
            ->where(['groups.id' => $group_id])
            ->asArray()
            ->one();
        
        if (empty($group)) throw new HttpException(255, 'Group not found', 5); 

        $group_members = GroupMembers::find()
            ->select([
                'group_members.id',
                'group_members.user_id',
                'group_members.group_id',
                'group_members.role',
                'group_members.is_active',
                'group_members.joined_at',
                'users.name as user_name',
                'users.profile_picture as profile_picture',
                'groups.type as group_type',
            ])
            ->leftJoin('users', 'users.id = group_members.user_id')
            ->leftJoin('groups', 'groups.id = group_members.group_id')
            ->where(['group_members.group_id' => $group_id])
            ->orderBy(['group_members.joined_at' => SORT_DESC])
            ->asArray()
            ->all();  
            
            $response = [
                'success' => true,
                'code' => 0,
                'group' => $group,
                'group_members' => $group_members,
            ];

            //Yii::$app->helper->postRequestParams('Group Details', $response);

            return $response;  
    }

    # Payments
    public function actionPayments(){
        $payments = Payments::find()
        ->select([
            'payments.*',
            'groups.name as group_name',
            'users.name as verified_by_name',
            'payer.name as payer_name'
        ])
        ->leftJoin('groups', 'groups.id = payments.group_id')
        ->leftJoin('users', 'users.id = payments.verified_by')
        ->leftJoin(['payer' => 'users'], 'payer.id = payments.user_id')
        ->orderBy(['payments.payment_date' => SORT_DESC])
        ->asArray()
        ->all();

        $response = [
            'success' => true,
            'code' => 0,
            'message' => 'Payments fetched successfully',
            'data' => $payments
        ];

        return $response;
    }

    # Payment Details
    public function actionPaymentDetails(){
        //Yii::$app->helper->postRequestParams('Payment Details', Yii::$app->request->post());
        $payment_id = Yii::$app->request->post('id');
        if (empty($payment_id)) throw new HttpException(255, 'Payment Id is required', 01);

        $payment = Payments::find()
        ->select([
            'payments.*',
            'groups.name as group_name',
            'users.name as verified_by_name',
            'payer.name as payer_name'
        ])  
        ->leftJoin('groups', 'groups.id = payments.group_id')
        ->leftJoin('users', 'users.id = payments.verified_by')
        ->leftJoin(['payer' => 'users'], 'payer.id = payments.user_id')
        ->where(['payments.id' => $payment_id])
        ->asArray()
        ->one();    

        $response = [
            'success' => true,
            'code' => 0,
            'message' => 'Payment details fetched successfully',
            'data' => $payment
        ];

        return $response;
    } 
    
    # Notifications
    public function actionNotifications(){
        $notifications = NotificationRecipient::find()
        ->select([
            'notification_recipient.*',
            'notifications.title',
            'notifications.message',
            'notifications.type',
            'notifications.sent_at',
            'notifications.group_id',
            'groups.name as group_name',
            'users.name as user_name'
        ])
        ->leftJoin('notifications', 'notifications.id = notification_recipient.notification_id')
        ->leftJoin('users', 'users.id = notification_recipient.user_id')
        ->leftJoin('groups', 'groups.id = notifications.group_id')
        ->orderBy(['notification_recipient.created_at' => SORT_DESC])
        ->asArray()
        ->all();        

        $response = [
            'success' => true,
            'code' => 0,
            'message' => 'Notifications fetched successfully',
            'data' => $notifications
        ];
        
        return $response;
    }

    # Notification Details
    public function actionNotificationDetails(){
        //Yii::$app->helper->postRequestParams('Notification Details', Yii::$app->request->post());
        $notification_id = Yii::$app->request->post('id');
        if (empty($notification_id)) throw new HttpException(255, 'Notification Id is required', 01);

        $notification = NotificationRecipient::find()
        ->select([
            'notification_recipient.*',
            'notifications.title',
            'notifications.message',
            'notifications.type',
            'notifications.sent_at',
            'notifications.group_id',
            'groups.name as group_name',
            'users.name as user_name'
        ])  
        ->leftJoin('notifications', 'notifications.id = notification_recipient.notification_id')
        ->leftJoin('users', 'users.id = notification_recipient.user_id')
        ->leftJoin('groups', 'groups.id = notifications.group_id')
        ->where(['notification_recipient.id' => $notification_id])
        ->asArray()
        ->one();    

        $response = [
            'success' => true,
            'code' => 0,
            'message' => 'Notification details fetched successfully',
            'data' => $notification
        ];

        return $response;
    }   

    # Generate Notification
    public function actionNewNotification(){
        //Yii::$app->helper->postRequestParams('Generate Notification', Yii::$app->request->post());
        
        $broadcast = Yii::$app->request->post('broadcast');
        if (empty($broadcast)) throw new HttpException(255, 'Broadcast is required', 01);

        $title = Yii::$app->request->post('title');
        if (empty($title)) throw new HttpException(255, 'Title is required', 01);

        $message = Yii::$app->request->post('message');
        if (empty($message)) throw new HttpException(255, 'Message is required', 01);

        $type = Yii::$app->request->post('type');
        if (empty($type)) throw new HttpException(255, 'Type is required', 01);
        
        $receiver = Yii::$app->request->post('client_id');

        $group_id = Yii::$app->request->post('group_id');

        $notification = new Notifications();


        //broadcast can be group or individual or all clients
        if ($broadcast == 'group'){
            if (empty($group_id)) throw new HttpException(255, 'Group Id is required', 01);
            $notification->group_id = $group_id;
        }
        else if ($broadcast == 'individual'){
            if (empty($receiver)) throw new HttpException(255, 'Receiver is required', 01);
            $notification->user_id = $receiver;
        }

        else if ($broadcast == 'all'){
            //get all clients
            $clients = User::find()->where(['status' => 'active'])->all();
        }

        //create notification
        $notification->title = strtoupper($title);
        $notification->message = $message;
        $notification->type = $type;
        $notification->created_at = date('Y-m-d H:i:s');
        $notification->status = 0;
        if ($notification->save()){
            //record recipients
            if ($broadcast == 'group'){
                $userIds = GroupMembers::find()->select('user_id')->where(['group_id' => $group_id])->column();
            }
            else if ($broadcast == 'individual'){
                $userIds = [$receiver];
            }
            else if ($broadcast == 'all'){
                $userIds = User::find()->select('id')->where(['status' => 'active'])->column();
            }

            foreach ($userIds as $userId) {
                $recipient = new NotificationRecipient();
                $recipient->notification_id = $notification->id;
                $recipient->user_id = $userId;
                $recipient->created_at = date('Y-m-d H:i:s');
                $recipient->status = 0;
                $recipient->save(false);
            }

            $response = [
                'success' => true,
                'code' => 0,
                'message' => 'Notification Created successfully'
            ];
    
            return $response;
        }
        else{
            $errorMessages = '';
            foreach ($notification->errors as $errors) {
                $errorMessages .= implode(', ', $errors) . ', ';
            }
            $errorMessages = rtrim($errorMessages, ', ');

            //log custom error
            Yii::$app->helper->customErrors('New Notification', $errorMessages, $this->controller);

            throw new HttpException(255, "Failed To Create Notification", 04);
        } 
    }

    # Resend Notification
    public function actionResendNotification(){
        //Yii::$app->helper->postRequestParams('Resend Notification', Yii::$app->request->post());
        $notification_id = Yii::$app->request->post('id');
        if (empty($notification_id)) throw new HttpException(255, 'Notification Id is required', 01);

        $notification = NotificationRecipient::find()->where(['id' => $notification_id])->one();

        if (empty($notification)) throw new HttpException(255, 'Notification not found', 5);

        $notification->status = 0; //pending to be sent
        $notification->save(false);

        $response = [
            'success' => true,
            'code' => 0,
            'message' => 'Notification resent successfully'
        ];

        return $response;
    }

    # Client Audit
    public function actionClientAudit(){
        $activity = ActivityLogs::find()
        ->select([
            'activity_logs.*',
            'users.name as user_name'
        ])
        ->leftJoin('users', 'users.id = activity_logs.user_id')
        ->orderBy(['activity_logs.id' => SORT_DESC])
        ->asArray()
        ->all();

        $response = [
            'success' => true,
            'code' => 0,
            'message' => 'Client audit fetched successfully',
            'data' => $activity
        ];

        return $response;   
    }

    # Activity Details
    public function actionActivityDetails(){
        //Yii::$app->helper->postRequestParams('Activity Details', Yii::$app->request->post());
        $activity_id = Yii::$app->request->post('id');
        if (empty($activity_id)) throw new HttpException(255, 'Activity Id is required', 01);

        $activity = ActivityLogs::find()
        ->select([
            'activity_logs.*',
            'users.name as user_name'
        ])
        ->leftJoin('users', 'users.id = activity_logs.user_id')
        ->where(['activity_logs.id' => $activity_id])
        ->asArray()
        ->one();

        $response = [
            'success' => true,
            'code' => 0,
            'message' => 'Activity details fetched successfully',
            'data' => $activity
        ];

        return $response;
    }   

    # Admin Activity
    public function actionAdminActivity(){
        $activity = AdminActivityLogs::find()
        ->select([
            'admin_activity_logs.*',
            'admin_users.name as admin_name'
        ])  
        ->leftJoin('admin_users', 'admin_users.id = admin_activity_logs.user_id')
        ->where(['!=', 'admin_activity_logs.user_id', $this->user_id])
        ->orderBy(['admin_activity_logs.id' => SORT_DESC])
        ->asArray()
        ->all();

        $response = [
            'success' => true,
            'code' => 0,
            'message' => 'Admin activity fetched successfully',
            'data' => $activity
        ];

        return $response;
    }

    # Admin Activity Details
    public function actionAdminActivityDetails(){
        //Yii::$app->helper->postRequestParams('Admin Activity Details', Yii::$app->request->post());
        $activity_id = Yii::$app->request->post('id');
        if (empty($activity_id)) throw new HttpException(255, 'Activity Id is required', 01);

        $activity = AdminActivityLogs::find()
        ->select([
            'admin_activity_logs.*',
            'admin_users.name as admin_name'
        ])
        ->leftJoin('admin_users', 'admin_users.id = admin_activity_logs.user_id')
        ->where(['admin_activity_logs.id' => $activity_id])
        ->asArray()
        ->one();

        $response = [
            'success' => true,
            'code' => 0,
            'message' => 'Admin activity details fetched successfully',
            'data' => $activity
        ];

        return $response;
    }

    /**
     * Logs sensitive operations performed by admin users
     * @param string $operation The type of operation being performed
     * @param array $data The data associated with the operation
     * @return void
     */
    private function logSensitiveOperation($operation, $data)
    {
        try {
            $log = new AdminActivityLogs();
            $log->user_id = $this->user_id;
            $log->action = $operation;
            $log->post_params = json_encode($data);
            $log->remote_ip = Yii::$app->request->getUserIP();
            $log->created_at = date('Y-m-d H:i:s');
            
            if (!$log->save()) {
                Yii::error("Failed to log sensitive operation: " . json_encode($log->errors), 'admin');
            }
        } catch (\Exception $e) {
            Yii::error("Error logging sensitive operation: " . $e->getMessage(), 'admin');
        }
    }
        
    #TODO:: ADD PAYMENT METHODS that user can use

}