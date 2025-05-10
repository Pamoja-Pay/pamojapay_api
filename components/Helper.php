<?php

namespace app\components;

use app\models\ActivityLogs;
use app\models\AdminActivityLogs;
use app\models\Groups;
use app\models\User;
use app\models\GroupMembers;    
use app\models\Contributions;
use app\models\ContributionSchedule;
use app\models\Notifications;
use app\models\NotificationRecipient;
use DateTime;
use DateInterval;
use Exception;
use Yii;
use yii\base\Component;
 
class Helper extends Component
{

    public $controller = 'Helper Component';

    # Record user Activity log
    public function logActivity($data, $type){
        if ($type == 'Client'){
            $model  = new ActivityLogs();
        }
        else if ($type == 'Admin'){
            $model = new AdminActivityLogs();
        }
        
        $model->user_id = $data['user_id'];
        $model->action = $data['request_url'];
        $model->created_at = date('Y-m-d H:i:s');
        $model->request_url = $data['request_url'];
        $model->remote_ip = $data['remoteIP'];
        $model->post_params = $data['postParams'];
        $model->request_params = $data['requestParams'];
        if ($model->save()){
            return true;
        }
        else {
            return false;
        }

    }

    #validate mobile number
    public function validateMobile($mob_num)
    {
        $mob_num = str_replace(' ','',$mob_num);
        if (substr($mob_num, 0,1) == '0' && strlen($mob_num) == 10)
        {
            $cus_mob = '255'.substr($mob_num,1);
        }
        else if ((substr($mob_num, 0,3) == '255' && strlen($mob_num) == 12))
        {
            $cus_mob = $mob_num;
        } 
        else {
            $response = [
                'success' => false,
                'message' => 'Invalid Mobile Number',
            ];
            return $response;
        }
        return $response = [
            'success' => true,
            'cus_mob' => $cus_mob
        ];
    }

    #generate OTP
    public function OTPGeneration($user_name, $email, $process){
        $OTP = rand(10000, 99999);
        $sender_email = Yii::$app->params['sender_email'];
        $message = "Dear " . $user_name . ", <br>" .
            "You have successfully registered into Minick Store App.<br>" . 
            "To complete registration, open the app and enter this OTP: <br>" .
            "<b>" . $OTP . "</b> <br>" .
        "Thank you.";

        //send email
        error_reporting(E_ALL ^ E_DEPRECATED);
        $mail = Yii::$app->mailer->compose()
            ->setTo($email)
            ->setFrom($sender_email)
            ->setSubject('Verification OTP')
            ->setHtmlBody($message);
        if($mail->send()){
            $response = [
                "success" => true,
                "response_code" => 00,
                "OTP" => $OTP,
                "message" => "OTP sent successful",
            ];
        }
        else{
            Yii::error($mail);
            $response = [
                "success" => false,
                "resoponse" => 700,
                "OTP" => $OTP,
                "message" => "Failed to send Verification Email"
            ];
        }

        return $response;
    }
    public function customErrors($process, $errorMessage, $controller)
    {
        $error = \PHP_EOL . "____________________________ Error Occurred _____________________________" . \PHP_EOL;
        $error .= "During: " . strtoupper($process) . \PHP_EOL;
        $error .= "Error Message: " . $errorMessage . \PHP_EOL;
        $error .= "Occured in: " . $controller . \PHP_EOL;
        $error .= "________________________________________________________________________" . \PHP_EOL;
        Yii::info($error, 'custom_error');
        return true;
    }

    public static function generateContributionSchedule($groupId)
    {
        try{
            $group = Groups::findOne($groupId);
            if (!$group || !in_array($group->type, ['Kikoba', 'Mchezo'])) {
                return false;
            }
    
            $contribution = Contributions::find()->where(['group_id' => $groupId])->one();
            if (!$contribution) return false;
    
            $members = GroupMembers::find()->where(['group_id' => $groupId])->all();
            if (empty($members)) return false;
    
            $groupStartDate = new DateTime($contribution->start_date);
            $endDate = (clone $groupStartDate)->add(new DateInterval('P3M')); // Generate for 3 months
    
            $interval = match (strtolower($contribution->frequency)) {
                'weekly' => new DateInterval('P7D'),
                'monthly' => new DateInterval('P1M'),
                'daily' => new DateInterval('P1D'),
                default => null
            };
    
            if (!$interval) return false;
    
            $scheduleRounds = []; // keep track of round number for each date
    
            // Generate dates and assign round numbers
            $round = 1;
            for ($date = clone $groupStartDate; $date <= $endDate; $date->add($interval), $round++) {
                $scheduleRounds[$date->format('Y-m-d')] = $round;
            }
    
            foreach ($members as $member) {
                $joinedAt = new DateTime($member->joined_at ?? $groupStartDate->format('Y-m-d'));
    
                foreach ($scheduleRounds as $dueDate => $roundNumber) {
                    $due = new DateTime($dueDate);
                    if ($due >= $joinedAt) {
                        // Check if schedule already exists for this member, group, and due_date
                        $exists = (new \yii\db\Query())
                            ->from('contribution_schedule')
                            ->where([
                                'group_id' => $groupId,
                                'user_id' => $member->user_id,
                                'due_date' => $dueDate,
                            ])
                            ->exists();
    
                        if (!$exists) {
                            Yii::$app->db->createCommand()->insert('contribution_schedule', [
                                'group_id' => $groupId,
                                'user_id' => $member->user_id,
                                'due_date' => $dueDate,
                                'amount' => $contribution->amount,
                                'round_number' => $roundNumber,
                                'is_paid' => 0,
                                'paid_at' => null,
                            ])->execute();
                        }
    
                        //TODO:: check if the schedule created is about to run out
                    }
                }
            }
    
            return true;
        }
        catch (Exception $ex){
            Yii::error("Failed to genereate contribution schedule due to: ". json_encode($ex->getMessage()));
            return false;
        }
        
    }

