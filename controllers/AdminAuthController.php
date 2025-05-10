<?php

namespace app\controllers;

use Yii;
use app\models\AdminUsers;
use app\models\Subscription;
use app\models\Notifications;
use app\models\NotificationRecipient;
use app\models\ForgetPasswordVerification;
use app\components\Helper;
use app\models\Groups;
use app\models\Packages;
use app\models\User;
use yii\rest\Controller;
use yii\web\HttpException;
use \Firebase\JWT\JWT;
use yii\filters\Cors;


class AdminAuthController extends Controller
{
    public $controller = 'Admin Auth Controller';

    public function behaviors()
    {
        $behaviors = parent::behaviors();

        // Add CORS filter
        $behaviors['corsFilter'] = [
            'class' => Cors::class,
            'cors' => [
                'Origin' => ['*'],
                'Access-Control-Request-Method' => ['POST', 'OPTIONS'],
                'Access-Control-Request-Headers' => ['*'],
            ],
        ];

        // Add rate limiter for auth endpoints
        $behaviors['rateLimiter'] = [
            'class' => \yii\filters\RateLimiter::class,
            'enableRateLimitHeaders' => true,
            'user' => Yii::$app->user,
        ];

        // Add verb filter
        $behaviors['verbFilter'] = [
            'class' => \yii\filters\VerbFilter::class,
            'actions' => [
                'login' => ['POST'],
                'forgot-password' => ['POST'],
                'test' => ['GET'],
            ],
        ];

        return $behaviors;
    }

    public function actionTest()
    {
        return $this->controller . ' is working';
    }

    public function actionLogin()
    {
        try {
            // Log the login attempt
            $this->logLoginAttempt();

            // Validate input
            $email = Yii::$app->request->post('email');
            $password = Yii::$app->request->post('password');

            if (empty($email) || empty($password)) {
                throw new \Exception('Email and password are required');
            }

            // Validate email format
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \Exception('Invalid email format');
            }

            // Find user
            $user = AdminUsers::findOne(['email' => $email]);
            if (!$user) {
                // Log failed attempt
                $this->logFailedLogin($email, 'User not found');
                throw new \Exception('Invalid credentials');
            }

            // Validate password
            if (!Yii::$app->security->validatePassword($password, $user->password)) {
                // Log failed attempt
                $this->logFailedLogin($email, 'Invalid password');
                throw new \Exception('Invalid credentials');
            }

            // Check account status
            if ($user->status !== 'active') {
                throw new \Exception('Account is not active');
            }

            // Generate JWT token
            $token = $this->generateJwtToken($user);

            // Log successful login
            $this->logSuccessfulLogin($user);

            return $this->asJson([
                'status' => 'success',
                'code' => 0,
                'statusCode' => 200,
                'message' => 'Login successful',
                'data' => [
                    'token' => $token,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phoneNumber' => $user->mobile,
                    'profile_picture' => $user->profile,
                    'role' => $user->role,
                ]
            ]);

        } catch (\Exception $e) {
            Yii::error("Login failed: " . $e->getMessage(), 'admin_auth');
            return $this->asJson([
                'status' => 'error',
                'code' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function actionForgotPassword()
    {
        try {
            // Log the password reset attempt
            $this->logPasswordResetAttempt();

            // Validate input
            $email = Yii::$app->request->post('email');
            if (empty($email)) {
                throw new \Exception('Email is required');
            }

            // Validate email format
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \Exception('Invalid email format');
            }

            // Find user
            $user = AdminUsers::findOne(['email' => $email]);
            if (!$user) {
                // Log failed attempt
                $this->logFailedPasswordReset($email, 'User not found');
                throw new \Exception('Invalid email address');
            }

            // Generate secure random password
            $password = Yii::$app->security->generateRandomString(12);
            $hashedPassword = Yii::$app->security->generatePasswordHash($password);

            // Update user
            $user->password = $hashedPassword;
            $user->status = 'active';
            $user->updated_at = date('Y-m-d H:i:s');

            if (!$user->save()) {
                throw new \Exception('Failed to reset password: ' . json_encode($user->errors));
            }

            // Log credentials
            $this->logPasswordReset($email, $password);

            // Send email
            $this->sendPasswordResetEmail($email, $password);

            // Log successful reset
            $this->logSuccessfulPasswordReset($email);

            return $this->asJson([
                'status' => 'success',
                'code' => 0,
                'message' => 'Password reset successful. Please check your email for the new password.'
            ]);

        } catch (\Exception $e) {
            Yii::error("Password reset failed: " . $e->getMessage(), 'admin_auth');
            return $this->asJson([
                'status' => 'error',
                'code' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    private function generateJwtToken($user)
    {
        $tokenId = bin2hex(random_bytes(32));
        $issuedAt = time();
        $notBefore = $issuedAt;
        $expire = $notBefore + 3600; // 1 hour

        $data = [
            'iat' => $issuedAt,
            'jti' => $tokenId,
            'iss' => Yii::$app->params['server_name'],
            'nbf' => $notBefore,
            'exp' => $expire,
            'data' => [
                'user_id' => $user->id,
                'mobile' => $user->mobile,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ]
        ];

        return JWT::encode($data, Yii::$app->params['jwt_key'], 'HS256');
    }

    private function sendPasswordResetEmail($email, $password)
    {
        try {
            $subject = 'Password Reset';
            $body = "Dear User,\n\n";
            $body .= "Your password has been reset successfully.\n\n";
            $body .= "Your new password is: {$password}\n\n";
            $body .= "Please change your password after logging in.\n\n";
            $body .= "Best regards,\nAdmin Team";

            return Yii::$app->mailer->compose()
                ->setFrom(Yii::$app->params['sender_email'])  
                ->setTo($email)
                ->setSubject($subject)
                ->setTextBody($body)
                ->send();
        } catch (\Exception $e) {
            Yii::error("Failed to send password reset email: " . $e->getMessage(), 'admin_auth');
            return false;
        }
    }

    private function logLoginAttempt()
    {
        Yii::info("Login attempt from IP: " . Yii::$app->request->userIP, 'admin_auth');
    }

    private function logFailedLogin($email, $reason)
    {
        Yii::warning("Failed login attempt for email: {$email}, reason: {$reason}, IP: " . Yii::$app->request->userIP, 'admin_auth');
    }

    private function logSuccessfulLogin($user)
    {
        Yii::info("Successful login for user: {$user->email}, IP: " . Yii::$app->request->userIP, 'admin_auth');
    }

    private function logPasswordResetAttempt()
    {
        Yii::info("Password reset attempt from IP: " . Yii::$app->request->userIP, 'admin_auth');
    }

    private function logFailedPasswordReset($email, $reason)
    {
        Yii::warning("Failed password reset attempt for email: {$email}, reason: {$reason}, IP: " . Yii::$app->request->userIP, 'admin_auth');
    }

    private function logPasswordReset($email, $password)
    {
        Yii::info("Password reset for email: {$email}, IP: " . Yii::$app->request->userIP, 'admin_auth');
    }

    private function logSuccessfulPasswordReset($email)
    {
        Yii::info("Successful password reset for email: {$email}, IP: " . Yii::$app->request->userIP, 'admin_auth');
    }
}

