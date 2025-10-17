<?php

namespace app\components;

use app\models\ActivityLogs;
use app\models\AdminActivityLogs;
use app\models\Groups;
use app\models\PushPaymentRequest;
use app\models\User;
use app\models\GroupMembers;    
use app\models\Contributions;
use app\models\ContributionSchedule;
use app\models\Notifications;
use app\models\NotificationRecipient;
use app\models\Packages;
use app\models\VikobaSettings;
use app\models\MchezoSettings;
use app\models\UjamaaSettings;
use app\models\EventSettings;
use app\models\PaymentGateway;
use app\models\Payments;
use yii\web\HttpException;
use DateTime;
use DateInterval;
use Exception;
use Yii;
use yii\base\Component;
use yii\httpclient\Client;

 
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

    # GET GROUP SETTING
    public function getGroupSetting($group_id, $type){
        if ($type == "Kikoba"){
            $setting = VikobaSettings::find()->where(['group_id' => $group_id])->one();
        }
        else if ($type == "Mchezo"){
            $setting = MchezoSettings::find()->where(['group_id' => $group_id])->one();
        }
        else if ($type == "Ujamaa"){
            $setting = UjamaaSettings::find()->where(['group_id' => $group_id])->one();
        }
        else if ($type == "Event"){
            $setting = EventSettings::find()->where(['group_id' => $group_id])->one();
        }
        else{
            Yii::error("Group of type: ". $type . " and ID: ". $group_id . "Not Found");
            $response = [
                'success' => false,
                'message' => 'Invalid Group type or ID'
            ];

            return $response;
        }

        $response = [
            'success' => true,
            'data' => $setting
        ];
        return $response;

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

    public  function validateChannel($mobile, $channel){
        $channel_code = substr($mobile, 3, 2);
        if ($channel_code == '74' || $channel_code == '75' || $channel_code == '76') {
            $mtandao = "Mpesa";
        } else if ($channel_code == '68' || $channel_code == '69' || $channel_code == '78') {
            $mtandao = "AirtelMoney";
        } else if ($channel_code == '65' || $channel_code == '67' || $channel_code == '71' || $channel_code == '77') {
            $mtandao = "TigoPesa";
        } else if ($channel_code == '62' || $channel_code == '61') {
            $mtandao = "HalotelPesa";
        } else if ($channel_code == '73') {
            $mtandao = "TPesa";
        } else {
            Yii::error("Invalid Mobile Network. Number entered: " .$mobile);
            return ['success' => false, 'message' => 'Invalid Mobile Network'];
        }

        if ($mtandao != $channel) {
            Yii::error("Invalid Channel. Number entered: ".$mobile);
            return [
                'success' => false,
                'message' => 'Entered Number is not valid for '.$channel. ". Number entered: ".$mobile,
            ];
        }
        else {
            return [
                'success' => true,
                'message' => 'Valid Channel'
            ];
        }
    }

    public function getPackagebyGroupID($group_id){
        $group = Groups::find()->where(['id' => $group_id])->one();
        if (empty($group)) return ['success' => false, 'message' => 'Group with ID ' .$group_id. ' does not exist'];

        $owner_id = $group['created_by'];

        $owner_details = User::find()->where(['id' => $owner_id])->one();
        if (empty($owner_details)) return ['success' => false, 'message' => 'User not found'];

        $package_details = Packages::find()->where(['id' => $owner_details['package_id']])->one();
        if (empty($package_details)) return ['success' => false,'message' => 'Package not found'];

        return [
            'success' => true,
            'message' => 'Package found',
            'package_details' => $package_details
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
                                'paid_amount' => 0,
                                'remain_amount' => $contribution->amount,
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

    public static function generateContributionSchedule2($groupId)
    {
        try{
            $group = Groups::findOne($groupId);
            if (!$group || !in_array($group->type, ['Kikoba', 'Mchezo'])) {
                return false;
            }
    
            $contribution = Contributions::find()->where(['group_id' => $groupId])->one();
            if (!$contribution) return false;

            $latestDue = ContributionSchedule::find()
                ->where(['group_id' => $groupId])
                ->orderBy(['due_date' => SORT_DESC])
                ->limit(1)
                ->one();
    
            $members = GroupMembers::find()->where(['group_id' => $groupId])->all();
            if (empty($members)) return false;
    
            //$groupStartDate = new DateTime($contribution->start_date);
            //get todays date
            $today = new DateTime();

            $endDate = (clone $today)->add(new DateInterval('P3M')); // Generate for 3 months
            
            $interval = match (strtolower($contribution->frequency)) {
                'weekly' => new DateInterval('P7D'),
                'monthly' => new DateInterval('P1M'),
                'daily' => new DateInterval('P1D'),
                default => null
            };
    
            if (!$interval) return false;
    
            $scheduleRounds = []; // keep track of round number for each date
    
            // Generate dates and assign round numbers
            $round = $latestDue->round_number +1;

            for ($date = clone $today; $date <= $endDate; $date->add($interval), $round++) {
                $scheduleRounds[$date->format('Y-m-d')] = $round;
            }
    
            foreach ($members as $member) {
                $joinedAt = new DateTime($member->joined_at ?? $today->format('Y-m-d'));
    
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
                                'paid_amount' => 0,
                                'remain_amount' => $contribution->amount,
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
        //$overallNext = null;

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
                //     'group_id' => $groupId,
                //     'group_name' => $group->name ?? null,
                //     'due_date' => $nextSchedule->due_date,
                //     'amount' => $nextSchedule->amount,
                //     'remain_amount' => $nextSchedule->remain_amount,
                //     'round_number' => $nextSchedule->round_number,
                // ];

                // // Track the overall next upcoming schedule across all groups
                // if ($overallNext === null || strtotime($nextSchedule->due_date) < strtotime($overallNext['due_date'])) {
                //     $overallNext = [
                        'group_id' => $groupId,
                        'group_name' => $group->name ?? null,
                        'due_date' => $nextSchedule->due_date,
                        'amount' => $nextSchedule->amount,
                        'remain_amount' => $nextSchedule->remain_amount,
                        'round_number' => $nextSchedule->round_number,
                    ];
                //}
            }
        }

        return [
            'status' => 'success',
            'data' => $result,
            // The single earliest upcoming unpaid installment for the user
            //'next' => $overallNext,
        ];
    }

    public static function overdueContributionsPerGroup($user_id)
    {
        $today = date('Y-m-d');

        // Get all group IDs the user belongs to
        $groups = GroupMembers::find()
            ->select(['group_id'])
            ->where(['user_id' => $user_id])
            ->column();

        $result = [];

        foreach ($groups as $groupId) {
            $overdueSchedule = ContributionSchedule::find()
                ->where([
                    'user_id' => $user_id,
                    'group_id' => $groupId,
                    'is_paid' => false,
                ])
                ->andWhere(['<', 'due_date', $today])
                ->orderBy(['due_date' => SORT_ASC])
                ->limit(1)
                ->one();

            if ($overdueSchedule) {
                $group = Groups::findOne($groupId);

                $result[] = [
                    'group_id' => $groupId,
                    'group_name' => $group->name ?? null,
                    'due_date' => $overdueSchedule->due_date,
                    'amount' => $overdueSchedule->amount,
                    'remain_amount' => $overdueSchedule->remain_amount,
                    'round_number' => $overdueSchedule->round_number,
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
                $message = $params['user_name']. ' amekuwa mwanachama wa '. $group_type . ' lenye jina '. $params['group_name'] .' kama ' . $params['role']. ' tarehe '. date('d/m/Y');
            }
            else if ($process == 'removeMember'){
                $message = $params['user_name']. ' ametolewa kwenye '. $group_type . ' lenye jina '. $params['group_name'] .' tarehe '. date('d/m/Y');
            }
            else if ($process == 'updateMember'){
                $message = $params['user_name']. ' amewekwa kuwa '.$params['role']. ' kwenye'. $group_type . ' lenye jina '. $params['group_name'] .' tarehe '. date('d/m/Y');
            }
            else if ($process == 'newPledge'){
                $message = $params['user_name']. ' Ameahidi Sh '.$params['amount']. ' kwenye'. $group_type . ' lenye jina '. $params['group_name'] .' tarehe '. date('d/m/Y');
            }
            else if ($process == 'newPayment'){
                $message = $params['user_name']. ' Ameweka malipo ya Sh '.$params['amount']. ' kwenye'. $group_type . ' lenye jina '. $params['group_name'] .' tarehe '. date('d/m/Y');
            }
            else if ($process == 'approvedPayment'){
                $message = $params['user_name']. ' amechagia Sh '.$params['amount']. ' kwenye'. $group_type . ' lenye jina '. $params['group_name'] .' tarehe '. date('d/m/Y');
            }
            else if ($process == 'changePassword'){
                $message = $params['user_name']. ' umefanikiwa kubadili neno siri lako. Tafadhali ingia kwenye app kuendelea.';
            }
            else if ($process == 'leaveGroup'){
                $message = $params['user_name']. ' Amejitoa katika kikundi cha '. $params['group_name'];
            }
            else if ($process == 'Buy Shares'){
                $message = $params['user_name'].'Amenunua Hisa kwenye '. $params['group_type'].' chenye jina '. $params['group_name'].' tarehe '. date('d/m/Y');
            }
            else if ($process == 'Withdraw Request'){
                $message = $params['user_name']. ' Ameanzisha muamala wakutoa Tsh. '. $params['amount'] . ' kwenye '. $params['group_name']. ' kwaajili ya '. $params['reason'] . '.';
            }
            else if ($process == 'Withdraw Approval'){
                $message = $params['user_name']. ' Amepitisha maombi ya kutoa Tsh. ' . $params['amount'] . ' kwenye '. $params['group_name'].' kwaajili ya '. $params['reason']. '. Unaitajika kukamilisha mwamala';
            }
            else if ($process == 'Withdraw Rejected'){
                $message = 'Maombi ya kutoa Tsh. ' . $params['amount']. ' kwenye ' .$params['group_name']. ' Kwaajili ya ' .$params['reason']. 'Yamekataliwa.';
            }
            else if ($process == 'Withdraw Final Approval'){
                $message = 'Maombi ya kutoa Tsh. '. $params['amount'].' kwenye '.$params['group_name'].' Kwaajili ya '.$params['reason']. ' Yamekamilika na fedha imetoka.';
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
                $message = $params['user_name']. ' is now a Member of '. $params['group_type'] . ' with a name '. $params['group_name'] .' as ' .$params['role'] . ' Today '. date('d/m/Y');
            }
            else if ($process == 'removeMember'){
                $message = $params['user_name']. ' has been removed from '. $params['group_type'] . ' with a name '. $params['group_name'] . ' Today '. date('d/m/Y');
            }
            else if ($process == 'updateMember'){
                $message = $params['user_name']. ' has been set a role '. $params['role']. 'in '. $params['group_type'] . ' with a name '. $params['group_name'] . ' Today '. date('d/m/Y');
            }
            else if ($process == 'newPledge'){
                $message = $params['user_name']. ' has pledged Tshs '. $params['amount']. 'in '. $params['group_type'] . ' with a name '. $params['group_name'] . ' Today '. date('d/m/Y');
            }
            else if ($process == 'newPayment'){
                $message = $params['user_name']. ' has record new payment of '. $params['amount']. 'in '. $params['group_type'] . ' with a name '. $params['group_name'] . ' Today '. date('d/m/Y');
            }
            else if ($process == 'approvedPayment'){
                $message = $params['user_name']. ' has contributed Tshs '. $params['amount']. 'in '. $params['group_type'] . ' with a name '. $params['group_name'] . ' Today '. date('d/m/Y');
            }
            else if ($process == 'changePassword'){
                $message = $params['user_name']. ' you have change your password successful please login in the app to continue';
            }
            else if ($process == 'leaveGroup'){
                $message = $params['user_name']. ' Has left a group with a name '. $params['group_name'];
            }
            else if ($process == 'Buy Shares'){
                $message = $params['user_name'].'Has Bought Shares in '. $params['group_type'].' with a name '. $params['group_name'].' Today '. date('d/m/Y');
            }
            else if ($process == 'Withdraw Request'){
                $message = $params['user_name']. ' Has started a Withdraw request of Tsh. '. $params['amount'] . ' from '. $params['group_name']. ' for '. $params['reason'] . '.';
            }
            else if ($process == 'Withdraw Approval'){
                $message = $params['user_name']. ' Has preapprove withdraw request of Tsh. ' . $params['amount'] . ' from  '. $params['group_name'].' for '. $params['reason']. '. You are needed to complete the process';
            }
            else if ($process == 'Withdraw Rejected'){
                $message = 'Request to withdraw Tsh. ' . $params['amount']. ' from ' .$params['group_name']. ' for ' .$params['reason']. ' Has been rejected.';
            }
            else if ($process == 'Withdraw Final Approval'){
                $message = 'Request to withdraw Tsh. '. $params['amount'].' from '.$params['group_name'].' for '.$params['reason']. ' Has been Approved and money sent.';
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
            else if ($process == 'addMember' || $process == 'removeMember' || $process == 'leaveGroup' || $process == 'updateMember' || $process == 'newPledge' || $process == 'approvedPayment' || $process == 'Buy Shares' || $process == 'Withdraw Request' || $process == 'Withdraw Rejected' || $process == 'Withdraw Final Approval'){
                $userIds = GroupMembers::find()->select('user_id')->where(['group_id' => $params['group_id'], 'is_active' => true])->column();
            }
            else if ($process == 'newPayment'){
                $userIds = GroupMembers::find()->select('user_id')->where(['group_id' => $params['group_id'], 'is_active' => true, 'role' => 'treasurer'])->column();
            }
            else if ($process == 'Withdraw Approval'){
                $userIds = GroupMembers::find()->select('user_id')->where(['group_id' => $params['group_id'], 'is_active' => true, 'role' => 'chairperson'])->column();
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


    # ClickPesa Push Payment
    public function clickPesaUssdPushInitiator($payload){
        try {
            $client = new Client();
            $response = $client->createRequest()
                ->setFormat(Client::FORMAT_JSON)
                ->setMethod('POST')
                ->setUrl(Yii::$app->params['pamoja_external_api_url'])
                ->setData($payload)
            ->send();
            if ($response->isOk) {
                $content = $response->getData();
                // Yii::error("This is the raw response Received From Pamoja External API");
                // Yii::error($content);
                // Yii::error("This is exctracted Message");
                // Yii::error($content['message']);

                $response = [
                    'success' => true,
                    'code' => "00",
                    'data' =>$content
                ];
            }
            else{
                $responseData = $response->getData();
                Yii::error("Failed to get Pamoja External API response with Error: ");
                Yii::error($responseData);
                $response = [
                    "success" => false,
                    "code" => "45",
                    "message" => $responseData['message'],
                ];
            }
    
            return $response;

        } catch (\Throwable $th) {
            Yii::error("Failed to get Pamoja External API response with Error: ");
            Yii::error($th->getMessage());
            $response = [
                "success" => false,
                "code" => "55",
                "message" => $th->getMessage(),
            ];

            return $response;
        }
    }


    //                             # PAYMENT SECTION #
                        
    // public static function pushPayment($msisdn, $amount, $channel, $user_id, $group_id, $type){
    //     // This is to ensure browser does not timeout after 30 seconds
    //     ini_set('max_execution_time', 300);
    //     set_time_limit(300);

    //     //validate channel
    //     $validChannel = Yii::$app->params['support_payment_channels'];
    //     if (!in_array($channel, $validChannel)) {
    //         return ['success' => false, 'code' => 23, 'message' => 'invalid channel'];
    //     }

    //     //validate msisdn
    //     $cus_mob = Yii::$app->helper->validateMobile($msisdn);
    //     if (!$cus_mob['success']) {
    //         return ['success' => false, 'code' => 25, 'message' => $cus_mob];
    //     }
    //     $payer_msisdn = $cus_mob['cus_mob'];

    //     //validate amount
    //     if ($amount < 1000) {
    //         return ['success' => false, 'code' => 26, 'message' => 'invalid amount'];
    //     }
        
    //     $trans_ref = 'PP'. time(); 

    //     $model = new PushPaymentRequest();
    //     $model->trans_ref = $trans_ref;
    //     $model->trans_date = date('Y-m-d H:i:s');
    //     $model->msisdn = $payer_msisdn;
    //     $model->channel = $channel;
    //     $model->amount = $amount;
    //     $model->type = $type;
    //     $model->status = 'Initiating';
    //     $model->user_id = $user_id;
    //     $model->created_at = date('Y-m-d H:i:s');
    //     $model->save(false);

    //     $create_order = self::createOrderMinimal($user_id, $amount, $msisdn, $group_id, $trans_ref, $type);

    //     if (!$create_order['success']) {
    //         $updateRequest = PushPaymentRequest::find()->where(['trans_ref' => $create_order['trans_ref']])->one();
    //         $updateRequest->status = 'Failed';
    //         $updateRequest->updated_at = date('Y-m-d H:i:s');
    //         $updateRequest->save(false);

    //        return $create_order;
    //     }
        
    //     //update push payement request
    //     $updateRequest = PushPaymentRequest::find()->where(['trans_ref' => $create_order['trans_ref']])->one();
    //     $updateRequest->status = 'Success';
    //     $updateRequest->updated_at  = date('Y-m-d H:i:s');
    //     $updateRequest->mno_ref = $create_order['mno_ref'];
    //     $updateRequest->save(false);

    //     return ['success' => true, 'code' => 1,'message' => 'success'];
       
    // }


    
    // public static function createOrderMinimal($user_id, $amount, $msisdn, $group_id, $trans_ref, $type)
    // {
    //     $user = User::findOne(['id' => $user_id]);
    //     if (!empty($user)){
    //         $sender_email = Yii::$app->params['support_email'];
    //         $sender_name  = $user->name;
    //         $sender_phone = $msisdn;
    //         $currency    = "TZS";
    //         $no_of_items = "1";

    //         //Recording transaction in selcom table
    //         $model = new Payments();
    //         $model->group_id = $group_id;
    //         $model->user_id = $user_id;
    //         $model->reference = $trans_ref;
    //         $model->amount = $amount;
    //         $model->payment_date = date("Y-m-d H:i:s");
    //         $model->payment_method = 'Selcom';
    //         $model->status = 'pending';
    //         $model->payment_for = $type;
    //         if($model->save()){
    //             //getting order_id from recorded data
    //             $order_id = (string) $model->id;

    //             //getting Selcom api credentials
    //             $api_name = Yii::$app->params['api_name'];
    //             $selcom_credentials = PaymentGateway::findOne(['api_name' => $api_name]);

    //             if (!empty($selcom_credentials)){
    //                 $api_key = $selcom_credentials->api_key;
    //                 $api_secret = $selcom_credentials->api_secret; 
    //                 $base_url = $selcom_credentials->base_url;
    //                 $api_endpoint = "/checkout/create-order-minimal";
    //                 $url = $base_url.$api_endpoint;
    //                 $isPost =true;
    //                 $req = [
    //                     "vendor"=>$selcom_credentials->vendor,
    //                     "order_id"=>$order_id,
    //                     "buyer_email"=>$sender_email,
    //                     "buyer_name"=>$sender_name,
    //                     "buyer_phone"=>$sender_phone,
    //                     "amount"=>(int)$amount,
    //                     "currency"=>$currency,
    //                     "no_of_items"=>$no_of_items
    //                 ];
    //                 $authorization = base64_encode($api_key);
    //                 $timestamp = date('c'); //2019-02-26T09:30:46+03:00 
    //                 $signed_fields  = implode(',', array_keys($req));
    //                 $digest = self::computeSignature($req, $signed_fields, $timestamp, $api_secret);
    //                 if (!empty($digest)){
    //                     $response = self::sendJSONPost($url, $isPost, json_encode($req), $authorization, $digest, $signed_fields, $timestamp);
    //                     if(!empty($response)){
    //                         $title = "Received Response from Selcom API";
    //                         $message = "Payment with reference :".$trans_ref;   
    //                         $data = json_encode($response);
    //                         self::paymentLogs($title, $message, $data);
    //                         //$trans_id = $order_id;
    //                         if($response['resultcode']== "000") {
    //                             $title = "Successfully Reseponse from Selcom API";
    //                             $message = "Payment with reference :".$trans_ref;   
    //                             $data = json_encode($response);
    //                             self::paymentLogs($title, $message, $data);

    //                             // update payment table
    //                             $updatePayment = Payments::findOne(['reference' => $trans_ref]);
    //                             $updatePayment->status = 'verified';
    //                             $updatePayment->save(false);

    //                             $msisdn = $sender_phone;
    //                             $result = [
    //                                 "success" => true,
    //                                 "result_code" => "000",
    //                                 "trans_id" => $model->id,
    //                                 "trans_ref" => $trans_ref,
    //                                 "order_id" => $order_id,
    //                                 "msisdn" => $msisdn,
    //                                 "mno_ref" => $response['mno_ref'],
    //                                 "message" => "Transaction successful",
    //                             ];
    //                             return $result;
    //                         } else {
    //                             // update payment table
    //                             $updatePayment = Payments::findOne(['reference' => $trans_ref]);
    //                             $updatePayment->status = 'rejected';
    //                             $updatePayment->save(false);

    //                             $title = "Failed to create order";
    //                             $message = "Payment with reference :".$trans_ref;
    //                             $data = json_encode($response);
    //                             self::paymentLogs($title, $message, $data);

    //                             $result = [
    //                                 "success"=> false,
    //                                 "result_code"=> 403,
    //                                 "trans_id" => $model->id,
    //                                 "trans_ref" => $trans_ref,
    //                                 "order_id" => $order_id,
    //                                 "msisdn" => $msisdn,
    //                                 "message" => "Transaction failed with result Code: ".$response['resultcode'],
    //                             ];
    //                             return $result; 
    //                         }
    //                     } else {
    //                         $title = "Failed to create order";
    //                         $message = "Payment with reference :".$trans_ref;
    //                         $data = json_encode($response);
    //                         self::paymentLogs($title, $message, $data);
    //                         throw new HttpException(450, 'No response come from sending push request', 14);
    //                     }
    //                 } else {
    //                     $title = "Failed to create order";
    //                     $message = "Payment with reference :".$trans_ref;
    //                     $data = json_encode($digest);
    //                     self::paymentLogs($title, $message, $data);
    //                     throw new HttpException(450, 'No digest come from computeSignature', 15);  
    //                 } 
    //             } else {
    //                 $title = "Payment Gateway not found in database";
    //                 $message = "Payment with name :".$api_name;
    //                 $data = "trans ref: ".$trans_ref;
    //                 self::paymentLogs($title, $message, $data);
    //                 throw new HttpException(450, 'External Api credentials not found in database', 16); 
    //             }    
    //         } else{
    //             $title = "failed to save the Payments Record";
    //             $message = "Payment with reference :" .$trans_ref;
    //             $data = json_encode($model->errors);
    //             self::paymentLogs($title, $message, $data);
    //             throw new HttpException(450, 'Failed to save the transaction record', 18);
    //         } 
    //     } else{
    //         $title = "User not found";
    //         $message = "User with ID: " .$user_id;
    //         $data = "trans ref: " .$trans_ref;
    //         self::paymentLogs($title, $message, $data);
    //         throw new HttpException(450, 'Failed to create order client not found', 17);
    //     } 
    // }

    // public static function computeSignature($parameters, $signed_fields, $request_timestamp, $api_secret){
    //     try {
    //         $fields_order = explode(',', $signed_fields);
    //         $sign_data = "timestamp=$request_timestamp";
    //         foreach ($fields_order as $key) {
    //         $sign_data .= "&$key=".$parameters[$key];
    //         }
    //         //HS256 Signature Method
    //         return base64_encode(hash_hmac('sha256', $sign_data, $api_secret, true));
    //     } catch (\Throwable $th) {
    //         $title = "Error computing Selcom signature";
    //         $message = $th->getMessage();
    //         $data = 'Params' .json_encode($parameters) . ' Signed Fields:'.$signed_fields.' Timestamp:'.$request_timestamp.'Api Secret:'.$api_secret; 
    //         self::paymentLogs($title, $message, $data);
    //         return null;
    //     }
    // }

    // public static function sendJSONPost($url, $isPost, $json, $authorization, $digest, $signed_fields, $timestamp) {
    //     try {
    //         $headers = [
    //             "Content-type: application/json;",
    //             //charset=\"utf-8\"", 
    //             "Accept: application/json", 
    //             "Cache-Control: no-cache",
    //             "Authorization: SELCOM $authorization",
    //             "Digest-Method: HS256",
    //             "Digest: $digest",
    //             "Timestamp: $timestamp",
    //             "Signed-Fields: $signed_fields",
    //         ];
    //         $ch = curl_init();
    //         curl_setopt($ch, CURLOPT_URL, $url);
    //         if($isPost){
    //             curl_setopt($ch, CURLOPT_POST, 1);
    //             curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    //         }
    //         curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    //         curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    //         curl_setopt($ch,CURLOPT_TIMEOUT,90);
    //         $result = curl_exec($ch);
    //         curl_close($ch);
    //         $resp = json_decode($result, true);

    //         Yii::error($json);
    //         Yii::error("The selcom Response");
    //         Yii::error($result);
    //         return $resp;
    //     } 
    //     catch (\Throwable $th) {
    //         $title = "Error sending Selcom JSON Post";
    //         $message = $th->getMessage();
    //         $data = 'Url'.json_encode($url).' isPost:'.$isPost.' json:'.$json.' Authorization:'.$authorization;
    //         self::paymentLogs($title, $message, $data);
    //         Yii::error("Error On sending http request to selcom api");
    //         Yii::error($th->getMessage());
    //         return false;
    //     }
        
    // }


    public static function paymentLogs($title, $message, $data){
        $error = \PHP_EOL . "____________________________ Error Occurred _____________________________" . \PHP_EOL;
        $error .= strtoupper($title) . \PHP_EOL;
        $error .= "Error Message: " . $message . \PHP_EOL;
        $error .= "data captured is: " . json_encode($data) . \PHP_EOL;
        $error .= "________________________________________________________________________" . \PHP_EOL;
        Yii::info($error, 'payments');
        return true;
    }

}