    public static function nextContributionsPerGroup($user_id)
    {
        $today = date('Y-m-d');

        // Get all group IDs the user belongs to
        $groups = GroupMembers::find()
            ->select(['group_id'])
            ->where(['user_id' => $user_id])
            ->column();

        $result = [];

        foreach ($groups as $groupId) {
            $nextSchedule = ContributionSchedule::find()
                ->where([
                    'user_id' => $user_id,
                    'group_id' => $groupId,
                    'is_paid' => false,
                ])
                ->andWhere(['>=', 'due_date', $today])
                ->orderBy(['due_date' => SORT_ASC])
                ->limit(1)
                ->one();

            if ($nextSchedule) {
                $group = Groups::findOne($groupId);

                $result[] = [
                    'group_id' => $groupId,
                    'group_name' => $group->name ?? null,
                    'due_date' => $nextSchedule->due_date,
                    'amount' => $nextSchedule->amount,
                    'round_number' => $nextSchedule->round_number,
                ];
            }
        }

        return [
            'status' => 'success',
            'data' => $result,
        ];
    }

    public function sendMail($subject, $body, $from, $to){
        try {
            error_reporting(E_ALL ^ E_DEPRECATED);
            Yii::$app->mailer->compose()
                ->setTo($to)
                ->setFrom($from)
                ->setSubject($subject)
                ->setHtmlBody($body)
            ->send();
            return true;
        } catch (\Throwable $e) {
            Yii::error("Email failed to be sent due to ".$e->getMessage());
            return false;
        }
    }

