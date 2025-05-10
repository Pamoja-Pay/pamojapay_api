<?php

namespace app\controllers;

use Yii;
use yii\rest\Controller;
use yii\filters\RateLimiter;
use yii\filters\auth\HttpBearerAuth;
use yii\filters\AccessControl;
use yii\web\Response;
use yii\web\HttpException;
use yii\web\UploadedFile;
use yii\filters\VerbFilter;

class BaseController extends Controller
{
    public function behaviors()
    {
        $behaviors = parent::behaviors();
        
        // Add rate limiter
        $behaviors['rateLimiter'] = [
            'class' => RateLimiter::class,
            'enableRateLimitHeaders' => true,
            'rateLimit' => 60, // 60 requests
            'timePeriod' => 60, // per 60 seconds
        ];

        // Add verb filter
        $behaviors['verbFilter'] = [
            'class' => VerbFilter::class,
            'actions' => [
                'index' => ['GET'],
                'view' => ['GET'],
                'create' => ['POST'],
                'update' => ['PUT', 'PATCH'],
                'delete' => ['DELETE'],
            ],
        ];

        // Add authentication
        $behaviors['authenticator'] = [
            'class' => HttpBearerAuth::class,
            'except' => ['options'],
        ];

        // Add CORS
        $behaviors['corsFilter'] = [
            'class' => \yii\filters\Cors::class,
            'cors' => [
                'Origin' => ['*'],
                'Access-Control-Request-Method' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
                'Access-Control-Request-Headers' => ['*'],
                'Access-Control-Allow-Credentials' => true,
            ],
        ];

        return $behaviors;
    }

    public function actions()
    {
        return [
            'options' => [
                'class' => 'yii\rest\OptionsAction',
            ],
        ];
    }

    /**
     * Validates and sanitizes input data
     * @param mixed $data The data to validate
     * @param string $type The type of validation to perform
     * @return mixed The sanitized data
     * @throws HttpException if validation fails
     */
    protected function validateInput($data, $type)
    {
        switch ($type) {
            case 'email':
                if (!filter_var($data, FILTER_VALIDATE_EMAIL)) {
                    throw new HttpException(400, 'Invalid email format');
                }
                return filter_var($data, FILTER_SANITIZE_EMAIL);
            
            case 'int':
                if (!is_numeric($data)) {
                    throw new HttpException(400, 'Invalid numeric value');
                }
                return (int)$data;
            
            case 'float':
                if (!is_numeric($data)) {
                    throw new HttpException(400, 'Invalid numeric value');
                }
                return (float)$data;
            
            case 'string':
                return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
            
            case 'date':
                if (!strtotime($data)) {
                    throw new HttpException(400, 'Invalid date format');
                }
                return date('Y-m-d H:i:s', strtotime($data));
            
            default:
                return $data;
        }
    }

    /**
     * Validates file upload
     * @param UploadedFile $file The uploaded file
     * @param array $allowedTypes Allowed file types
     * @param int $maxSize Maximum file size in bytes
     * @return bool
     * @throws HttpException if validation fails
     */
    protected function validateFileUpload($file, $allowedTypes = [], $maxSize = 5242880)
    {
        if (!$file) {
            throw new HttpException(400, 'No file uploaded');
        }

        if ($file->size > $maxSize) {
            throw new HttpException(400, 'File size exceeds limit');
        }

        if (!empty($allowedTypes) && !in_array($file->type, $allowedTypes)) {
            throw new HttpException(400, 'Invalid file type');
        }

        return true;
    }

    /**
     * Logs sensitive operations
     * @param string $action The action being performed
     * @param array $data The data being processed
     * @param string $type The type of log (admin, user, etc.)
     */
    protected function logSensitiveOperation($action, $data, $type = 'admin')
    {
        $logData = [
            'action' => $action,
            'data' => $data,
            'user_id' => Yii::$app->user->id,
            'ip' => Yii::$app->request->userIP,
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        Yii::info(json_encode($logData), $type . '_sensitive_operation');
    }
} 