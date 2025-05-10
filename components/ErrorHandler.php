<?php

namespace app\components;

use Yii;
use yii\web\Response;
use yii\web\HttpException;

class ErrorHandler extends \yii\base\ErrorHandler
{
    /**
     * Renders the exception.
     * @param \Exception $exception the exception to be rendered.
     */
    protected function renderException($exception)
    {
        $response = Yii::$app->has('response') ? Yii::$app->response : new Response();
        $response->format = Response::FORMAT_JSON;

        if ($exception instanceof HttpException) {
            $statusCode = $exception->statusCode;
        } else {
            $statusCode = 500; // General internal server error
        }


        // // Log concise error details
       

        // Yii::error(
        //     "*********** Error Occurred ***********\n" .
        //     "Error message -> " . $exception->getMessage() . "\n" .
        //     "In file " . $exception->getFile() . " at line " . $exception->getLine(),
        //     'application'
        // );

        switch ($statusCode) {
            case 255:
                $response->setStatusCode(255, "System defined error");
                $response->data = [
                    'success' => false,
                    'message' => $exception->getMessage(),
                    'code'    => 'err' . $exception->getCode(),
                ];
                break;

            case 403:
                $response->setStatusCode(403, "Authentication required");
                $response->data = [
                    'success' => false,
                    'message' => $exception->getMessage(),
                    'code'    => 'err52',
                ];
                break;

            case 404:
                $response->setStatusCode(404, "Resource not found");
                $response->data = [
                    'success' => false,
                    'message' => $exception->getMessage(),
                    'code'    => 'err51',
                ];
                break;

            default:
                $response->setStatusCode($statusCode, "General failure / Internal server error");
                $response->data = [
                    'success' => false,
                    'message' => $exception->getMessage(),
                    'code'    => 'err50',
                ];
                break;
        }

        // Send the response
        $response->send();
        return;
    }
}