    public static function generateNotification($params){

        $process = $params['process'];
        if ($params['lang'] == 'sw')/*swahili notificatios*/{
            if ($params['group_type'] == 'Event'){
                $group_type = 'Tukio';
            }
            else{
                $group_type = $params['group_type'];
            }
            if ($process == 'registration'){
                $message = $params['user_name']. ' umefanikiwa kutengeneza akaunti yako kikamilifu. Tafadhali ingia kwenye app kuendelea.';   
            }
            else if ($process == 'forgotPassword'){
                $message = "Habari, ".$params['user_name'] . ' tumia OTP : '. $params['OTP']. 'ilikukamilisha zoezi lakubadili neno siri';
            }
            else if ($process == 'createGroup'){
                $message = "Habari ".$params['user_name'] . ', '. $group_type . ' lenye jina ' . $params['group_name']. ' kimetengenezwa kikamilifu.';
            }
            else if ($process == 'addMember'){
                $message = $params['user_name']. ' amekuwa mwanachama wa '. $group_type . ' lenye jina '. $params['group_name'] .'kama ' . $params['role']. 'tarehe '. date('d/m/Y');
            }
            else if ($process == 'removeMember'){
                $message = $params['user_name']. ' ametolewa kwenye '. $group_type . ' lenye jina '. $params['group_name'] .'tarehe '. date('d/m/Y');
            }
            else if ($process == 'updateMember'){
                $message = $params['user_name']. ' amewekwa kuwa '.$params['role']. ' kwenye'. $group_type . ' lenye jina '. $params['group_name'] .'tarehe '. date('d/m/Y');
            }
            else if ($process == 'newPledge'){
                $message = $params['user_name']. ' Ameahidi Sh '.$params['amount']. ' kwenye'. $group_type . ' lenye jina '. $params['group_name'] .'tarehe '. date('d/m/Y');
            }
            else if ($process == 'newPayment'){
                $message = $params['user_name']. ' Ameweka malipo ya Sh '.$params['amount']. ' kwenye'. $group_type . ' lenye jina '. $params['group_name'] .'tarehe '. date('d/m/Y');
            }
            else if ($process == 'approvedPayment'){
                $message = $params['user_name']. ' amechagia Sh '.$params['amount']. ' kwenye'. $group_type . ' lenye jina '. $params['group_name'] .'tarehe '. date('d/m/Y');
            }
            else if ($process == 'changePassword'){
                $message = $params['user_name']. ' umefanikiwa kubadili neno siri lako. Tafadhali ingia kwenye app kuendelea.';
            }
        }
        else if ($params['lang'] == 'en')/**english notifications */{

            if ($process == 'registration'){
                $message = $params['user_name']. ' you have successful created account. please login to continue.';   
            }
            else if ($process == 'forgotPassword'){
                $message = "Hello ".$params['user_name'] . ' use the OTP: ' .$params['OTP'] . ' to complete the process of reseting your password';
            }
            else if ($process == 'createGroup'){
                $message = "Hello ".$params['user_name'] . ', ' . $params['group_type'] . ' with a name ' . $params['group_name']. ' has been created successfully.';
            }
            else if ($process == 'addMember'){
                $message = $params['user_name']. ' is now a Member of '. $params['group_type'] . ' with a name '. $params['group_name'] .' as ' .$params['role'] . 'Today '. date('d/m/Y');
            }
            else if ($process == 'removeMember'){
                $message = $params['user_name']. ' has been removed from '. $params['group_type'] . ' with a name '. $params['group_name'] . 'Today '. date('d/m/Y');
            }
            else if ($process == 'updateMember'){
                $message = $params['user_name']. ' has been set a role '. $params['role']. 'in'. $params['group_type'] . ' with a name '. $params['group_name'] . 'Today '. date('d/m/Y');
            }
            else if ($process == 'newPledge'){
                $message = $params['user_name']. ' has pledged Tshs '. $params['amount']. 'in'. $params['group_type'] . ' with a name '. $params['group_name'] . 'Today '. date('d/m/Y');
            }
            else if ($process == 'newPayment'){
                $message = $params['user_name']. ' has record new payment of '. $params['amount']. 'in'. $params['group_type'] . ' with a name '. $params['group_name'] . 'Today '. date('d/m/Y');
            }
            else if ($process == 'approvedPayment'){
                $message = $params['user_name']. ' has contributed Tshs '. $params['amount']. 'in'. $params['group_type'] . ' with a name '. $params['group_name'] . 'Today '. date('d/m/Y');
            }
            else if ($process == 'changePassword'){
                $message = $params['user_name']. ' you have change your password successful please login in the app to continue';
            }
        }

        $model = new Notifications();
        $model->user_id = $params['user_id'];
        if (!empty($params['group_id'])){
            $model->group_id = $params['group_id'];
        }
        $model->type = $params['type'];
        $model->title = strtoupper($process);
        $model->message = $message;
        $model->status = 0; //pending to be sent
        $model->created_at = date('Y-m-d H:i:s');
        if ($model->save()){

            //record recepients
            //find recepients
            if ($process == "registration" || $process == 'forgotPassword' || $process == 'createGroup' || $process == 'changePassword'){
                $userIds = User::find()->select('id')->where(['id' => $params['user_id']])->column();
            }
            else if ($process == 'addMember' || $process == 'removeMember' || $process == 'updateMember' || $process == 'newPledge' || $process == 'approvedPayment'){
                $userIds = GroupMembers::find()->select('user_id')->where(['group_id' => $params['group_id'], 'is_active' => true])->column();
            }
            else if ($process == 'newPayment'){
                $userIds = GroupMembers::find()->select('user_id')->where(['group_id' => $params['group_id'], 'is_active' => true, 'role' => 'treasurer'])->column();
            }

            foreach ($userIds as $userId) {
                $recipient = new NotificationRecipient();
                $recipient->notification_id = $model->id;
                $recipient->user_id = $userId;
                $recipient->created_at = date('Y-m-d H:i:s');
                $recipient->status = 0; //pending to be sent
                $recipient->save(false);
            }
            

            return true;
        }
        else{
            Yii::error("Failed to Record notification due to: ". json_encode($model->errors));
            return false;
        }
    }

    # LOG PARAMS
    public function postRequestParams($process, $data) {
        $error = strtoupper("************************************". $process ."****************************************") . PHP_EOL;
        $error .= json_encode($data) .  PHP_EOL;
        $error .= "________________________________________________________________________" . PHP_EOL;
        Yii::info($error, 'request');
        return true;
    }

    public function generatePass($email, $password){
        $details = "************************************************************************" . PHP_EOL;
        $details .= "Email: ". $email . PHP_EOL;
        $details .= "Password: ". $password . PHP_EOL;
        $details .= "________________________________________________________________________" . PHP_EOL;
        Yii::info($details, 'credentials');

        return true;
    }

}

