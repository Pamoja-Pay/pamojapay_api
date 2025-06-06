<?php

namespace app\controllers;

use Yii;
use app\models\User;
use app\models\Payments;
use app\models\Packages;
use app\models\GroupMembers;
use app\models\Notifications;
use app\models\NotificationRecipient;
use app\models\ForgetPasswordVerification;
use app\components\Helper;
use yii\rest\Controller;
use yii\web\HttpException;
use \Firebase\JWT\JWT;
use app\models\Subscription;

class AuthController extends Controller
{
    public $controller = 'Auth Controller';
    # TEST
    public function actionTest(){
        return "IT WORKS";
    }

    # REGISTER
    public function actionRegister(){
        $process = "Registration";
        $errorMessages = '';

        $name = Yii::$app->request->post('name');
        if (empty($name)) throw new  HttpException(255, 'Name is required', 01);
        $email = Yii::$app->request->post('email');
        //if (empty($email)) throw new HttpException(255, 'Email is required', 01);
        $password = Yii::$app->request->post('password');
        if (empty($password)) throw new HttpException(255, 'Password is required', 01);
        // $pin = Yii::$app->request->post('pin');
        // if (empty($pin)) throw new HttpException(255, 'Pin is required', 01);
        $language = Yii::$app->request->post('language');
        if (empty($language)) {
            $language = 'en'; //default
        }
        $mobile = Yii::$app->request->post('phoneNumber');
        if (empty($mobile)) throw new HttpException(255, 'Phonw number is Require', 01);



        //validate mobile 
        $cus_mob = Yii::$app->helper->validateMobile($mobile);

        if (!$cus_mob['success']) throw new HttpException(255, $cus_mob['message'], 02);

        //check if user with this mobile already exist
        $check1 = User::findOne(['phone_number' => $cus_mob['cus_mob']]);
        if (!empty($check1)) throw new HttpException(255, 'Phone number already exist', 03);
        //check if user with this email already exist
        $check2 = User::findOne(['email' => $email]);
        if(!empty($check2)) throw new HttpException(255, "Email already exist", 03);

        //password encryption
        $hash_pass = Yii::$app->getSecurity()->generatePasswordHash($password);

        $model = new User();
        $model->name = strtoupper($name);
        if (!empty($email)) $model->email = $email;
        $model->phone_number = $cus_mob['cus_mob'];
        //$model->pin_code = $pin;
        $model->language = $language;
        $model->password_hash = $hash_pass;
        $model->status = 'active'; //registered successful
        $model->created_at = date("Y-m-d H:i:s");
        $model->package_id = 1; //default package
        $model->profile_picture = 'default.jpg';
        $model->role = 'Client';
        if (!$model->save()){
            foreach ($model->errors as $errors) {
                $errorMessages .= implode(', ', $errors) . ', ';
            }
            $errorMessages = rtrim($errorMessages, ', ');
            
            //log custom error
            Yii::$app->helper->customErrors($process, $errorMessages, $this->controller);

            throw new HttpException(255, "Failed To Create User Account", 04);
        }
        //record subscription
        $duration = 30; //default duration 1 month
        $subscription = new Subscription();
        $subscription->user_id = $model->id;
        $subscription->package_id = $model->package_id;
        $subscription->duration =   $duration;
        $subscription->activated_at = date("Y-m-d H:i:s");
        $subscription->expire_at = date("Y-m-d H:i:s", strtotime("+" . $duration . " days"));
        $subscription->created_at = date("Y-m-d H:i:s");
        $subscription->updated_at = date("Y-m-d H:i:s");
        $subscription->save(false);

        //genereate notification   
        $package = Packages::find()->where(['id' => $model->package_id])->one();
        if (empty($package)) throw new HttpException(255, 'Package not found', 5);
        $params = [
            'user_id' => $model->id,
            'user_name' => $model->name,
            'lang' => $model->language,
            'type' => $package->notification_support,
            'process' => 'registration',
        ];

        $record_notification = Yii::$app->helper->generateNotification($params);
        if ($record_notification){
            $response = [
                'success' => true,
                'code' => 0,
                'statusCode' => 200,
                'message' => 'Account Created Successful',
                'data' => [
                    'user_id' => $model->id
                ]
            ];
        }
        else{
            $response = [
                'success' => true,
                'code' => 0,
                'statusCode' => 200,
                'message' => 'Account Created Successful failed to generate notification',
                'data' => [
                    'user_id' => $model->id
                ]
            ];
        }
        return $response;
    }

    # LOGIN
    public function actionLogin(){
        // Yii::error("*************************************** Login ************************");
        // Yii::error(Yii::$app->request->post());
        // Yii::error("*************************************** end Login ************************");

        $mobile = Yii::$app->request->post('phoneNumber');
        if (empty($mobile)) throw new HttpException(255, 'Mobile Number is required', 01);
        
        $password = Yii::$app->request->post('password');
        $biometric = Yii::$app->request->post('biometric');

        // If biometric login is not being used, password is required
        if (empty($biometric) && empty($password)) {
            throw new HttpException(255, 'Password is required', 01);
        }

        $cus_mob = Yii::$app->helper->validateMobile($mobile);

        if (!$cus_mob['success']) throw new HttpException(255, $cus_mob['message'], 02);

        $user = User::find()->where(['phone_number' => $cus_mob['cus_mob']])->one();
        if (!empty($user)){
            // Check if it's a biometric login attempt
            if ($biometric === true) {
                // Verify if user has biometric enabled
                if (!$user->biometric_enabled) {
                    throw new HttpException(255, 'You have not enabled biometric login', 6);
                }
                // For biometric login, we skip password verification
                $isValidLogin = true;
            } else {
                // Regular password login
                $isValidLogin = Yii::$app->getSecurity()->validatePassword($password, $user['password_hash']);
            }

            if ($isValidLogin){
                if ($user['status'] != 'active') throw new HttpException(255, 'Account is not Active', 7);
                else{

                    //check if subscription is active
                    $subscription = Subscription::find()->where(['user_id' => $user['id']])->one();
                    if (empty($subscription)) throw new HttpException(255, 'Subscription not found', 5);
                    if ($subscription->expire_at < date("Y-m-d H:i:s")) throw new HttpException(255, 'Subscription has expired', 20);

                    //get user payments 
                    $user_payments = Payments::find()->where(['user_id' => $user['id'], 'status' => 'verified']) ->sum('amount');
                    //get group count
                    $group_count = GroupMembers::find()->where(['user_id' => $user['id']])->count();
                    //get overdue schedules
                    $overdue_schedules = Yii::$app->helper->overdueContributionsPerGroup($user['id']);

                    //get upcoming schedules
                    $upcoming_schedules = Yii::$app->helper->nextContributionsPerGroup($user['id']);

                    //notification count 
                    $notification_count = NotificationRecipient::find()->where(['user_id' => $user['id'], 'read_at' => null])->count();
                    $tokenId = bin2hex(random_bytes(32));
                    $issuedAt = time();
                    $notBefore = $issuedAt;
                    $expire = $notBefore + 3600;  //adding 1hour
                    $serverName = Yii::$app->params['server_name'];
                    $data = [
                        'iat' => $issuedAt,
                        'jti' => $tokenId,
                        'iss' => $serverName,
                        'nbf' => $notBefore,
                        'exp' => $expire,
                        'data' => [
                            'user_id' => $user['id'],
                            'mobile'  => $user['phone_number'],
                            'package_id' => $user['package_id'],
                            'name' => $user['name'],
                        ]
                    ];
                    $key = Yii::$app->params['jwt_key'];
        
                    $jwt = JWT::encode($data, $key, 'HS256');
                    $response = [
                        'success' => true,
                        'code' => 0,
                        'statusCode' => 200,
                        'message' => 'Login Successful',
                        'data' => [
                            'token' => $jwt,
                            'name' => $user->name,
                            'email' => $user->email,
                            'phoneNumber' => $user->phone_number,
                            'profile_picture' => $user->profile_picture,
                            'language' => $user->language, 
                            'bio' => $user->biometric_enabled,
                            'total_contributions' => $user_payments,
                            'group_count' => $group_count,
                            'upcoming_payments' => $upcoming_schedules,
                            'overdue_payments' => $overdue_schedules,
                            'notifications' => $notification_count
                        ] 
                    ];
        
                    return $response;
                }
            }
            else throw new HttpException(255, 'Wrong Username or password', 5);
        }
        else throw new HttpException(255, 'Wrong Username or password', 5);
    }

    # FORGOT PASSWORD
    public function actionForgotPassword(){
        $email = Yii::$app->request->post('email');
        if (empty($email)) throw new HttpException(255, "Email is required", 01);


        $user = User::find()->where(['email' => $email])->one();
        if (empty($user)) throw new HttpException(255, "Account not found with this email", 5);

        //check if user has pending verification OTP with status 0 or 1
        $otpRecord = ForgetPasswordVerification::find()
            ->where(['user_id' => $user->id])
            ->andWhere(['in', 'status', [0, 1]])
            ->one();
        
        if(!empty($otpRecord)) {
            $response = [
                'success' => true,
                'code' => 0,
                'message' => 'You have not used the last sent OTP'
            ];
            return $response;
        }

        // generate OTP
        $OTP = rand(10000, 99999);

        //save otp to database
        $forgetPasswordVerification = new ForgetPasswordVerification();
        $forgetPasswordVerification->user_id = $user->id;
        $forgetPasswordVerification->OTP = $OTP;
        $forgetPasswordVerification->status = 1;  // to be sent via email
        $forgetPasswordVerification->created_at = date("Y-m-d H:i:s");
        if ($forgetPasswordVerification->save()){
            //send OTP to email
            $subject = "OTP for Password Reset";
            $body = "Your OTP for password reset is: " . $OTP;
            $from = Yii::$app->params['sender_email'];
            $to = $user->email;

            $response = Yii::$app->helper->sendMail($subject, $body, $from, $to);

            if ($response){
                //genereate notification   
                $package = Packages::find()->where(['id' => $user->package_id])->one();
                if (empty($package)) throw new HttpException(255, 'Package not found', 5);
                $params = [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'lang' => $user->language,
                    'OTP' => $OTP,
                    'type' => $package->notification_support,
                    'process' => 'forgotPassword',
                ];

                $record_notification = Yii::$app->helper->generateNotification($params);
                if ($record_notification){
                    $response = [
                        'success' => true,
                        'code'=> 0,
                        'message' => 'OTP Email sent successful open you email to get verification code'
                    ];
                }
                else{
                    $response = [
                        'success' => true,
                        'code'=> 0,
                        'message' => 'OTP Email sent successful open you email to get verification code failed to generete notification'
                    ];
                }
                
            }
            else  throw new HttpException(255, 'Failed to send Email for verification', 8);

            return $response;
            //$mail($email, $subject, $body, $from);
        }
        else {
            Yii::error("*************************************** Error saving OTP ************************");
            Yii::error($forgetPasswordVerification->errors);
            Yii::error("*************************************** end Error saving OTP ************************");
            throw new HttpException(255, "Failed to save Generated OTP", 4);
        }
    }

    # VERIFY FORGOT PASSWORD REQUEST
    public function actionPFVerification(){
       
        $email = Yii::$app->request->post('email');
        if (empty($email)) throw new HttpException(255, 'Email is required', 01);
        $OTP = YII::$app->request->post('otp');
        if (empty($OTP)) throw new HttpException(255, 'OTP is required', 01);

        $user = User::find()->where(['email' => $email])->one();
        if (empty($user)) throw new HttpException(255, "Account not found with this email", 5);

        $otpRecords = ForgetPasswordVerification::find()->where(['user_id' => $user->id, 'OTP' => $OTP, 'status' => 1])->one();

        if (!empty($otpRecords)){
            $otpRecords->status = 2 ; //validated direct to change password
            $otpRecords->updated_at = date('Y-m-d H:i:s');
            if ($otpRecords->save()){
                $response = [
                    'success'=> true,
                    'code' => 0,
                    'message' => 'Valid OTP proceed'
                ];

                return $response;
            }
            else throw new HttpException(255, 'Failed to Update Valid OTP try again', 9);    
        }
        else throw new HttpException(255, 'Wrong OTP Entered', 5);
    }

    # CHANGE PASSWORD
    public function actionChangePassword(){
        $OTP = Yii::$app->request->post('otp');
        if (empty($OTP)) throw new HttpException(255, 'OTP is required', 01);
        $new_password = Yii::$app->request->post('newPassword');
        if (empty($new_password)) throw new HttpException(255, 'New Password is required', 01);
        $confirm_password = Yii::$app->request->post('confirmPassword');
        if (empty($confirm_password)) throw new HttpException(255, 'Confirm Password is required', 01);

        //check if the new pass word match with confirm password
        if ($new_password == $confirm_password){
            //validate OTP
            $otpData = ForgetPasswordVerification::find()->where(['OTP' => $OTP, 'status' => 2])->one();
            if (empty($otpData)) throw new HttpException(255, 'Invalid OTP Passed', 5);

            $user_id = $otpData->user_id;

            //get user
            $user = User::find()->where(['id' => $user_id])->one();
            if (empty($user)) throw new HttpException(255, 'User not found', 5);

            //encrypt new password
            $hash_pass = Yii::$app->getSecurity()->generatePasswordHash($new_password);

            //check if the new password is the same as the old password
            if (Yii::$app->getSecurity()->validatePassword($new_password, $user->password_hash)){
                throw new HttpException(255, 'You have used this password before', 11);
            }

            //update user password
            $user->password_hash = $hash_pass;
            if ($user->save()){
                //genereate notification   
                $package = Packages::find()->where(['id' => $user->package_id])->one();
                if (empty($package)) throw new HttpException(255, 'Package not found', 5);
                $params = [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'lang' => $user->language,
                    'type' => $package->notification_support,
                    'process' => 'changedPassword',
                ];

                $record_notification = Yii::$app->helper->generateNotification($params);
                if ($record_notification){}
                $response = [
                    'success' => true,
                    'code' => 0,
                    'message' => 'Password updated successfully'
                ];

                return $response;

            }
            else throw new HttpException(255, 'Failed to update password', 9);
        }
        else throw new HttpException(255, 'Password do not match', 10);
    }   
}
