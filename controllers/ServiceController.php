<?php

namespace app\controllers;

use app\models\EventSettings;
use Yii;
use app\models\Notifications;
use app\models\Contributions;
use app\models\GroupMembers;
use app\models\VikobaSettings;
use app\models\MchezoSettings;
use app\models\UjamaaSettings;
use app\models\Packages;
use app\models\EventStatus;
use app\components\Helper;
use app\models\PayoutSchedule;
use app\models\PayoutScheduleSetting;
use app\models\ContributionSchedule;
use app\models\OutgoingPayment;
use app\models\Payments;
use app\models\Groups;
use app\models\SharesConfig;
use app\models\NotificationRecipient;
use app\models\LeavingGroupTracking;
use app\models\Shares;
use app\models\Pledges;
use yii\rest\Controller;
use app\models\User;
use yii\web\HttpException;
use yii\web\UploadedFile;
use app\models\MemberExtraAmount;

class ServiceController extends Controller
{
    public $controller = 'Service Controller';

    public $user_id = null;
    public $mobile = null;
    public $package_id = null;
    public $login_user_name = null;

    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['contentNegotiator'] = [
            'class' => 'yii\filters\ContentNegotiator',
            'formats' => [
                'application/json' => \yii\web\Response::FORMAT_JSON,
            ],
        ];
        return $behaviors;
    }
    public function init()
    {
        $user = new User;
        $auth = $user->validateToken();
        if ($auth['status'])
        {
            $user_data = $auth['data'];
            $this->user_id = $user_data->user_id;
            $this->mobile = $user_data->mobile;
            $this->package_id = $user_data->package_id;
            $this->login_user_name = $user_data->name;

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

            $type = 'Client';

            $helper = new Helper();
            $record = $helper->logActivity($data, $type);
        }
        else
        {
            throw new HttpException(400, $auth['data']);
        }   
    }
    
    public function actionTest(){

        return $this->controller. " IT WORKS FINE";
    }

    public function actionProfile(){
        $user = User::find()->where(['id' => $this->user_id])->one();
        if (empty($user)) throw new HttpException(255, 'User not found', 01);

        $response = [
          'success' => true,
            'code' => 0,
            'user' => $user
        ];

        return $response;
    }

    # Dashboard
    public function actionDashboard(){

        //get user
        $user = User::find()->where(['id' => $this->user_id])->one();
        //get user payments 
        $user_payments = Payments::find()->where(['user_id' => $this->user_id, 'status' => 'verified']) ->sum('amount');

        //get group count
        $group_count = GroupMembers::find()->where(['user_id' => $this->user_id])->count();

        //check for pledges
        $pledges = Pledges::find()
            ->select(['pledges.*', 'groups.name as group_name'])
            ->leftJoin('groups', 'groups.id = pledges.group_id')
            ->where(['user_id' => $this->user_id])
            ->andWhere(['<>', 'status', 'Paid'])
            ->asArray()
            ->all();

        // if (!empty($pledges)){
        //     $my_pledges = $pledges;
        // }

        //get upcoming schedules
        $upcoming_schedules = Yii::$app->helper->nextContributionsPerGroup($this->user_id);

        //get overdue schedules
        $overdue_schedules = Yii::$app->helper->overdueContributionsPerGroup($this->user_id);

         //notification count 
         $notification_count = NotificationRecipient::find()->where(['user_id' => $this->user_id, 'read_at' => null])->count();

        $response = [
            'success' => true,
            'code' => 0,
            'data' => [
                'name' => $user->name,
                'total_contributions' => $user_payments,
                'group_count' => $group_count,
                'upcoming_payments' => $upcoming_schedules,
                'my_pledges' => $pledges,
                'overdue_payments' => $overdue_schedules,
                'notifications' => $notification_count
            ] 
        ];

        return $response;
    }
   
    # My groups
    public function actionMyGroups(){
        $groups = GroupMembers::find()->where(['user_id' => $this->user_id])->all();
        if (empty($groups)){
            $response = [
                'status'=> true,
                'code' => 0,
                'groups' => $groups
            ];
        }
        else{
            $group_ids = array_column($groups, 'group_id');
            $groups = Groups::find()
                ->select(['groups.*', 'users.name as creator_name'])
                ->leftJoin('users', 'users.id = groups.created_by')
                ->where(['groups.id' => $group_ids])
                ->orderBy(['groups.created_at' => SORT_DESC])
                ->asArray()
                ->all();
            $response = [
                'success' => true,
                'code' => 0,
                'groups' => $groups
            ];
        }
        
        return $response;
    }

    # Create group
    public function actionCreateGroup(){
        Yii::error("**************************** CREATE GROUP ********************************");
        Yii::error(Yii::$app->request->post());
        Yii::error("**************************************************************************");
        
        $name = Yii::$app->request->post('name');
        if (empty($name)) throw new HttpException(255, 'Name is required', 01);
        $description = Yii::$app->request->post('description');
        if (empty($description)) throw new HttpException(255, 'Description is required', 01);
        $type = Yii::$app->request->post('type');
        if (empty($type)) throw new HttpException(255, 'Type is required', 01);

        if ($type == 'Event'){

            $pledge_deadline = Yii::$app->request->post('pledge_deadline');
            if (empty($pledge_deadline)) throw new HttpException(255, 'Pledge deadline is required', 01);
            $contribution_deadline = Yii::$app->request->post('contribution_deadline');
            if (empty($contribution_deadline)) throw new HttpException(255, 'Contribution deadline is required', 01);
        }

        //check if user has reached the maximum number of groups
        //get user package id
        $user_details = User::findOne($this->user_id);
        $package_id = $user_details->package_id;

        //get package group limit
        $package = Packages::findOne($package_id);
        if (empty($package)) throw new HttpException(255, 'Package not found',5);

        $group_limit = $package->group_limit;

        $group_count = Groups::find()->where(['created_by' => $this->user_id])->count();
        if ($group_count >= $group_limit) throw new HttpException(255, 'You have reached the maximum number of groups', 12);

        $search_group = Groups::find()->where(['name' => strtoupper($name)])->one();
        if (!empty($search_group)) throw new HttpException(255, 'Group already exists', 03);

        $group = new Groups();
        $group->name = strtoupper($name);
        $group->description = $description;
        $group->created_by = $this->user_id;
        $group->created_at = date('Y-m-d H:i:s');
        $group->type = ucfirst($type);
        if ($group->save()){
            $group_Member = new GroupMembers();
            $group_Member->group_id = $group->id;
            $group_Member->user_id = $this->user_id;
            $group_Member->role = 'Admin';
            $group_Member->joined_at = date('Y-m-d H:i:s');
            $group_Member->is_active = true;
            if ($group_Member->save()){

                if ($type == 'Event'){
                    $event_status = new EventStatus();
                    $event_status->group_id = $group->id;
                    $event_status->status = 'Open';
                    $event_status->updated_at = date('Y-m-d H:i:s');
                    $event_status->pledge_deadline = $pledge_deadline;
                    $event_status->contribution_deadline = $contribution_deadline;
                    if (!$event_status->save()){
                        Yii::error("*************************************** Event Status creation failed ************************");
                        Yii::error(json_encode($event_status->errors));
                        Yii::error("*************************************** end Event Status creation failed ************************");
                        throw new HttpException(255, "Failed to Record Event Status", 04);
                    }

                    //add to group setting
                    $group_settings = new EventSettings();
                    $group_settings->group_id = $group->id;
                    $group_settings->pledge_deadline = $pledge_deadline;
                    $group_settings->contribution_deadline = $contribution_deadline;
                    $group_settings->status = 'Active';
                    $group_settings->created_at = date('Y-m-d H:i:s');
                    $group_settings->created_by = $this->user_id;
                    $group_settings->save(false);

                }
        
                //generate notification 
                $params = [
                    'user_id' => $this->user_id,
                    'user_name' => $user_details->name,
                    'group_name' => $group->name,
                    'group_id' => $group->id,
                    'group_type' => $group->type,
                    'lang' => $user_details->language,
                    'type' => $package->notification_support,
                    'process' => 'createGroup', //push/sms/email/all
                ];

                $record_notification = Helper::generateNotification($params);
                if ($record_notification){
                    $response = [
                        'succes' => true,
                        'code' => 0,
                        'message' => "Group created successful",
                        'group' => $group
                    ];
                }
                else{
                    $response = [
                        'succes' => true,
                        'code' => 0,
                        'message' => "Group created successful failed to record notification",
                        'group' => $group
                    ];
                }  
            }
            else throw new HttpException(255, 'Group Member creation failed', 04);
        }
        else throw new HttpException(255, 'Group creation failed', 04);
 
        return $response;
    }

    # Search user
    public function actionSearchUser(){
        $phone_number = Yii::$app->request->post('phoneNumber');
        if (empty($phone_number)) throw new HttpException(255, 'Phone number is required', 01);

        //validate mobile 
        $cus_mob = Yii::$app->helper->validateMobile($phone_number);

        if (!$cus_mob['success']) throw new HttpException(255, $cus_mob['message'], 02);

        if ($cus_mob['cus_mob'] == $this->mobile)throw new HttpException(255, 'You cannot add yourself to a group', 13);

        $users = User::find()->where(['phone_number' => $cus_mob['cus_mob']])->one();
        if (empty($users)) throw new HttpException(255, 'User not found', 05);
        $response = [
            'success' => true,
            'code' => 0,
            'user_id' => $users['id'],
            'name' => $users['name'],
        ];
        return $response;
    }

    # Add user to group
    public function actionAddMember(){
        $group_id = Yii::$app->request->post('group_id');
        if (empty($group_id)) throw new HttpException(255, 'Group ID is required', 01);
        $user_id = Yii::$app->request->post('user_id');
        if (empty($user_id)) throw new HttpException(255, 'User ID is required', 01);
        $role = Yii::$app->request->post('role');
        if (empty($role)) throw new HttpException(255, 'Role is required', 01);

        $user = User::find()->where(['id' => $user_id])->one(); 
        if (empty($user)) throw new HttpException(255, 'User not found', 5);

        $group = Groups::find()->where(['id' => $group_id])->one();
        if (empty($group)) throw new HttpException(255, 'Group not found', 5);

        //check if user is Admin
        $group_Member = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $this->user_id])->one();
        if (empty($group_Member)) throw new HttpException(255, 'You are not a Member of this group', 13);
        if ($group_Member->role != 'Admin') throw new HttpException(255, 'You are not an Admin of this group', 13);

        //check if the user already is a Member in the group
        $is_Member = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $user_id])->one();
        if (!empty($is_Member)) throw new HttpException(255, "The User is already a Member", 03);

        //check leaving group tracking for record
        $leavingRecord = LeavingGroupTracking::find()->where(['user_id' => $user_id, 'group_id' => $group_id])->one();
        if (!empty($leavingRecord)) {
            if ($leavingRecord['attempts'] >= 3) throw new HttpException(255, 'You Cant add this Member has been removed more than three times', 13);
        }

        //check if group is Mchezo
        if ($group->type == 'Mchezo'){
            //check if group has payout schedule setting
            $group_setting = MchezoSettings::find()->where(['group_id' => $group_id])->one();
            //$payout_setting = PayoutScheduleSetting::find()->where(['group_id' => $group_id])->one();
            if (empty($group_setting)) throw new HttpException(255, "Please add setting before adding Members", 13);
        }
        else if($group->type == 'Kikoba') {
            $group_setting = VikobaSettings::find()->where(['group_id' => $group_id])->one();
            if (empty($group_setting)) throw new HttpException(255, "Please add setting before adding Members", 13);

        }
        else if ($group->type == 'Event'){
            $group_setting = EventSettings::find()->where(['group_id' => $group_id])->one();
            if (empty($group_setting)) throw new HttpException(255, "Please add setting before adding Members", 13);
        }
        else if ($group->type == 'Ujamaa'){
            $group_setting = UjamaaSettings::find()->where(['group_id' => $group_id])->one();
            if (empty($group_setting)) throw new HttpException(255, "Please add setting before adding Members", 13);
        }
        else{
            throw new HttpException(255, 'Unknown Group Type', 13);
        }
        // if (!$group || !in_array($group->type, ['Event', 'Ujamaa'])){

        //     // //check if contribution schedule is set
        //     // $contribution_schedule = Contributions::find()->where(['group_id' => $group_id])->one();
        //     // if (empty($contribution_schedule)) throw new HttpException(255, "Please add contribution schedule before adding Members", 13);
        // }

        //check if the group has reach its maximum number of Members
        //get group owner package id
        $user_details = User::findOne($group->created_by);
        $package_id = $user_details->package_id;

        //get package group limit
        $package = Packages::findOne($package_id);
        if (empty($package)) throw new HttpException(255, 'Package not found',5);

        $Member_limit = $package->Member_limit;
        $Member_count = GroupMembers::find()->where(['group_id' => $group_id])->count();
        if ($Member_count >= $Member_limit) throw new HttpException(255, "The Group has reach maximum number of Members", 14);

        if ($role == "Admin"){
            //count the Members with the role Admin
            $counts = GroupMembers::find()->where(['group_id' => $group_id, 'role' => 'Admin'])->count();
            if ($counts >= 2) throw new HttpException(255, "Group has maximum number of Admins", 15);
        }
        else if ($role == "Treasurer"){
            //count the Members with the role Admin
            $counts = GroupMembers::find()->where(['group_id' => $group_id, 'role' => 'Treasurer'])->count();
            if (!empty($counts)) throw new HttpException(255, "Group has maximum number of Treasurer", 15);
        }
        else if ($role == "Member"){
             
        }
        else if ($role == "Secretary"){
            //count the Members with the role Secretary
            $counts = GroupMembers::find()->where(['group_id' => $group_id, 'role' => 'Secretary'])->count();
            if (!empty($counts)) throw new HttpException(255, "Group has maximum number of Secretary", 15);
        }
        else if ($role == "Chairperson"){
            //count the Members with the role Chairperson
            $counts = GroupMembers::find()->where(['group_id' => $group_id, 'role' => 'Chairperson'])->count();
            if (!empty($counts)) throw new HttpException(255, "Group has maximum number of Chairperson", 15);
        }
        else throw new HttpException(255, "Role not Valid", 16);

        $group_Member = new GroupMembers();
        $group_Member->group_id = $group_id;
        $group_Member->user_id = $user_id;
        $group_Member->role = strtolower($role);
        $group_Member->joined_at = date('Y-m-d H:i:s');
        $group_Member->is_active = true;
        if ($group_Member->save()){

            //generate contribution schedule
            Helper::generateContributionSchedule($group_id);

            //generate notification
            $params = [
                'user_id' => $this->user_id,
                'user_name' => $user->name,
                'group_name' => $group->name,
                'group_type'=> $group->type,
                'group_id' => $group->id,
                'role' => $group_Member->role,
                'lang' => $user_details->language,
                'type' => $package->notification_support,
                'process' => 'addMember',
            ];

            $record_notification = Helper::generateNotification($params);
            if ($record_notification){
                $response = [
                    'success' => true,
                    'code' => 0,
                    'message' => 'User added to group successfully',
                ];
            }
            else{
                $response = [
                    'success' => true,
                    'code' => 0,
                    'message' => 'User added to group successfully failed to record notification',
                ];
            }
        }
        else throw new HttpException(255, 'Failed to add Member', 04); 

        return $response;
    }

    # Remove user from group
    public function  actionRemoveMember(){
        $Member_id =  Yii::$app->request->post('user_id');
        if (empty($Member_id)) throw new HttpException(255, 'User ID is required', 01);
        $group_id = Yii::$app->request->post('group_id');
        if (empty($group_id)) throw new HttpException(255, 'Group ID is required', 01);

        $user_id = $this->user_id;

        //check if group exist
        $group = Groups::find()->where(['id' => $group_id])->one();
        if (empty($group)) throw new HttpException(255, "Group not found", 5);

        //check if this user is a group Member 
        $is_Member1 = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $user_id])->one();
        if (empty($is_Member1)) throw new HttpException(255, "Your not a group Member", 13);
        
        //check if this user is Admin of this group
        if ($is_Member1->role != "Admin") throw  new HttpException(255, "Your not an Admin of this Group", 13);

        //check if Member to be removed is a Member
        $is_Member2 = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $Member_id])->one();
        if (empty($is_Member2)) throw new HttpException(255, "This user is not a Member", 13);

        //check if the Member to be removed is an Admin
        if ($is_Member2->role == "Admin") throw new HttpException(255, "You can not remove Admin Member", 13);


        //get details of Member to be removed 
        $Member_details = User::find()->where(['id' => $Member_id])->one();
        if (empty($Member_details)) throw new HttpException(255, 'user not found', 5);


        if ($is_Member2->delete()){

            //check if there is contribution schedule for the group for this user and the is_paid is 0
            $contribution_schedule = ContributionSchedule::find()->where(['group_id'=> $group_id, 'user_id'=> $Member_id, 'is_paid'=> 0])->one();

            if (!empty($contribution_schedule)){
                $contribution_schedule->delete(); 
            }

            // check if there is payout schedule for the group for this user

            $payout_schedule = PayoutSchedule::find()->where(['group_id'=> $group_id, 'user_id'=> $Member_id])->one();

            if (!empty($payout_schedule)){
                $payout_schedule->delete();
            }

            $user_details = User::find()->where(['id' => $this->user_id])->one();
            if (empty($user_details)) throw new HttpException(255, 'User not found', 5);
            //get package
            $package = Packages::find()->where(['id' => $this->package_id])->one();
            if (empty($package)) throw new HttpException(255, 'Package not found', 5);

            //recording leaving record
            //check if record exist
            $record = LeavingGroupTracking::find()
                ->where(['user_id'=> $Member_id])
                ->andWhere(['group_id'=> $group_id])
            ->one();
            Yii::error("attempts");
            Yii::error($this->user_id); 
            if (empty($record)) {
                $record = new LeavingGroupTracking();
                $record->user_id = $Member_id;
                $record->group_id = $group_id;
                $record->attempts = 1;
                $record->save(false);
            }
            else {
                Yii::error($record);
                $record->attempts = $record->attempts + 1;
                $record->save(false);
            }

            //generate notification
            $params = [
                'user_id' => $this->user_id,
                'user_name' => $Member_details->name,
                'group_name' => $group->name,
                'group_type'=> $group->type,
                'group_id' => $group->id,
                'role' => $is_Member2->role,
                'lang' => $user_details->language,
                'type' => $package->notification_support,
                'process' => 'removeMember',
            ];

            $record_notification = Helper::generateNotification($params);
            if ($record_notification){
                $response = [
                    'success' => true,
                    'code' => 0,
                    'message' => 'User removed from group successfully',
                ];
            }
            else {
                $response = [
                    'success' => true,
                    'code' => 0,
                    'message' => 'User removed from group successfully failed to generate notification',
                ];
            }
            
        }
        else throw new HttpException(255, 'Failed to Remove Member', 17);

        return $response;

    }

    # Leave the Group
    public function actionLeaveGroup(){
        $group_id = Yii::$app->request->post('group_id');
        if (empty($group_id)) throw new HttpException(255, 'Group id is required', 01);

        //check if group exist
        $group = Groups::find()->where(['id' => $group_id])->one();
        if (empty($group)) throw new HttpException(255, 'Group not found', 5);

        //check if current user is group Member
        $group_Member = GroupMembers::find()->where(['group_id'=> $group_id, 'user_id'=> $this->user_id])->one();
        if (empty($group_Member)) throw new HttpException(255, 'You are not a Member of this group', 13);

        //check if the current user is the group Admin
        if ($group_Member->role == 'Admin') throw new HttpException(255, 'You are the group Admin', 13);

        //remove the user from the group
        $group_Member->delete();

        //check if there is contribution schedule for the group for this user and the is_paid is 0
        $contribution_schedule = ContributionSchedule::find()->where(['group_id'=> $group_id, 'user_id'=> $this->user_id, 'is_paid'=> 0])->one();

        if (!empty($contribution_schedule)){
            $contribution_schedule->delete(); 
        }

        // check if there is payout schedule for the group for this user

        $payout_schedule = PayoutSchedule::find()->where(['group_id'=> $group_id, 'user_id'=> $this->user_id])->one();

        if (!empty($payout_schedule)){
            $payout_schedule->delete();
        }

        // $group_creator = $group->created_by;
        // $creator_details = User::find()->where(['id'=> $group_creator])->one(); 
        // $package_id = $creator_details->package_id;
        //$package = Packages::findOne($package_id);

        $package = Yii::$app->helper->getPackagebyGroupID($group_id);

        $user = User::findOne($this->user_id);

        //recording leaving record
        //check if record exist
        $record = LeavingGroupTracking::find()->where(['user_id'=> $this->user_id])
        ->andWhere(['group_id'=> $group_id])
        ->one();
        Yii::error("left attempts");
        Yii::error($record); 
        if (empty($record)) {
            $record = new LeavingGroupTracking();
            $record->user_id = $this->user_id;
            $record->group_id = $group_id;
            $record->attempts = 1;
            $record->save(false);
        }
        else {
            $record->attempts = $record->attempts + 1;
            $record->save(false);
        }

        //generate notification
        $params = [
            'user_id' => $this->user_id,
            'user_name' => $user->name,
            'group_name' => $group->name,
            'group_type'=> $group->type,
            'group_id' => $group->id,
            'role' => $group_Member->role,
            'lang' => $user->language,
            'type' => $package->notification_support,
            'process' => 'leaveGroup',
        ];

        $record_notification = Helper::generateNotification($params);
        if ($record_notification){
            $response = [
                'success' => true,
                'code' => 0,
                'message' => 'User left successful',
            ];
        }
        else{
            $response = [
                'success' => true,
                'code' => 0,
                'message' => 'User left success but failed to record notification',
            ];
        }
        return $response;
    }

    # Update user role in a group
    public function actionUpdateMember(){
        $Member_id = Yii::$app->request->post('user_id');
        if (empty($Member_id)) throw new HttpException(255, 'User ID is required', 01);
        $group_id = Yii::$app->request->post('group_id');
        if (empty($group_id)) throw new HttpException(255, 'Group ID is required', 01);
        $role = Yii::$app->request->post('role');
        if (empty($role)) throw new HttpException(255, "Role is required", 01);


        $user_id = $this->user_id;

        $Member_details = User::find()->where(['id' => $Member_id])->one();
        if (empty($Member_details)) throw new HttpException(255, 'Member not found', 5);

        //check if the group exist
        $group = Groups::find()->where(['id' => $group_id])->one();
        if (empty($group)) throw new HttpException(255, 'Group not found', 5);

        //check if user is Member
        $group_Member = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $user_id])->one();
        if (empty($group_Member)) throw new HttpException(255, 'You are not a Member of this group', 13);
        //check if user is Admin
        if ($group_Member->role != 'Admin') throw new HttpException(255, 'You are not an Admin of this group', 13);

        //check if user id is equal to Member id
        if ($user_id == $Member_id) throw new HttpException(255, "You can not change your role", 13);
        
        //check if Member is already Member
        $is_Member = GroupMembers::find()->where(['group_id' => $group_id])->andWhere(['user_id' => $Member_id])->one();
        if (empty($is_Member)) throw new HttpException(255, 'This user is not a Member of this group', 13);

        //check if the role to be asigned equals to the same  Member role
        if ($is_Member->role == $role) throw new HttpException(255, "the user is already a ". $role , 03);

        if ($role == "Admin"){
            //count the Members with the role Admin
            $counts = GroupMembers::find()->where(['group_id' => $group_id, 'role' => 'Admin'])->count();
            if ($counts >= 2) throw new HttpException(255, "Group has maximum number of Admins", 15);
        }
        else if ($role == "Treasurer"){
            //count the Members with the role Admin
            $counts = GroupMembers::find()->where(['group_id' => $group_id, 'role' => 'Treasurer'])->count();
            if (!empty($counts)) throw new HttpException(255, "Group has maximum number of Treasurer", 15);
        }
        else if ($role == "Member"){
             
        }
        else if ($role == "Secretary"){
            //count the Members with the role Secretary
            $counts = GroupMembers::find()->where(['group_id' => $group_id, 'role' => 'Secretary'])->count();
            if (!empty($counts)) throw new HttpException(255, "Group has maximum number of Secretary", 15);
        }
        else if ($role == "Chairperson"){
            //count the Members with the role Chairperson
            $counts = GroupMembers::find()->where(['group_id' => $group_id, 'role' => 'Chairperson'])->count();
            if (!empty($counts)) throw new HttpException(255, "Group has maximum number of Chairperson", 15);
        }
        else if ($role == "Vice_Chairperson"){
            //count the Members with the role vice Chairperson
            $counts = GroupMembers::find()->where(['group_id' => $group_id, 'role' => 'vice_Chairperson'])->count();
            if (!empty($counts)) throw new HttpException(255, "Group has maximum number of Vice Chairperson", 15);
        }
        else if ($role == "Organizer"){
            //count the Members with the role organizer
            $counts = GroupMembers::find()->where(['group_id' => $group_id, 'role' => 'organizer'])->count();
            if ($counts >= 3) throw new HttpException(255, "Group has maximum number of Organizers", 15);
        }
        else if ($role == "Coordinator"){
            //count the Members with the role coordinator
            $counts = GroupMembers::find()->where(['group_id' => $group_id, 'role' => 'coordinator'])->count();
            if ($counts >= 2) throw new HttpException(255, "Group has maximum number of Coordinators", 15);
        }
        else throw new HttpException(255, "Role not Valid", 16);

        $is_Member->role = $role;
        if ($is_Member->save()){

            $group_owner = User::find()->where(['id' => $group->created_by])->one();
            if (empty($group_owner)) throw new HttpException(255, 'User not found', 5);

            $package = Packages::find()->where(['id' => $group_owner->package_id])->one();
            if (empty($package)) throw new HttpException(255, 'Package not found', 5);
            //generate notification
            $params = [
                'user_id' => $this->user_id,
                'user_name' => $Member_details->name,
                'group_name' => $group->name,
                'group_type'=> $group->type,
                'group_id' => $group->id,
                'role' => $is_Member['role'],
                'lang' => $group_owner->language,
                'type' => $package->notification_support,
                'process' => 'updateMember',
            ];

            $record_notification = Helper::generateNotification($params);
            if ($record_notification){
                $response = [
                    'success' => true,
                    'code' => 0,
                    'message' => 'Member updated successful'
                ];
            }
            else{
                $response = [
                    'success' => true,
                    'code' => 0,
                    'message' => 'Member updated successful failed to record notification'
                ];
            }
        }
        else throw new HttpException(255, 'Failed To update Member Role', 9);
        
        return $response;
    }

    # Group search
    public function actionGroupSearch(){
        $group_name = Yii::$app->request->post('groupName');
        if (empty($group_name)) throw new HttpException(255, 'Group name is required', 01);

        $groups = Groups::find()
            ->leftJoin('group_members', 'group_members.group_id = groups.id')        
            ->where(['like', 'name', $group_name])
            ->andWhere(['group_members.user_id' => $this->user_id])
            ->all();
            
        if (empty($groups)) throw new HttpException(255, 'Group not found', 5);
        $response = [
            'success' => true,
            'code' => 0,
            'groups' => $groups
        ];
        return $response;
    }

    # Group details
    public function actionGroupDetails(){
        // Yii::error("************************* This is the post data *****************************");
        // Yii::error(Yii::$app->request->post());
        // Yii::error("*********************************************************************");
        $group_id = Yii::$app->request->post('groupId');
        if (empty($group_id)) throw new HttpException(255, 'Group ID is required', 01);

        $group = Groups::find()
            ->select(['id', 'name', 'description', 'created_at', 'created_by', 'logo_url', 'type'])
            ->where(['id' => $group_id])
            ->asArray()
            ->one();
            
        if (empty($group)) throw new HttpException(255, 'Group not found', 5); 

        $setting = $this->getGroupSetting($group['id'], $group['type']);
        if ($setting['success']){
            $group_setting = $setting['data'];
        }
        else{
            $group_setting = [];
        }

        //check if the user is a Member of this group
        $group_Member = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $this->user_id])->one();
        if (empty($group_Member)) throw new HttpException(255, 'You are not a Member of this group', 13);

        $user_role = $group_Member->role;

        if ($group['type'] == 'Event'){
            //check if the user has pledged for this group
            $pledges = Pledges::find()->where(['group_id' => $group_id, 'user_id' => $this->user_id])->one();
            if (empty($pledges)) {
                $is_pledged = false;
            }
            else{
                $is_pledged = true;
            }
        }
        else{
            $is_pledged = false;
        }
        
        $group_Members = GroupMembers::find()
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
            ->orderBy(['group_embers.joined_at' => SORT_DESC])
            ->asArray()
            ->all();

        $total_Members = GroupMembers::find()
            ->leftJoin('users', 'users.id = group_members.user_id')
            ->leftJoin('groups', 'groups.id = group_members.group_id')
            ->where(['group_members.group_id' => $group_id])
            ->count();  

        //get user payments
        //$user_payments = $this->getUserPayments($this->user_id);

        //get upcoming schedules
        //$upcoming_schedules = Helper::nextContributionsPerGroup($this->user_id);

        $incoming_payments = Payments::find()
            ->select(['payments.*', 'groups.name as group_name', 'users.name as user_name'])
            ->leftJoin('groups', 'groups.id = payments.group_id')
            ->leftJoin('users', 'users.id = payments.user_id')
            ->where(['group_id' => $group_id])
            ->orderBy(['payment_date' => SORT_DESC]) // Order by payment_date DESC
            ->asArray()
            ->all();
    
        $outgoing_payments = OutgoingPayment::find()
            ->select(['outgoing_payment.*', 'groups.name as group_name', 'users.name as user_name'])
            ->leftJoin('groups', 'groups.id = outgoing_payment.group_id')
            ->leftJoin('users', 'users.id = outgoing_payment.recipient_id')
            ->where(['group_id' => $group_id])
            ->orderBy(['created_at' => SORT_DESC]) // Order by payment_date DESC
            ->asArray()
            ->all(); 
        
        $total_income = Payments::find()
            ->leftJoin('groups', 'groups.id = payments.group_id')
            ->leftJoin('users', 'users.id = payments.user_id')
            ->where(['group_id' => $group_id])
            ->sum('payments.amount');

        $total_outgoing = OutgoingPayment::find()
            ->leftJoin('groups', 'groups.id = outgoing_payment.group_id')
            ->leftJoin('users', 'users.id = outgoing_payment.recipient_id')
            ->where(['group_id' => $group_id])
            ->andWhere(['outgoing_payment.status' => 'Approved'])
            ->sum('outgoing_payment.amount');
            
        $response = [
            'success' => true,
            'code' => 0,
            'data' => [
                'group' => $group,
                'income' => $incoming_payments,
                'outgoing' => $outgoing_payments,
                'total_income' => $total_income,
                'total_outgoing' => $total_outgoing,
                'total_members' => $total_Members,
                'is_pledged' => $is_pledged,
                'group_members' => $group_Members,
                //'user_payments' => $user_payments,
                //'upcoming_schedules' => $upcoming_schedules,
                'group_setting' => $group_setting,
                'user_role' => $user_role,
                'message' => null
            ]
            
        ];
        return $response;   
    }

    # Group Settings
    public function actionGroupSettings(){
        Yii::error("********************************* Received Data in Group Setting ****************************************");
        Yii::error(Yii::$app->request->post());
        Yii::error("*************************************** END ****************************************************");

        $receivedData = Yii::$app->request->post();
        $group_id = $receivedData['groupId'];
        if (empty($group_id)) throw new HttpException(255, 'Group id is required', 01);

        $group = Groups::find()->where(['id' => $group_id])->one();

        if (empty($group)) throw new HttpException(255, 'Group not found', 5);

        //check if login user is Admin of this group
        $group_Member = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $this->user_id])->one();
        if (empty($group_Member)) throw new HttpException(255, 'You are not a Member of this group', 13);
        if ($group_Member->role != 'Admin') throw new HttpException(255, 'You are not an Admin of this group', 13);

        $group_type = $group->type;

        if ($group_type == 'Kikoba'){
            $model = VikobaSettings::find()->where(['group_id' => $group_id])->one();
            if (empty($model)){
                $model = new VikobaSettings();
                $model->group_id = $group_id;
                $model->created_at = date('Y-m-d H:i:s');
                $model->created_by = $this->user_id;
            }
            if (!empty($receivedData['contributionSchedule'])){
                $validSchedule = Yii::$app->params['valid_contribution_schedules'];
                if (!in_array($receivedData['contributionSchedule'], $validSchedule)) {
                    throw new \Exception('Invalid Contribution Schedule');
                }
            }
            if (!empty($receivedData['receivingMobileNumber'])){
                //validate mobile number
                $cus_mob = Yii::$app->helper->validateMobile($receivedData['receivingMobileNumber']);
                if (!$cus_mob['success']) throw new HttpException(255, $cus_mob['message'], 02);
                $model->receiving_number = $cus_mob['cus_mob'];
            }
            $model->contribution_schedule = $receivedData['contributionSchedule'];
            $model->contribution_amount = $receivedData['contributionAmount'];
            $model->shares_value = $receivedData['amountPerShare'];
            $model->max_shares_per_member = $receivedData['maximumSharesPerMember'];
            if ($receivedData['allowSellShares']){
                $model->allow_selling = 1; //true
            }
            else{
                $model->allow_selling = 0; //false
            }
            $model->start_date = $receivedData['contributionStartDate'];
            $model->status = 'Active';
            $model->withdraw_initiator = $receivedData['withdrawInitiator'];  
            $model->withdraw_approver = $receivedData['withdrawApprover'];
            $model->updated_at = date('Y-m-d H:i:s');
            $model->updated_by = $this->user_id;
            if ($model->save()){

                //add in the contribution
                //check it if contribution setting exist
                $new = Contributions::find()->where(['group_id' => $group_id])->one();
                if (empty($new)){
                    $new = new Contributions();
                    $new->created_at = date('Y-m-d H:i:s');
                }
                $new->group_id = $group_id;
                $new->amount = $receivedData['contributionAmount'];
                $new->frequency = $receivedData['contributionSchedule'];
                $new->start_date = $receivedData['contributionStartDate'];
                $new->updated_at = date('Y-m-d H:i:s');
                $new->save();

                $response =  [
                    'success' => true,
                    'code'    => 0,
                    'message' => 'Group Setting Updated Successful',
                    'data' => $model
                ];

                return $response;
            }
            else throw new HttpException(255, 'Failed To update Group Setting', 9);

        }
        else if ($group_type == 'Event'){
            $model = EventSettings::find()->where(['group_id' => $group_id])->one();
            if(empty($model)){
                $model = new EventSettings();
                $model->group_id = $group_id;
                $model->created_at = date('Y-m-d H:i:s');
                $model->created_by = $this->user_id;
            }
            if (!empty($receivedData['receivingNumber'])){
                //validate mobile number
                $cus_mob = Yii::$app->helper->validateMobile($receivedData['receivingNumber']);
                if (!$cus_mob['success']) throw new HttpException(255, $cus_mob['message'], 02);
                $model->receiving_number = $cus_mob['cus_mob'];
            }
            $model->pledge_deadline = $receivedData['pledgeDeadline'];
            $model->contribution_deadline = $receivedData['contributionDeadline'];
            $model->status = 'Active';
            $model->withdraw_initiator = $receivedData['withdrawInitiator'];  
            $model->withdraw_approver = $receivedData['withdrawApprover'];
            $model->updated_at = date('Y-m-d H:i:s');
            $model->updated_by = $this->user_id;
            if ($model->save()){
                $response =  [
                    'success' => true,
                    'code'    => 0,
                    'message' => 'Group Setting Updated Successful',
                    'data' => $model
                ];

                return $response;
            }
            else throw new HttpException(255, 'Failed To update Group Setting', 9);
        }
        else if ($group_type == 'Mchezo'){
            $model = MchezoSettings::find()->where(['group_id' => $group_id])->one();
            if (empty($model)){
                $model = new MchezoSettings();
                $model->group_id = $group_id;
                $model->created_at = date('Y-m-d H:i:s');
                $model->created_by = $this->user_id;
            }
            if (!empty($receivedData['contributionSchedule'])){
                $validSchedule = Yii::$app->params['valid_contribution_schedules'];
                if (!in_array($receivedData['contributionSchedule'], $validSchedule)) {
                    throw new HttpException(255, 'Invalid Contributiont Schedule', 16);
                }
                if ($receivedData['contributionSchedule'] == 'custom'){
                    if (empty($receivedData['contribution_schedule_custom_interval'])){
                        throw new HttpException(255, 'Custom contribution is required', 16);
                    }
                    else {
                        $model->contribution_schedule_custom_interval = $receivedData['contribution_schedule_custom_interval'];
                    }
                }
            }
            if (!empty($receivedData['payoutSchedule'])){
                $validSchedule = Yii::$app->params['valid_contribution_schedules'];
                if (!in_array($receivedData['payoutSchedule'], $validSchedule)) {
                    throw new HttpException(255, 'Invalid Payout Schedule', 16);
                    if ($receivedData['payoutSchedule'] == 'custom'){
                        if (empty($receivedData['payoutScheduleCustom'])){
                            throw new HttpException(255, 'Custom payout is required', 16);
                        }
                        else {
                            $model->payout_schedule_custom_interval = $receivedData['payoutSchedule'];
                        }
                    }
                }
            }
            if (!empty($receivedData['receivingNumber'])){
                //validate mobile number
                $cus_mob = Yii::$app->helper->validateMobile($receivedData['receivingNumber']);
                if (!$cus_mob['success']) throw new HttpException(255, $cus_mob['message'], 02);
                $model->receiving_number = $cus_mob['cus_mob'];
            }

            $model->contribution_schedule = $receivedData['contributionSchedule'];
            $model->payout_schedule = $receivedData['payoutSchedule'];
            $model->contribution_amount = $receivedData['contributionAmount'];
            $model->payout_percent = $receivedData['payoutPercent'];
            $model->withdraw_initiator = $receivedData['withdrawInitiator'];  
            $model->withdraw_approver = $receivedData['withdrawApprover'];
            $model->start_date = $receivedData['startDate'];
            $model->status = 'Active';
            $model->updated_at = date('Y-m-d H:i:s');
            $model->updated_by = $this->user_id;
            if ($model->save()){

                //add in the contribution
                //check it if contribution setting exist
                $new = Contributions::find()->where(['group_id' => $group_id])->one();
                if (empty($new)){
                    $new = new Contributions();
                    $new->created_at = date('Y-m-d H:i:s');
                }
                $new->group_id = $group_id;
                $new->amount = $receivedData['contributionAmount'];
                $new->frequency = $receivedData['contributionSchedule'];
                if ($receivedData['contributionSchedule'] == 'custom'){
                    $new->custom_frequency_days = $receivedData['contribution_schedule_custom_interval'];
                }
                $new->start_date = $receivedData['contributionStartDate'];
                $new->updated_at = date('Y-m-d H:i:s');
                $new->save();

                $response =  [
                    'success' => true,
                    'code'    => 0,
                    'message' => 'Group Setting Updated Successful',
                    'data' => $model
                ];

                return $response;
            }
            else throw new HttpException(255, 'Failed To update Group Setting', 9);
        }
        else if ($group_type == 'Ujamaa'){
            $model = UjamaaSettings::find()->where(['group_id' => $group_id])->one();
            if (empty($model)){
                $model = new UjamaaSettings();
                $model->group_id = $group_id;
                $model->created_at = date('Y-m-d H:i:s');
                $model->created_by = $this->user_id;
            }
            if (!empty($receivedData['receivingNumber'])){
                //validate mobile number
                $cus_mob = Yii::$app->helper->validateMobile($receivedData['receivingNumber']);
                if (!$cus_mob['success']) throw new HttpException(255, $cus_mob['message'], 02);
                $model->receiving_number = $cus_mob['cus_mob'];
            }

            $model->start_date = $receivedData['startDate'];
            $model->minimum_amount = $receivedData['minimumAmount'];
            $model->withdraw_initiator = $receivedData['withdrawInitiator'];  
            $model->withdraw_approver = $receivedData['withdrawApprover'];
            $model->status = 'Active';
            $model->updated_at = date('Y-m-d H:i:s');
            $model->updated_by = $this->user_id;
            if ($model->save()){
                $response =  [
                    'success' => true,
                    'code'    => 0,
                    'message' => 'Group Setting Updated Successful',
                    'data' => $model
                ];

                return $response;
            }
            else throw new HttpException(255, 'Failed To update Group Setting', 9);
            

        }
        else throw new HttpException(255, 'Unknown Group type', 16);
    }

    # GET GROUP SETTING
    public function actionGetGroupSetting(){
        $group_id = Yii::$app->request->post('groupId');
        if (empty($group_id)) throw new HttpException(255, 'Group id is required', 01);

        $group = Groups::find()->where(['id' => $group_id])->one();

        if (empty($group)) throw new HttpException(255, 'Group not found', 5);

        $group_type = $group->type;
        
        $model = null;
        if ($group_type == 'Kikoba'){
            $model = VikobaSettings::find()->where(['group_id' => $group_id])->one();
        }
        else if ($group_type == 'Mchezo'){
            $model = MchezoSettings::find()->where(['group_id' => $group_id])->one();
        }
        else if ($group_type == 'Ujamaa'){
            $model = UjamaaSettings::find()->where(['group_id' => $group_id])->one();
        }
        else if ($group_type == 'Event'){
            $model = EventSettings::find()->where(['group_id' => $group_id])->one();
        }
        else{
            throw new HttpException(255, 'Unknown Group type', 16);
        }

        $response = [
            'success' => true,
            'code'    => 0,
            'data'    => $model 
        ];

        return $response;
    }

    # Create ContributionSchedule
    public function actionContributionSchedule(){
        $group_id = Yii::$app->request->post('group_id');
        if (empty($group_id)) throw new HttpException(255, 'Group ID is required', 01);

        $amount = Yii::$app->request->post('amount');
        if (empty($amount)) throw new HttpException(255, 'Amount is required', 01);

        $frequency = Yii::$app->request->post('frequency');
        if (empty($frequency)) throw new HttpException(255, 'Frequency is required', 01);   
        
        $grace_period = Yii::$app->request->post('grace_period');
        if (empty($grace_period)) {
            $grace_period = 0;
        }

        $payment_method = Yii::$app->request->post('payment_method');
        if (empty($payment_method)) throw new HttpException(255, 'Payment method is required', 01);

        $start_date = Yii::$app->request->post('start_date');
        if (empty($start_date)) throw new HttpException(255, 'Start date is required', 01); 

        

        $group = Groups::find()->where(['id' => $group_id])->one();
        if (empty($group)) throw new HttpException(255, 'Group not found', 5);

        $group_Member = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $this->user_id])->one();
        if (empty($group_Member)) throw new HttpException(255, 'User not a Member of this group', 13);

        if ($group_Member->role != 'Admin') throw new HttpException(255, 'User is not an Admin of this group', 13);

        //check of group has a contribution schedule
        $contribution_schedule = Contributions::find()->where(['group_id' => $group_id])->one();
        if (!empty($contribution_schedule)) {
            //update the contribution schedule
            $contribution_schedule->amount = $amount;
            $contribution_schedule->frequency = $frequency;
            $contribution_schedule->grace_period_days = $grace_period;
            $contribution_schedule->payment_method = $payment_method;
            $contribution_schedule->start_date = $start_date;
            $contribution_schedule->updated_at = date('Y-m-d H:i:s');
            if ($contribution_schedule->save()){
                $response = [
                    'success' => true,
                    'code' => 0,
                    'message' => 'Contribution schedule updated successfully',
                ];
            }
            else throw new HttpException(255, 'Contribution schedule update failed', 9);
        }
        else{
            //create a new contribution schedule
            $contribution_schedule = new Contributions();
            $contribution_schedule->group_id = $group_id;
            $contribution_schedule->amount = $amount;
            $contribution_schedule->frequency = $frequency;
            $contribution_schedule->grace_period_days = $grace_period;
            $contribution_schedule->payment_method = $payment_method;
            $contribution_schedule->start_date = $start_date;
            $contribution_schedule->created_at = date('Y-m-d H:i:s');
            if ($contribution_schedule->save()){
                $response = [
                    'success' => true,
                    'code' => 0,
                    'message' => 'Contribution schedule created successfully',
                ];
            }
            else throw new HttpException(255, 'Contribution schedule creation failed', 04);
        }
        return $response;   
    }

    # Change profile picture
    public function actionChangeProfile(){
        $profile = UploadedFile::getInstanceByName("profile");
        if (empty($profile)) throw new HttpException(255, 'Profile Picture is required', 01);

        $userId  = $this->user_id;
        $picNumber = rand(10000, 99999);

        $fileName = $userId. $picNumber . '.' . $profile->extension;
        $path = Yii::getAlias('@webroot') . '/'. 'profile/' . $fileName;

        // //check if the picture for this user exist and delete it if present
        // if (file_exists($path)) {
        //     unlink($path);
        // }

        if ($profile->saveAs($path)) {
            $model = User::findOne(['id' => $userId]);

            if (!empty($model)){
                $model->profile_picture = $fileName;
                if ($model->save()) {
                    $response =  [
                        "success" => true,
                        "code" => 0,
                        "message" => "Profile picture changed successfully",
                    ];
    
                    return $response;
                }
                throw new HttpException(255, 'Failed to save profile to the database', 9);
            } throw new HttpException(255, "User not found", 5);
        } throw new HttpException(255, "Failed to save the profile picture", 18);
    }

    # Update User Details
    public function actionProfileUpdate(){ 
        $bio = Yii::$app->request->post('bio');
        $language = Yii::$app->request->post('lang');

        $user = User::findOne(['id' => $this->user_id]);
        if (empty($user)) throw new HttpException(255, 'User not found', 5);
        
        // Validate and update bio if provided
        if (isset($bio)) {

            if (!in_array($bio, [0, 1])) {
                throw new HttpException(255, 'Invalid bio value. Must be 0 or1', 16);
            }
            $user->biometric_enabled = (bool)$bio;
        }
        
        // Validate and update language if provided
        if (!empty($language)) {
            if (!in_array($language, ['en', 'sw'])) {
                throw new HttpException(255, 'Invalid language value. Must be en or sw', 16);
            }
            $user->language = $language;
        }

        
        if ($user->save()){
            $response = [
                'success' => true,
                'code' => 0,
                'message' => 'User details updated successfully',
            ];
        }
        else{
            Yii::error("Save failed with errors: " . json_encode($user->errors));
            throw new HttpException(255, 'User details update failed', 9);
        }
        return $response;
    }

    # New Pledge
    public function actionNewPledge(){
        // Yii::error("************************* Received Data ******************************************");
        // Yii::error(Yii::$app->request->post());
        // Yii::error("************************* END ******************************************");

        $group_id = Yii::$app->request->post('group_id');
        if (empty($group_id)) throw new HttpException(255, 'Group ID is required', 01);

        $amount = Yii::$app->request->post('amount');
        if (empty($amount)) throw new HttpException(255, 'Amount is required', 01);

        $to_be_paid_at = Yii::$app->request->post('to_be_paid_at');
        if (empty($to_be_paid_at)) throw new HttpException(255, 'To be paid at is required', 01);

        //check the amount is greater than 100
        if ($amount < 100) throw new HttpException(255, 'Amount must be greater than 100', 16);
        $user_id = $this->user_id;

        //check if the group is an event
        $group = Groups::find()->where(['id' => $group_id])->one();
        if (empty($group)) throw new HttpException(255, 'Group not found', 5);
        if ($group->type != 'Event') throw new HttpException(255, 'Group is not an event', 13);

        //check if the event is open
        $event_status = EventStatus::find()->where(['group_id' => $group_id])->one();
        if (empty($event_status)) throw new HttpException(255, 'Event is not open', 13);
        if ($event_status->status != 'Open') throw new HttpException(255, 'Event is not open', 13);

        //check if the user is the Member of the group
        $group_Member = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $user_id])->one();
        if (empty($group_Member)) throw new HttpException(255, 'You are not a Member of this group', 13);

        $group_settings = EventSettings::find()->where(['group_id' => $group_id])->one();
        if (empty($group_settings)) throw new HttpException(255, 'Event settings not found', 13);



        //check if the pledge deadline is in the future
        if ($group_settings->pledge_deadline < date('Y-m-d H:i:s')) throw new HttpException(255, 'Pledge deadline has passed', 13);

        //check if the to be paid at valid based on contribution deadline
        if ($group_settings->contribution_deadline < $to_be_paid_at) throw new HttpException(255, 'The contribution deadline is : '. $group_settings->contribution_deadline, 13);

        //check if the user has already pledged
        $pledges = Pledges::find()->where(['group_id' => $group_id, 'user_id' => $user_id])->one();
        if (!empty($pledges)) {

            //check if the new amount is greater than the old amount
            if ($pledges->amount < $amount) {

                // check if user has paid to deduct the amount from the pledge
                $paid_amount = $pledges->paid_amount;
                if ($paid_amount > 0) {
                    $pledges->remain_amount = $amount - $paid_amount;
                }
                else{
                    $pledges->remain_amount = $amount;
                }
                $pledges->amount = $amount;
                $pledges->to_be_paid_at = $to_be_paid_at;
                $pledges->updated_at = date('Y-m-d H:i:s');
                if ($pledges->save()){

                    $login_user_details = User::find()->where(['id' => $this->user_id])->one();
                    if (empty($login_user_details)) throw new HttpException(255, 'User not Found', 5);
                    $package = Packages::find()->where(['id' => $this->package_id])->one();
                    if (empty($package)) throw new HttpException(255, 'Package not found', 5);

                    //genereate notification
                    $params = [
                        'user_id' => $this->user_id,
                        'user_name' => $this->login_user_name,
                        'group_name' => $group->name,
                        'group_type'=> $group->type,
                        'group_id' => $group->id,
                        'amount' => $amount,
                        'lang' => $login_user_details->language,
                        'type' => $package->notification_support,
                        'process' => 'newPledge',
                    ];
        
                    $record_notification = Helper::generateNotification($params);
                    if ($record_notification){
                        $response = [
                            'success' => true,
                            'code' => 0,
                            'message' => 'Pledge updated successfully',
                        ];
                    }
                    else{
                        $response = [
                            'success' => true,
                            'code' => 0,
                            'message' => 'Pledge updated successfully failed to generete notification',
                        ];
                    }
                    return $response;
                }
                else throw new HttpException(255, 'Pledge update failed', 9);
            }
            else throw new HttpException(255, 'New pledge amount must be greater than the previous pledge amount', 16);
        }

        $pledge = new Pledges();
        $pledge->group_id = $group_id;
        $pledge->user_id = $user_id;
        $pledge->amount = $amount;
        $pledge->to_be_paid_at = $to_be_paid_at;
        $pledge->pledge_date = date('Y-m-d H:i:s');
        $pledge->status = 'Not Paid';
        $pledge->created_at = date('Y-m-d H:i:s');
        $pledge->updated_at = date('Y-m-d H:i:s');
        $pledge->paid_amount = 0;
        $pledge->remain_amount = $amount;
        $pledge->paid_at = null;

        if ($pledge->save()){
            $login_user_details = User::find()->where(['id' => $this->user_id])->one();
            if (empty($login_user_details)) throw new HttpException(255, 'User not Found', 5);
            $package = Packages::find()->where(['id' => $this->package_id])->one();
            if (empty($package)) throw new HttpException(255, 'Package not found', 5);

            //genereate notification
            $params = [
                'user_id' => $this->user_id,
                'user_name' => $this->login_user_name,
                'group_name' => $group->name,
                'group_type'=> $group->type,
                'group_id' => $group->id,
                'amount' => $amount,
                'lang' => $login_user_details->language,
                'type' => $package->notification_support,
                'process' => 'newPledge',
            ];

            $record_notification = Helper::generateNotification($params);
            if ($record_notification){
                $response = [
                    'success' => true,
                    'code' => 0,
                    'message' => 'Thank you for your pledge',
                ];
            }
            else{
                $response = [
                    'success' => true,
                    'code' => 0,
                    'message' => 'Thank you for your pledge failed to generate notification',
                ];
            }

            return $response;
            
        }
        else throw new HttpException(255, 'Your pledge was not created', 04);
    }

    # My Pledges
    public function actionMyPledges(){
        $user_id = $this->user_id;

        $pledges = Pledges::find()->where(['user_id' => $user_id])->all();

        if (empty($pledges)) {
            $response = [
                'success' => true,
                'code' => 0,
                'message' => 'You have no pledges',
            ];
        }
        else{
            $response = [
                'success' => false,
                'message' => 'You have already pledged',
            ];
        }
        return $response;
    }

    # Event Pledges
    public function actionEventPledges(){
        $group_id = Yii::$app->request->post('group_id');
        if (empty($group_id)) throw new HttpException(255, 'Group ID is required', 01);

        $user_id = $this->user_id;

        //check if group exists
        $group = Groups::find()->where(['id' => $group_id])->one();
        if (empty($group)) throw new HttpException(255, 'Group not found', 5);

        //check if group is an event
        if ($group->type != 'Event') throw new HttpException(255, 'Group is not an event', 13);

        //check if user is a Member of the group
        $group_Member = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $user_id])->one();
        if (empty($group_Member)) throw new HttpException(255, 'You are not a Member of this group', 13);

        //get group settings
        $group_settings = EventSettings::find()->where(['group_id' => $group_id])->one();
        if (empty($group_settings)) throw new HttpException(255, "Group Setting is not found", 13);

        //get pledges for the event with user names and group name
        $pledges = Pledges::find()
            ->select(['pledges.*', 'users.name as user_name', 'groups.name as group_name'])
            ->leftJoin('users', 'users.id = pledges.user_id')
            ->leftJoin('groups', 'groups.id = pledges.group_id')
            ->where(['pledges.group_id' => $group_id])
            ->asArray()
            ->all();

        $response = [
            'success' => true,
            'code' => 0,
            'pledges' => $pledges,
            'pledge_deadline' => $group_settings->pledge_deadline,
            'contribution_deadline' => $group_settings->contribution_deadline

        ];
        return $response;
    }

    # current User
    public function actionCurrentUser(){
        $user_id = $this->user_id;
        $user = User::findOne(['id' => $user_id]);

        if (empty($user)) throw new HttpException(255, 'User not found', 5);

        $response = [
            'success' => true,
            'code' => 0,
            'user_id' => $user->id,
            'name' => $user->name,
            'profile_picture' => $user->profile_picture
        ];
        return $response;
    }

    # Contribution Schedule Details
    public function actionContributionScheduleDetails(){
        $group_id = Yii::$app->request->post('group_id');
        if (empty($group_id)) throw new HttpException(255, 'Group ID is required', 01);

        $user_id = $this->user_id;

        //check if group exists
        $group = Groups::find()->where(['id' => $group_id])->one();
        if (empty($group)) throw new HttpException(255, 'Group not found', 5);

        //check if group is an event
        if ($group->type == 'Event') throw new HttpException(255, 'Group is an event', 13);


        //check if user is a Member of the group
        $group_Member = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $user_id])->one();
        if (empty($group_Member)) throw new HttpException(255, 'You are not a Member of this group', 13);

        //check if the group has a contribution schedule
        $contribution_schedule = Contributions::find()->where(['group_id' => $group_id])->one();
        if (empty($contribution_schedule)) {
            $response = [
                'success' => true,
                'code' => 0,
                'group_name' => $group->name,
                'message' => 'No contribution schedule found',
            ];
            return $response;
        }

        $contributions = Contributions::find()
        ->select(['contributions.*', 'groups.name as group_name'])
        ->leftJoin('groups', 'groups.id = contributions.group_id')
        ->where(['contributions.group_id' => $group_id])
        ->asArray()
        ->all();

        $response = [
            'success' => true,
            'code' => 0,
            'contribution_schedule' => $contributions,

        ];

        return $response;
    }
    public function actionPayoutSchedule(){
        $group_id = Yii::$app->request->post('group_id');
        if (empty($group_id)) throw new HttpException(255, 'Group ID is required', 01);

        $user_id = $this->user_id;

        //check if group exists
        $group = Groups::find()->where(['id' => $group_id])->one();
        if (empty($group)) throw new HttpException(255, 'Group not found', 5);

        //check if group is an event
        if ($group->type != 'Mchezo') throw new HttpException(255, 'Group is not Mchezo', 13);


        //check if user is a Member of the group
        $group_Member = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $user_id])->one();
        if (empty($group_Member)) throw new HttpException(255, 'You are not a Member of this group', 13);

        if ($group_Member->role == 'Admin'){
            $is_Admin = true;
        }
        else {
            $is_Admin = false;
        }

        //check if the group has a payout schedule setting
        $payout_schedule_setting = PayoutScheduleSetting::find()->where(['group_id' => $group_id])->one();

        //check if the group has a payout schedule setting
        $payout_schedule_setting = PayoutScheduleSetting::find()->where(['group_id' => $group_id])->one();

        $payouts = PayoutSchedule::find()
        ->select(['payout_schedule.*', 'groups.name as group_name', 'users.name as user_name'])
        ->leftJoin('users', 'users.id = payout_schedule.user_id')
        ->leftJoin('groups', 'groups.id = payout_schedule.group_id')
        ->where(['payout_schedule.group_id' => $group_id])
        ->asArray()
        ->all();

        if (empty($payouts)) {
            $response = [
                'success' => true,
                'code' => 0,
                'group_name' => $group->name,
                'payout_schedule_setting' => $payout_schedule_setting,
                'is_Admin' => $is_Admin,
                'group_type' => $group->type,
                'message' => 'No Payout schedule found',

            ];
            return $response;
        }

        $response = [
            'success' => true,
            'code' => 0,
            'payout_schedule' => $payouts,
            'payout_schedule_setting' => $payout_schedule_setting,

        ];

        return $response;
    }

    # Add payout schedule setting
    public function actionAddPayoutScheduleSetting(){
        $group_id = Yii::$app->request->post('group_id');
        if (empty($group_id)) throw new HttpException(255, 'Group ID is required', 01);

        $days_interval = Yii::$app->request->post('days_interval');
        if (empty($days_interval)) throw new HttpException(255, 'Days interval is required', 01);

        $user_id = $this->user_id;

        //check if group exists
        $group = Groups::find()->where(['id' => $group_id])->one();
        if (empty($group)) throw new HttpException(255, 'Group not found', 5);

        //check if group is a mchezo
        if ($group->type != 'Mchezo') throw new HttpException(255, 'Group is not a Mchezo', 13);    

        //check if user is a Member of the group
        $group_Member = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $user_id])->one();
        if (empty($group_Member)) throw new HttpException(255, 'You are not a Member of this group', 13);

        //check if user is an Admin of the group
        if ($group_Member->role != 'Admin') throw new HttpException(255, 'You are not an Admin of this group', 13);

        //check if payout schedule setting already exists
        $payout_schedule_setting = PayoutScheduleSetting::find()->where(['group_id' => $group_id])->one();
        if (!empty($payout_schedule_setting)) {
            $payout_schedule_setting->group_id = $group_id;
            $payout_schedule_setting->days_interval = $days_interval;
            $payout_schedule_setting->updated_at = date('Y-m-d H:i:s');
            if ($payout_schedule_setting->save()){
                $response = [
                    'success' => true,
                    'code' => 0,
                    'message' => 'Payout schedule setting updated successfully',
                ];

                return $response;
            }
            else throw new HttpException(255, 'Payout schedule setting failed', 9);
        }

        $payout_schedule_setting = new PayoutScheduleSetting();
        $payout_schedule_setting->group_id = $group_id;
        $payout_schedule_setting->days_interval = $days_interval;
        $payout_schedule_setting->created_at = date('Y-m-d H:i:s');
        $payout_schedule_setting->updated_at = date('Y-m-d H:i:s');

        if ($payout_schedule_setting->save()){
            $response = [
                'success' => true,
                'message' => 'Payout schedule setting added successfully',
            ];

            return $response;
        }
        else throw new HttpException(255, 'Payout schedule setting failed', 04); 
    }

    # Generate Payout Schedule
    public function actionGeneratePayoutSchedule(){
        $group_id = Yii::$app->request->post('group_id');
        if (empty($group_id)) throw new HttpException(255, 'Group ID is required', 01);

        $user_id = $this->user_id;

        //check if group exists
        $group = Groups::find()->where(['id' => $group_id])->one();
        if (empty($group)) throw new HttpException(255, 'Group not found', 5);

        //check if group is a mchezo
        if ($group->type != 'Mchezo') throw new HttpException(255, 'Group is not a Mchezo', 13);            

        //check if user is a Member of the group
        $group_Member = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $user_id])->one();
        if (empty($group_Member)) throw new HttpException(255, 'You are not a Member of this group', 13);

        //check if user is an Admin of the group
        if ($group_Member->role != 'Admin') throw new HttpException(255, 'You are not an Admin of this group', 13);

        //check if the group has a payout schedule setting
        $payout_schedule_setting = PayoutScheduleSetting::find()->where(['group_id' => $group_id])->one();
        if (empty($payout_schedule_setting)) throw new HttpException(255, 'No payout schedule setting found', 5);

        // Get all active Members sorted by joined_at
        $Members = GroupMembers::find()
            ->where(['group_id' => $group_id])
            ->orderBy(['joined_at' => SORT_ASC])
            ->all();

        // Get all existing payout schedules for this group
        $existingSchedules = PayoutSchedule::find()
            ->where(['group_id' => $group_id])
            ->orderBy(['scheduled_date' => SORT_ASC])
            ->all();

        $existingUserIds = [];
        $lastScheduledDate = null;
        if (!empty($existingSchedules)) {
            foreach ($existingSchedules as $schedule) {
                $existingUserIds[] = $schedule->user_id;
                $lastScheduledDate = $schedule->scheduled_date;
            }
        }
        else{
            //get group contribution schedule
            $contribution_schedule = Contributions::find()->where(['group_id' => $group_id])->one();
            if (empty($contribution_schedule)) throw new HttpException(255, 'No contribution schedule found', 5);

            $constribution_start_date = $contribution_schedule->start_date;
            $lastScheduledDate = $constribution_start_date;
        }

        $interval = (int)$payout_schedule_setting->days_interval;
        $newSchedules = [];
        $startDate = $lastScheduledDate ? date('Y-m-d', strtotime($lastScheduledDate . " +{$interval} days")) : date('Y-m-d');

        foreach ($Members as $Member) {
            if (!in_array($Member->user_id, $existingUserIds)) {
                $schedule = new PayoutSchedule();
                $schedule->group_id = $group_id;
                $schedule->user_id = $Member->user_id;
                $schedule->scheduled_date = $startDate;
                $schedule->created_at = date('Y-m-d H:i:s');
                $schedule->status = 'pending';
                $schedule->updated_at = date('Y-m-d H:i:s');
                if ($schedule->save()) {
                    $newSchedules[] = [
                        'user_id' => $Member->user_id,
                        'scheduled_date' => $startDate
                    ];
                    $startDate = date('Y-m-d', strtotime($startDate . " +{$interval} days"));
                }
            }
        }

        if (empty($newSchedules)) {
            $response = [
                'success' => true,
                'code' => 0,
                'message' => 'No new Members to add to payout schedule.',
            ];
        } else {
            $response = [
                'success' => true,
                'code' => 0,
                'message' => 'Payout schedule generated/updated successfully.',
                'new_schedules' => $newSchedules
            ];
        }
        return $response;
    }

    # Add Payment
    public function actionAddPayment(){
        Yii::error("************************************ PAYMENT DETAILS ***********************************************");
        Yii::error(Yii::$app->request->post());
        Yii::error("****************************************************************************************************");
        $group_id = Yii::$app->request->post('groupId');
        if (empty($group_id)) throw new HttpException(255, 'Group ID is required', 01);

        $amount = Yii::$app->request->post('amount');
        if (empty($amount)) throw new HttpException(255, 'Amount is required', 01);
        $amount = (int)$amount;
        // check if amount is bellow limit
        if ($amount < 1000) throw new HttpException(255, 'Amount must be above 1000', 02);

        $payment_type = Yii::$app->request->post('payment_type');  // can be Buy Share or Contribution
        if (empty($payment_type)) throw new HttpException(255, 'Payment type is required', 01);

        $channel = Yii::$app->request->post('channel');
        if (empty($channel)) throw new HttpException(255, 'Payment method is required', 01);

        $payer_msisdn = Yii::$app->request->post('mobile_number');
        if (empty($payer_msisdn)) throw new HttpException(255, 'Payer msisdn is required', 01);
        //validate mobile 
        $cus_mob = Yii::$app->helper->validateMobile($payer_msisdn);

        if (!$cus_mob['success']) throw new HttpException(255, $cus_mob['message'], 02);

        $payer_msisdn = $cus_mob['cus_mob'];

        $user_id = $this->user_id;
        

        //check if group exists
        $group = Groups::find()->where(['id' => $group_id])->one();
        if (empty($group)) throw new HttpException(255, 'Group not found', 5);

        //check if user is a Member of the group
        $group_Member = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $user_id])->one();
        if (empty($group_Member)) throw new HttpException(255, 'You are not a Member of this group', 13); 

        //if the payment if for buying shares
        //check if group is kikoba
        if ($group->type == 'Kikoba') {
            if ($payment_type == 'Shares'){
                //check if group has shares config
                //get group setting
                $group_setting = $this->getGroupSetting($group_id, $group->type);
                $setting = $group_setting['data'];
                if (empty($setting)) throw new HttpException(255, "the group has not set shares config", 13);

                //$shares_config = SharesConfig::find()->where(['group_id' => $group_id])->one();
                //if (empty($shares_config)) throw new HttpException(255, "the group has not set shares config", 13);

                //check if the buy period has passed
                // $buy_period = $setting->buy_period_end;
                // if (!empty($buy_period)){
                //     if (strtotime($buy_period) < time()) throw new HttpException(255, "the buy period has passed", 13);
                // }

                //get share price
                $shares_price = $setting->shares_value;
                Yii::error("Shares Price: ". $shares_price);

                //get max shares per Member
                $max_shares_per_member = $setting->max_shares_per_member;

                // check shares already bought
                $total_shares_bought = Shares::find()->where(['group_id' => $group_id])->sum('shares_bought') ?? 0;
                Yii::error("Total Shares bought: ". $total_shares_bought);

                // get remaining shares to be bought
                $remaining_shares = 100 - $total_shares_bought;
                Yii::error("Shares Remain: ". $remaining_shares);


                // check if the user has already bought shares
                $user_shares = Shares::find()->where(['group_id' => $group_id, 'member_id' => $this->user_id])->sum('shares_bought')?? 0;

                // check to get the amount of shares remain for Member to buy
                $remaining_shares_for_member = $max_shares_per_member - $user_shares;
                Yii::error("Remain Shares for member: ". $remaining_shares_for_member);


                //get shares to be bought
                $shares_to_be_bought = $amount / $shares_price;
                Yii::error("Shares to be bought: ". $shares_to_be_bought);


                if ($shares_to_be_bought > $remaining_shares_for_member) throw new HttpException(255, 'You have exceeded your max shares per Member remaining shares you can buy is: ' .$remaining_shares_for_member, 13);

                
                // check exceed max shares
                if ($remaining_shares < $shares_to_be_bought) throw new HttpException(255, 'Shares limit exceeded', 13);
            }
        }

        

        // //TODO:: perform push payment
        // $type = $payment_type; 
        // $initiatePush = Yii::$app->helper->pushPayment($payer_msisdn, $amount, $channel, $this->user_id, group_id, $type);

        // if ($initiatePush['success']){
        //     $response = [
        //         'success' => true,
        //         'message' => 'Payment initiated successfully',
        //     ];
        // }
        // else{
        //     $response = [
        //         'success' => false,
        //         'message' => 'Payment failed',
        //     ];
        // }
        // return $response;

        //TODO:: FOR TESTING 
        $trans_ref = 'Test'. time(); 

        $model = new Payments();
        $model->group_id = $group_id;
        $model->user_id = $this->user_id;;
        $model->reference = $trans_ref;
        $model->amount = $amount;
        $model->payment_date = date("Y-m-d H:i:s");
        $model->payment_method = 'Selcom';
        $model->status = 'verified';
        if ($payment_type == 'Shares'){
            $model->payment_for = 'Buying Shares';
        }
        else{
            $model->payment_for = $payment_type;   
        }
        $model->save(false);

        //check if the group is a Event
        if ($group->type == 'Event'){
            //reduce the remaining amount
            $pledges = Pledges::find()->where(['group_id' => $group_id, 'user_id' => $this->user_id])->one();
            if (empty($pledges)) throw new HttpException(255, 'No pledges found', 01);
            $pledges->remain_amount = $pledges->remain_amount - $amount;
            if ($pledges->remain_amount < 0){
                $pledges->status = 'Paid';
            }
            else{
                $pledges->status = 'Partial Paid';
            }
            $pledges->paid_amount = $pledges->paid_amount + $amount;
            $pledges->paid_at = $model->payment_date;
            if ($pledges->save()){
                //genereate notification
                $payer_id = $this->user_id;
                $payer_details = User::find()->where(['id' => $payer_id])->one();
                //get group owner package details
                $package  = Yii::$app->helper->getPackagebyGroupID($group_id);
                $params = [
                    'user_id' => $this->user_id,
                    'user_name' => $payer_details->name,
                    'group_name' => $group->name,
                    'group_type'=> $group->type,
                    'group_id' => $group->id,
                    'amount' => $amount,
                    'lang' => $payer_details->language,
                    'type' => $package->notification_support,
                    'process' => 'approvedPayment',
                ];
    
                $record_notification = Helper::generateNotification($params);
                if ($record_notification){
                    $response = [
                        'success' => true,
                        'code' => 0,
                        'message' => 'Payment Completed successfully',
                    ];
                }
                else{
                    $response = [
                        'success' => true,
                        'code' => 0,
                        'message' => 'Payment Completed  successfully failed to generete notification',
                    ];
                }
                
            }
            else throw new HttpException(255, 'Failed to update pledges', 9);

            return $response;
        }
        else if ($group->type == 'Kikoba'){
            if ($payment_type == 'Contribution'){
                $payment = $amount;
                // Fetch unpaid contribution schema records ordered by due_date
                $contributions = ContributionSchedule::find()
                    ->where(['user_id' => $this->user_id, 'group_id' => $group_id])
                    ->andWhere(['is_paid' => 0])
                    ->orderBy(['due_date' => SORT_ASC])
                    ->all();

                foreach ($contributions as $contribution) {
                    if ($payment <= 0) {
                        break;
                    }

                    $remaining = $contribution->remain_amount;

                    if ($payment >= $remaining) {
                        // Full payment for this record
                        $contribution->paid_amount += $remaining;
                        $contribution->remain_amount = 0;
                        $contribution->is_paid = 1;
                        $contribution->paid_at = date('Y-m-d H:i:s');
                        $payment -= $remaining;
                    } else {
                        // Partial payment
                        $contribution->paid_amount += $payment;
                        $contribution->remain_amount -= $payment;
                        $payment = 0;
                    }

                    $contribution->save(false); 
                }
                //genereate notification
                $payer_id = $this->user_id;
                $payer_details = User::find()->where(['id' => $payer_id])->one();
                //get group owner package details
                $package  = Yii::$app->helper->getPackagebyGroupID($group_id);
                $params = [
                    'user_id' => $this->user_id,
                    'user_name' => $payer_details->name,
                    'group_name' => $group->name,
                    'group_type'=> $group->type,
                    'group_id' => $group->id,
                    'amount' => $amount,
                    'lang' => $payer_details->language,
                    'type' => $package->notification_support,
                    'process' => 'approvedPayment',
                ];
    
                $record_notification = Helper::generateNotification($params);
                if ($record_notification){
                    $response = [
                        'success' => true,
                        'code' => 0,
                        'message' => 'Payment Completed successfully',
                    ];
                }
                else{
                    $response = [
                        'success' => true,
                        'code' => 0,
                        'message' => 'Payment Completed  successfully failed to generete notification',
                    ];
                }
            }
            else if ($payment_type == 'Shares'){
                $model = new Shares();
                $model->group_id = $group_id;
                $model->member_id = $this->user_id;
                $model->amount_paid = $amount;
                $model->shares_bought = $shares_to_be_bought;
                $model->bought_at = date('Y-m-d H:i:s');
                $model->save(false);

                //generate notification
                $login_user_details = User::find()->where(['id' => $this->user_id])->one();
                //get group owner package details
                $package  = Yii::$app->helper->getPackagebyGroupID($group_id);
                if ($package['success']) {
                    $params = [
                        'user_id' => $this->user_id,
                        'user_name' => $this->login_user_name,
                        'lang' => $login_user_details->language,
                        'type' => $package->notification_support,
                        'group_id' => $group_id,
                        'group_type' => 'Kikoba',
                        'group_name' => $group->name,
                        'process' => 'Buy Shares',
                    ];

                    $record_notification = Helper::generateNotification($params);

                    if ($record_notification){
                        $response = [
                            'success' => true,
                            'code' => 0,
                            'message' => 'Payment Completed successfully',
                        ];
                    }
                    else{
                        $response = [
                            'success' => true,
                            'code' => 0,
                            'message' => 'Payment Completed  successfully failed to generete notification',
                        ];
                    }
                }
            }
        }
        else if ($group->type == 'Mchezo'){
            if ($payment_type == 'Contribution'){
                $payment = $amount;
                // Fetch unpaid contribution schema records ordered by due_date
                $contributions = ContributionSchedule::find()
                    ->where(['user_id' => $this->user_id, 'group_id' => $group_id])
                    ->andWhere(['is_paid' => 0])
                    ->orderBy(['due_date' => SORT_ASC])
                    ->all();

                foreach ($contributions as $contribution) {
                    if ($payment <= 0) {
                        break;
                    }

                    $remaining = $contribution->remain_amount;

                    if ($payment >= $remaining) {
                        // Full payment for this record
                        $contribution->paid_amount += $remaining;
                        $contribution->remain_amount = 0;
                        $contribution->is_paid = 1;
                        $contribution->paid_at = date('Y-m-d H:i:s');
                        $payment -= $remaining;
                    } else {
                        // Partial payment
                        $contribution->paid_amount += $payment;
                        $contribution->remain_amount -= $payment;
                        $payment = 0;
                    }

                    $contribution->save(false); 
                }
                //genereate notification
                $payer_id = $this->user_id;
                $payer_details = User::find()->where(['id' => $payer_id])->one();
                //get group owner package details
                $package  = Yii::$app->helper->getPackagebyGroupID($group_id);
                $params = [
                    'user_id' => $this->user_id,
                    'user_name' => $payer_details->name,
                    'group_name' => $group->name,
                    'group_type'=> $group->type,
                    'group_id' => $group->id,
                    'amount' => $amount,
                    'lang' => $payer_details->language,
                    'type' => $package->notification_support,
                    'process' => 'approvedPayment',
                ];
    
                $record_notification = Helper::generateNotification($params);
                if ($record_notification){
                    $response = [
                        'success' => true,
                        'code' => 0,
                        'message' => 'Payment Completed successfully',
                    ];
                }
                else{
                    $response = [
                        'success' => true,
                        'code' => 0,
                        'message' => 'Payment Completed  successfully failed to generete notification',
                    ];
                }
            }
        }
        else if ($group->type == 'Ujamaa'){
            if ($payment_type == 'Contribution'){
                $payment = $amount;
                // Fetch unpaid contribution schema records ordered by due_date
                $contributions = ContributionSchedule::find()
                    ->where(['user_id' => $this->user_id, 'group_id' => $group_id])
                    ->andWhere(['is_paid' => 0])
                    ->orderBy(['due_date' => SORT_ASC])
                    ->all();

                foreach ($contributions as $contribution) {
                    if ($payment <= 0) {
                        break;
                    }

                    $remaining = $contribution->remain_amount;

                    if ($payment >= $remaining) {
                        // Full payment for this record
                        $contribution->paid_amount += $remaining;
                        $contribution->remain_amount = 0;
                        $contribution->is_paid = 1;
                        $contribution->paid_at = date('Y-m-d H:i:s');
                        $payment -= $remaining;
                    } else {
                        // Partial payment
                        $contribution->paid_amount += $payment;
                        $contribution->remain_amount -= $payment;
                        $payment = 0;
                    }

                    $contribution->save(false); 
                }
                //genereate notification
                $payer_id = $this->user_id;
                $payer_details = User::find()->where(['id' => $payer_id])->one();
                //get group owner package details
                $package  = Yii::$app->helper->getPackagebyGroupID($group_id);
                $params = [
                    'user_id' => $this->user_id,
                    'user_name' => $payer_details->name,
                    'group_name' => $group->name,
                    'group_type'=> $group->type,
                    'group_id' => $group->id,
                    'amount' => $amount,
                    'lang' => $payer_details->language,
                    'type' => $package->notification_support,
                    'process' => 'approvedPayment',
                ];
    
                $record_notification = Helper::generateNotification($params);
                if ($record_notification){
                    $response = [
                        'success' => true,
                        'code' => 0,
                        'message' => 'Payment Completed successfully',
                    ];
                }
                else{
                    $response = [
                        'success' => true,
                        'code' => 0,
                        'message' => 'Payment Completed  successfully failed to generete notification',
                    ];
                }
            }
        }

        return $response;       
    }

    # Verify Payment
    public function actionVerifyPayment(){
        $reference = Yii::$app->request->post('reference');
        if (empty($reference)) throw new HttpException(255, 'Payment Reference is required', 01);

        $status = Yii::$app->request->post('status');
        if (empty($status)) throw new HttpException(255, 'Payment Status is required', 01);

        $reason = Yii::$app->request->post('reason');
        if ($status == 'rejected'){
            if (empty($reason)) throw new HttpException(255, 'Payment Reason is required', 01);
        }

        //check if the status is verified or rejected
        if ($status != 'verified' && $status != 'rejected') throw new HttpException(255, 'Invalid payment status', 16);

        $payment = Payments::find()->where(['reference' => $reference])->one();
        if (empty($payment)) throw new HttpException(255, 'Payment not found', 5);

        $payment_amount = $payment->amount;

        $user_id = $this->user_id;

        $group = Groups::find()->where(['id' => $payment->group_id])->one();
        if (empty($group)) throw new HttpException(255, 'Group not found', 5);

        //check if user is an Treasurer of the group
        $group_Member = GroupMembers::find()->where(['group_id' => $payment->group_id, 'user_id' => $user_id])->one();
        if (empty($group_Member)) throw new HttpException(255, 'You are not a Member of this group', 13);

        if ($group_Member->role != 'Treasurer') throw new HttpException(255, 'You are not a Treasurer of this group', 13);
        $payment->verified_by = $user_id;
        $payment->verified_at = date('Y-m-d H:i:s');
        $payment->status = $status;
        if ($status == 'rejected'){
            $payment->rejection_reason = $reason;
        }
        if($payment->save()){
            //check if the group is a Event
            if ($group->type == 'Event' && $status == 'verified'){
                //reduce the remaining amount
                $pledges = Pledges::find()->where(['group_id' => $payment->group_id, 'user_id' => $payment->user_id])->one();
                if (empty($pledges)) throw new HttpException(255, 'No pledges found', 01);
                $pledges->remain_amount = $pledges->remain_amount - $payment_amount;
                if ($pledges->remain_amount < 0){
                    $pledges->status = 'Full Paid';
                }
                else{
                    $pledges->status = 'Partially Paid';
                }
                $pledges->paid_amount = $pledges->paid_amount + $payment_amount;
                $pledges->paid_at = $payment->payment_date;
                if ($pledges->save()){
                    //genereate notification
                    $payer_id = $payment->user_id;
                    $payer_details = User::find()->where(['id' => $payer_id])->one();
                    if (empty($payer_details)) throw new HttpException(255, 'User not found', 5);
                    $login_user_details = User::find()->where(['id' => $this->user_id])->one();
                    if (empty($login_user_details)) throw new HttpException(255, 'User not Found', 5);
                    $package = Packages::find()->where(['id' => $this->package_id])->one();
                    if (empty($package)) throw new HttpException(255, 'Package not found', 5);
                    $params = [
                        'user_id' => $this->user_id,
                        'user_name' => $payer_details->name,
                        'group_name' => $group->name,
                        'group_type'=> $group->type,
                        'group_id' => $group->id,
                        'amount' => $payment_amount,
                        'lang' => $login_user_details->language,
                        'type' => $package->notification_support,
                        'process' => 'approvedPayment',
                    ];
        
                    $record_notification = Helper::generateNotification($params);
                    if ($record_notification){
                        $response = [
                            'success' => true,
                            'code' => 0,
                            'message' => 'Payment' .$status. ' successfully',
                        ];
                    }
                    else{
                        $response = [
                            'success' => true,
                            'code' => 0,
                            'message' => 'Payment' .$status. ' successfully failed to generete notification',
                        ];
                    }
                    
                }
                else throw new HttpException(255, 'Failed to update pledges', 9);

                return $response;
            }

            $response = [
                'success' => true,
                'code' => 0,
                'message' => 'Payment verified successfully',
            ];

            return $response;
        }
        else throw new HttpException(255, 'Failed to verify payment', 9);

    }

    #My Payments
    public function actionMyPayments(){
        
        $user_id = $this->user_id;

        $payments = Payments::find()
            ->select(['payments.*', 'groups.name as group_name'])
            ->leftJoin('groups', 'groups.id = payments.group_id')
            ->where(['user_id' => $user_id])
            ->orderBy(['payment_date' => SORT_DESC]) // Order by payment_date DESC
            ->asArray()
            ->all();

        $groups = GroupMembers::find()->where(['user_id' => $this->user_id])->all();
        if (empty($groups)){
            $response = [
                'success' => true,
                'code' => 0,
                'message' => 'Payments fetched successfully',
                'data' => $payments,
                'my_groups' => $groups
            ];
    
        }
        else{
            $group_ids = array_column($groups, 'group_id');
            $groups = Groups::find()
                ->select(['groups.*', 'users.name as creator_name'])
                ->leftJoin('users', 'users.id = groups.created_by')
                ->where(['groups.id' => $group_ids])
                ->orderBy(['groups.created_at' => SORT_DESC])
                ->asArray()
                ->all();
                
                $response = [
                    'success' => true,
                    'code' => 0,
                    'message' => 'Payments fetched successfully',
                    'data' => $payments,
                    'my_groups' => $groups
                ];
        }

        return $response;
    }

    # Get User Payments
    public function getUserPayments($user_id)
    {

        $groups = GroupMembers::find()
            ->select(['groups.id as group_id', 'groups.name as group_name', 'groups.type as group_type'])
            ->leftJoin('groups', 'groups.id = group_members.group_id')
            ->where(['group_members.user_id' => $user_id])
            ->asArray() // <-- this line solves your issue
            ->all();


        $result = [];

        foreach ($groups as $group) {
            $groupId = $group['group_id'];
            $groupType = $group['group_type'];

            $paidAmount = Payments::find()
                ->where(['user_id' => $user_id, 'group_id' => $groupId])
                ->andWhere(['status' => 'verified'])
                ->sum('amount') ?? 0;
            
            $incomingPayment = Payments::find()
                ->where(['group_id' => $groupId])
                ->andWhere(['status' =>'verified'])
                ->sum('amount')?? 0;
            $outgoingPayment = OutgoingPayment::find()
                ->where(['group_id' => $groupId])
                ->andWhere(['status' =>'Approved'])
                ->sum('amount')?? 0;
            $balance = $incomingPayment - $outgoingPayment;    

            $expectedAmount = 0;
            $outstanding = 0;
            $incomingAmount = $incomingPayment;
            $outgoingAmount = $outgoingPayment;
            $currentBalance = $balance;

            if (in_array($groupType, ['Kikoba', 'Mchezo', 'Ujamaa'])) {
                $today = date('Y-m-d');
            
                // 1. Total expected contributions up to today (based on schedule)
                $expectedAmount = ContributionSchedule::find()
                    ->where([
                        'group_id' => $groupId,
                    ])
                    ->andWhere(['<=', 'due_date', $today])
                    ->sum('amount') ?? 0;
            
                // 2. Total paid by the user in this group
                $paidAmount = Payments::find()
                    ->where(['user_id' => $user_id, 'group_id' => $groupId])
                    ->andWhere(['status' => 'verified'])
                    ->sum('amount') ?? 0;
            
                $outstanding = max($expectedAmount - $paidAmount, 0);
            } elseif ($groupType === 'Event') {
                // get pledged amount from pledge table
                $expectedAmount = Pledges::find()
                    ->where(['group_id' => $groupId, 'user_id' => $user_id])
                    ->sum('amount') ?? 0;
            }

            $outstanding = $expectedAmount - $paidAmount;

            $result[] = [
                'group_id' => $groupId,
                'group_name' => $group['group_name'],
                'group_type' => $groupType,
                'incoming_amount' => $incomingAmount,
                'outgoing_amount' => $outgoingAmount,
                'current_balance' => $currentBalance,
                'expected_amount' => $expectedAmount,
                'paid_amount' => $paidAmount,
                'outstanding' => max($outstanding, 0),
            ];
        }

        return [
            'status' => 'success',
            'code' => 0,
            'data' => $result,
        ];
    }

    # Group Payment
    public function actionGroupPayment(){
        $group_id = Yii::$app->request->post('group_id');
        if (empty($group_id)) throw new HttpException(255, 'Group ID is required', 01);

        $user_id = $this->user_id;

        $group = Groups::find()->where(['id' => $group_id])->one();
        if (empty($group)) throw new HttpException(255, 'Group not found', 5);

        $group_Member = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $user_id])->one();
        if (empty($group_Member)) throw new HttpException(255, 'You are not a Member of this group', 13);


        $incoming_payments = Payments::find()
            ->select(['payments.*', 'groups.name as group_name', 'users.name as user_name'])
            ->leftJoin('groups', 'groups.id = payments.group_id')
            ->leftJoin('users', 'users.id = payments.user_id')
            ->where(['group_id' => $group_id])
            ->orderBy(['payment_date' => SORT_DESC]) // Order by payment_date DESC
            ->asArray()
            ->all();
        
        $outgoing_payments = OutgoingPayment::find()
            ->select(['outgoing_payment.*', 'groups.name as group_name', 'users.name as user_name'])
            ->leftJoin('groups', 'groups.id = outgoing_payment.group_id')
            ->leftJoin('users', 'users.id = outgoing_payment.recipient_id')
            ->where(['group_id' => $group_id])
            ->orderBy(['created_at' => SORT_DESC]) // Order by payment_date DESC
            ->asArray()
            ->all();    

        $response = [
            'success' => true,
            'code' => 0,
            'message' => 'Payments fetched successfully',
            'user_role' => $group_Member->role,
            'data' => [
                'incoming_payments' => $incoming_payments,
                'outgoing_payments' => $outgoing_payments
            ]
        ];

        return $response;
    }

    # Approve Payment Request level 1
    public function actionApprovePaymentRequest(){
       $payment_id = Yii::$app->request->post('id');
       if (empty($payment_id)) throw new HttpException(255, 'Payment ID is required', 01);
       
       $status = Yii::$app->request->post('status');
       if (empty($status)) throw new HttpException(255, 'status is required', 01);

       //check if the status is verified or rejected
       if ($status != 'verified' && $status != 'rejected') throw new HttpException(255, 'Invalid payment status', 16);

       $payment = OutgoingPayment::find()->where(['id' => $payment_id])->one();
        if (empty($payment)) throw new HttpException(255, 'Payment not found', 5);

        $user_id = $this->user_id;

        $group = Groups::find()->where(['id' => $payment->group_id])->one();
        if (empty($group)) throw new HttpException(255, 'Group not found', 5);

        //check if user is an Treasurer of the group
        $group_Member = GroupMembers::find()->where(['group_id' => $payment->group_id, 'user_id' => $user_id])->one();
        if (empty($group_Member)) throw new HttpException(255, 'You are not a Member of this group', 13);

        if ($group_Member->role != 'Treasurer') throw new HttpException(255, 'You are not a Treasurer of this group', 13);
        $payment->approver_one = 1; //setting to true
        $payment->updated_at = date('Y-m-d H:i:s');
        if ($status =='verified'){
            $payment->status = 'Preapproved';
        }
        else{
            $payment->status = $status;
        }
        if($payment->save()){

            if ($status == 'verified'){
                $current_user = User::find()->where(['id' => $this->user_id])->one();
                $group_owner = User::find()->where(['id' => $group->created_by])->one();
                $package = Packages::find()->where(['id' => $group_owner->package_id])->one();
                //genereate notification
                $params = [
                    'user_id' => $this->user_id,
                    'user_name' => $current_user->name,
                    'group_name' => $group->name,
                    'amount'=> $payment->amount,
                    'reason' => $payment->reason,
                    'group_id' => $group->id,
                    'role' => $group_Member->role,
                    'lang' => $group_owner->language,
                    'type' => $package->notification_support,
                    'process' => 'Withdraw Approval',
                ];

                $record_notification = Helper::generateNotification($params);
            }
            else if ($status =='rejected'){
                $current_user = User::find()->where(['id' => $this->user_id])->one();
                $group_owner = User::find()->where(['id' => $group->created_by])->one();
                $package = Packages::find()->where(['id' => $group_owner->package_id])->one();
                //genereate notification
                $params = [
                    'user_id' => $this->user_id,
                    'user_name' => $current_user->name,
                    'group_name' => $group->name,
                    'amount'=> $payment->amount,
                    'reason' => $payment->reason,
                    'group_id' => $group->id,
                    'role' => $group_Member->role,
                    'lang' => $group_owner->language,
                    'type' => $package->notification_support,
                    'process' => 'Withdraw Rejected',
                ];

                $record_notification = Helper::generateNotification($params);   
            }

            $response = [
               'success' => true,
                'code' => 0,
               'message' => 'Payment'.$status.'successfully',
            ];

            return $response;
        }
        else{
            Yii::error("*************************************** Error Update  Outgoing Payment Request ************************");
            Yii::error(json_encode($payment->errors));
            Yii::error("*************************************** end Error updating Outgoing Payment Request ************************");
            throw new HttpException(255, 'Failed to verify payment', 9);
        }
    }

    # Approve Payment Request level 2
    public function actionApprovePaymentRequestLevel2(){
        $payment_id = Yii::$app->request->post('id');
        if (empty($payment_id)) throw new HttpException(255, 'Payment ID is required', 01);

        $status = Yii::$app->request->post('status');
        if (empty($status)) throw new HttpException(255,'status is required', 01);

        //check if the status is verified or rejected
        if ($status!='approved' && $status!='rejected') throw new HttpException(255, 'Invalid payment status', 16);

        $payment = OutgoingPayment::find()->where(['id' => $payment_id])->one();
        if (empty($payment)) throw new HttpException(255, 'Payment not found', 5);
        
        $user_id = $this->user_id;

        $group = Groups::find()->where(['id' => $payment->group_id])->one();
        if (empty($group)) throw new HttpException(255, 'Group not found', 5);

        //check if user is an Chairperson of the group
        $group_Member = GroupMembers::find()->where(['group_id' => $payment->group_id, 'user_id' => $user_id])->one();
        if (empty($group_Member)) throw new HttpException(255, 'You are not a Member of this group', 13);

        if ($group_Member->role!= 'Chairperson') throw new HttpException(255, 'You are not a Chairperson of this group', 13);

        $payment->approver_two = 1; //setting to true
        $payment->updated_at = date('Y-m-d H:i:s');
        if ($status =='approved'){
            $payment->status = "Approved"; //to be used by engine to make transfer
        }
        else {
            $payment->status = $status;
        }
        if($payment->save()){
            if ($status == 'approved'){

                $current_user = User::find()->where(['id' => $this->user_id])->one();
                $group_owner = User::find()->where(['id' => $group->created_by])->one();
                $package = Packages::find()->where(['id' => $group_owner->package_id])->one();
                //genereate notification
                $params = [
                    'user_id' => $this->user_id,
                    'user_name' => $current_user->name,
                    'group_name' => $group->name,
                    'amount'=> $payment->amount,
                    'reason' => $payment->reason,
                    'group_id' => $group->id,
                    'role' => $group_Member->role,
                    'lang' => $group_owner->language,
                    'type' => $package->notification_support,
                    'process' => 'Withdraw Final Approval',
                ];

                $record_notification = Helper::generateNotification($params);

            }
            else if ($status =='rejected'){
                $current_user = User::find()->where(['id' => $this->user_id])->one();
                $group_owner = User::find()->where(['id' => $group->created_by])->one();
                $package = Packages::find()->where(['id' => $group_owner->package_id])->one();
                //genereate notification
                $params = [
                    'user_id' => $this->user_id,
                    'user_name' => $current_user->name,
                    'group_name' => $group->name,
                    'amount'=> $payment->amount,
                    'reason' => $payment->reason,
                    'group_id' => $group->id,
                    'role' => $group_Member->role,
                    'lang' => $group_owner->language,
                    'type' => $package->notification_support,
                    'process' => 'Withdraw Rejected',
                ];

                $record_notification = Helper::generateNotification($params);   
            }

            $response = [
               'success' => true,
                'code' => 0,
               'message' => 'Payment'.$status.'successfully',
            ];

            return $response;
        }
    }

    # Payment schedule
    public function actionPaymentSchedule(){
        $user_id = $this->user_id;

        $contribution_schedules = ContributionSchedule::find()
            ->select(['contribution_schedule.*', 'groups.name as group_name'])
            ->leftJoin('groups', 'groups.id = contribution_schedule.group_id')
            ->where(['user_id' => $user_id])
            ->andWhere(['is_paid' => false])
            ->orderBy(['due_date' => SORT_ASC])
            //->groupBy(['group_id', 'contribution_schedule.id', 'contribution_schedule.user_id', 'contribution_schedule.amount', 'contribution_schedule.due_date', 'contribution_schedule.round_number', 'contribution_schedule.is_paid', 'contribution_schedule.paid_at', 'groups.name'])
            ->asArray()
            ->all();

        $response = [
            'success' => true,
            'code' => 0,
            'message' => 'Payment schedule fetched successfully',
            'data' => $contribution_schedules
        ];

        return $response;
    }
    # Payment schedule
    public function actionMyContributionSchedule(){
        $user_id = $this->user_id;

        $contribution_schedules = ContributionSchedule::find()
            ->select(['contribution_schedule.*', 'groups.name as group_name'])
            ->leftJoin('groups', 'groups.id = contribution_schedule.group_id')
            ->where(['user_id' => $user_id])
            ->orderBy(['due_date' => SORT_ASC])
            //->groupBy(['group_id', 'contribution_schedule.id', 'contribution_schedule.user_id', 'contribution_schedule.amount', 'contribution_schedule.due_date', 'contribution_schedule.round_number', 'contribution_schedule.is_paid', 'contribution_schedule.paid_at', 'groups.name'])
            ->asArray()
            ->all();

        $response = [
            'success' => true,
            'code' => 0,
            'message' => 'Contribution schedule fetched successfully',
            'data' => $contribution_schedules
        ];

        return $response;
    }


    # Change Password
    public function actionChangePassword(){
        // Yii::error("************************* CHANGE PASSWORD ************************************");
        // Yii::error(Yii::$app->request->post());
        // Yii::error("**********************************************************************************");
        $old_password = Yii::$app->request->post('oldPassword');
        if (empty($old_password)) throw new HttpException(255, 'Current Password is required', 01);
        $new_password = Yii::$app->request->post('newPassword');
        if (empty($new_password)) throw new HttpException(255, 'New Password is required', 01);
        $confirm_password = Yii::$app->request->post('confirmPassword');
        if (empty($confirm_password)) throw new HttpException(255, 'Confirm Password is required', 01);

        //get this user details
        $user = User::find()->where(['id' => $this->user_id])->one();
        if (empty($user)) throw new HttpException(255, 'User not Found', 5);

        //validate old password
        if (Yii::$app->getSecurity()->validatePassword($old_password, $user['password_hash'])) {

            //check if the passwords match
            if ($new_password == $confirm_password) {
                $hash_pass = Yii::$app->getSecurity()->generatePasswordHash($new_password);

                //check if the new password equals to old password
                if (Yii::$app->getSecurity()->validatePassword($new_password, $user['password_hash'])) throw new HttpException(255, 'You have used this password before', 11);

                $user->password_hash = $hash_pass;
                if ($user->save()) {

                    //genereate notification
                    $login_user_details = User::find()->where(['id' => $this->user_id])->one();
                    if (empty($login_user_details)) throw new HttpException(255, 'User not Found', 5);
                    $package = Packages::find()->where(['id' => $this->package_id])->one();
                    if (empty($package)) throw new HttpException(255, 'Package not found', 5);
                    $params = [
                        'user_id' => $this->user_id,
                        'user_name' => $this->login_user_name,
                        'lang' => $login_user_details->language,
                        'type' => $package->notification_support,
                        'process' => 'changePassword',
                    ];
        
                    $record_notification = Helper::generateNotification($params);
                    if ($record_notification){
                    }
                    $response = [
                        'success' => true,
                        'code' => 0,
                        'message' => 'Password Changed successful'
                    ];
                }
                else{
                    Yii::error("*************************************** Error updating Password ************************");
                    Yii::error(json_encode($user->errors));
                    Yii::error("*************************************** end Error updating Password ************************");
                    throw new HttpException(255, 'Failed to Change Password', 9);
                }

                return $response;
            } else throw new HttpException(255, 'Password does not match', 10);
            
        } else throw new HttpException(255, 'Wrong Current Password', 19);
        
    }

    # My Notifications
    public function actionNotifications(){

        $my_notifiation = NotificationRecipient::find()
        ->select(['notification_recipient.*', 'notifications.title', 'notifications.message'])
        ->leftJoin('notifications', 'notifications.id = notification_recipient.notification_id')
        ->where(['notification_recipient.user_id' => $this->user_id])
        ->orderBy(['created_at' => SORT_DESC])
        ->asArray()
        ->all();

        $response = [
            'success' =>true,
            'code' => 0,
            'notificaton' => $my_notifiation
        ];

        return $response;
    }

    # Mark Notification as read
    public function actionNotificationRead(){
        // Yii::error("************************* MARK NOTIFICATION AS READ ************************************");
        // Yii::error(Yii::$app->request->post());
        // Yii::error("**********************************************************************************");

        $request = Yii::$app->request->post();
        $notificationIds = [];
    
        // Support both single and multiple IDs
        if (!empty($request['notification_id'])) {
            $notificationIds = [(int)$request['notification_id']];
        } elseif (!empty($request['notification_ids']) && is_array($request['notification_ids'])) {
            $notificationIds = array_map('intval', $request['notification_ids']);
        } else {
            throw new HttpException(400, 'Notification ID(s) are required');
        }
    
        $notifications = NotificationRecipient::find()->where(['id' => $notificationIds])->all();
    
        if (empty($notifications)) {
            throw new HttpException(404, 'No notifications found for the given ID(s)');
        }
    
        $updated = 0;
        foreach ($notifications as $notification) {
            $notification->read_at = date('Y-m-d H:i:s');
            if ($notification->save()) {
                $updated++;
            }
        }
    
        return [
            'success' => true,
            'code' => 0,
            'message' => "$updated notification(s) marked as read"
        ];   
    }

    public function actionMarkAllNotificationRead(){
        $notifications = NotificationRecipient::find()->where(['user_id' => $this->user_id])->all();
    
        if (empty($notifications)) {
            throw new HttpException(404, 'No notifications found for the given ID(s)');
        }
    
        $updated = 0;
        foreach ($notifications as $notification) {
            $notification->read_at = date('Y-m-d H:i:s');
            if ($notification->save()) {
                $updated++;
            }
        }
    
        return [
            'success' => true,
            'code' => 0,
            'message' => "$updated notification(s) marked as read"
        ];   
    }

    # GET UNREAD NOTIFICATIONS
    public function actionUnreadNotifications(){
        $notification_count = NotificationRecipient::find()->where(['user_id' => $this->user_id , 'read_at' => null])->count();

        $response = [
            'success' => true,
            'code' => 0,
            'notification_count' => $notification_count
        ];

        return $response;
    }

    # Add Configure Shares
    public function actionConfigureShares(){
        $group_id = Yii::$app->request->post('group_id');
        if (empty($group_id)) throw new HttpException(255, 'Group id is required', 01);

        $share_price = Yii::$app->request->post('share_price');
        if (empty($share_price)) throw new HttpException(255, 'Share price is required', 01);

        $max_shares_per_Member = Yii::$app->request->post('max_shares_per_Member');
        $allow_selling = Yii::$app->request->post('allow_selling');  // can be 0 or 1
        if (empty($allow_selling)) {
            $allow_selling = 0;
        }
        else if (!in_array($allow_selling, [0,1])) throw new HttpException(255, 'Allow selling is invalid', 01);
        $buy_period_start = Yii::$app->request->post('buy_period_start');
        if (empty($buy_period_start)) throw new HttpException(255, 'Buy period start is required', 01);
        $buy_period_end = Yii::$app->request->post('buy_period_end');

        //find the group
        $group = Groups::find()->where(['id'=> $group_id])->one();
        if (empty($group)) throw new HttpException(255, 'Group not found', 5);

        //check if the group is kikoba
        if ($group->type != 'Kikoba') throw new HttpException(255, 'Group is not kikoba', 13);

        //check if the current user is group Admin
        $group_Member = GroupMembers::find()->where(['group_id'=> $group_id, 'user_id'=> $this->user_id])->one();
        if (empty($group_Member)) throw new HttpException(255, 'You are not a Member of this group', 13);

        if ($group_Member->role!= 'Admin') throw new HttpException(255, 'You are not an Admin of this group', 13);

        $model = SharesConfig::find()->where(['group_id'=> $group_id])->one();
        if (!empty($model)) throw new HttpException(255, 'Shares already configured', 13);

        if (empty($model)) {
            $model = new SharesConfig();
            $model->group_id = $group_id;
            $model->share_price = $share_price;
            $model->max_shares_per_Member = $max_shares_per_Member;
            $model->allow_selling = $allow_selling;
            $model->buy_period_start = $buy_period_start;
            $model->buy_period_end = $buy_period_end;
            if ($model->save()) {
                $response = [
                   'success'=> true,
                    'code'=> 200,
                   'message'=> 'Values saved successful'
                ]; 

                return $response;
            }
            else {
                Yii::error("*************************************** Error saving Shares ************************");
                Yii::error(json_encode($model->errors));
                Yii::error("*************************************** end Error saving Shares ************************");
                throw new HttpException(255, 'Failed to record Share value', 04);
            }
        }   
    }

    # Update Shares config
    public function actionUpdateSharesConfig(){
        $group_id = Yii::$app->request->post('group_id');
        if (empty($group_id)) throw new HttpException(255, 'Group id is required', 01);
        $share_price = Yii::$app->request->post('share_price');
        $max_shares_per_Member = Yii::$app->request->post('max_shares_per_Member');
        $allow_selling = Yii::$app->request->post('allow_selling');  // can be 0 or 1
        $buy_period_start = Yii::$app->request->post('buy_period_start');
        $buy_period_end = Yii::$app->request->post('buy_period_end');

        //check if any field has been passed in the request
        if (empty($share_price) && empty($max_shares_per_Member) && empty($allow_selling) && empty($buy_period_start) && empty($buy_period_end)) throw new HttpException(255, 'No fields passed', 01);

        //find the group
        $group = Groups::find()->where(['id'=> $group_id])->one();
        if (empty($group)) throw new HttpException(255, 'Group not found', 5);

        //check if the group is kikoba
        if ($group->type!= 'Kikoba') throw new HttpException(255, 'Group is not kikoba', 13);


        //check if the current user is group Admin
        $group_Member = GroupMembers::find()->where(['group_id'=> $group_id, 'user_id'=> $this->user_id])->one();
        if (empty($group_Member)) throw new HttpException(255, 'You are not a Member of this group', 13);

        if ($group_Member->role!= 'Admin') throw new HttpException(255, 'You are not an Admin of this group', 13);

        $model = SharesConfig::find()->where(['group_id'=> $group_id])->one();
        if (empty($model)) throw new HttpException(255, 'Shares not configured', 13);

        if (!empty($share_price)) $model->share_price = $share_price;
        if (!empty($max_shares_per_Member)) $model->max_shares_per_Member = $max_shares_per_Member;
        if (!empty($allow_selling)) $model->allow_selling = $allow_selling;
        if (!empty($buy_period_start)) $model->buy_period_start = $buy_period_start;
        if (!empty($buy_period_end)) $model->buy_period_end = $buy_period_end;

        if ($model->save()) {
            $response = [
              'success'=> true,
                'code'=> 200,
             'message'=> 'Values updated successful' 
            ];
            
            return $response;
        }
        else {
            Yii::error("*************************************** Error updating Shares ************************");   
            Yii::error(json_encode($model->errors));
            Yii::error("*************************************** end Error updating Shares ************************");
            throw new HttpException(255, 'Failed to update Share value', 04);
        }
    }

    # Get Shares Config
    public function actionGetSharesConfig(){
        $group_id = Yii::$app->request->post('group_id');
        if (empty($group_id)) throw new HttpException(255, 'Group id is required', 01); 
        
        //find the group
        $group = Groups::find()->where(['id'=> $group_id])->one();
        if (empty($group)) throw new HttpException(255, 'Group not found', 5);

        //check if the group is kikoba
        if ($group->type!= 'Kikoba') throw new HttpException(255, 'Group is not kikoba', 13);

        //check if the current user is group Admin
        $group_Member = GroupMembers::find()->where(['group_id'=> $group_id, 'user_id'=> $this->user_id])->one();
        if (empty($group_Member)) throw new HttpException(255, 'You are not a Member of this group', 13);

        $model = SharesConfig::find()->where(['group_id'=> $group_id])->one();
        if (empty($model)) throw new HttpException(255, 'Shares not configured', 13);

        $response = [
            'success'=> true,
            'code'=> 200,
            'message'=> 'Shares fetched successful',
            'data'=> $model
        ];

        return $response;
    }

    # Shares Bought
    public function actionSharesBought(){
        $group_id = Yii::$app->request->post('group_id');
        if (empty($group_id)) throw new HttpException(255, 'Group id is required', 01); 

        //find group
        $group = Groups::find()->where(['id' => $group_id])->one();
        if (empty($group)) throw new HttpException(255, 'Group not found', 5);

        //check if the group is kikoba
        if ($group->type!= 'Kikoba') throw new HttpException(255, 'Group is not kikoba', 13);

        //check if the current user is group Member
        $group_Member = GroupMembers::find()->where(['group_id'=> $group_id, 'user_id'=> $this->user_id])->one();
        if (empty($group_Member)) throw new HttpException(255, 'You are not a Member of this group', 13);

        // sum shares bought grouped by Members
        $shares = Shares::find()
        ->select(['Member_id', 'users.name as Member_name', 'groups.name as group_name', 'SUM(shares_bought) as total_shares_bought', 'SUM(amount_paid) as total_amount_paid'])
        ->leftJoin('users', 'users.id = shares.Member_id')
        ->leftJoin('groups', 'groups.id = shares.group_id')
        ->where(['group_id' => $group_id])
        ->groupBy(['Member_id'])
        ->asArray()
        ->all();

        // calculate total shares bought in the group
        $total_shares_bought = Shares::find()->where(['group_id' => $group_id])->sum('shares_bought');

        // calculate percentage of shares each Member has
        foreach ($shares as &$share) {
            $share['percent_of_shares'] = ($share['total_shares_bought'] / $total_shares_bought) * 100;
        }

        $config = VikobaSettings::find()
        ->select(['shares_value', 'max_shares_per_Member', 'allow_selling', 'start_date'])
        ->where(['group_id'=> $group_id])->one();
        if (empty($config)){
            $response = [
                'success' => false,
                'code' => 700,
                'role' => $group_Member->role,
                'message' => 'Shares not configured',
                'data' => $shares
                ];

                // Yii::error("********************THIS IS RESPONSE RETURNED");
                // Yii::error(json_encode($response));
                // Yii::error("********************END RESPONSE RETURNED");
            return $response;    
        }

        //get total shares bought
        //$total_shares_bought = Shares::find()->where(['group_id'=> $group_id])->sum('shares_bought');

        //get total amount paid
        $total_amount_paid = Shares::find()->where(['group_id'=> $group_id])->sum('amount_paid');

        $response = [
            'success' => true,
            'code' => 200,
            'role' => $group_Member->role,
            'message' => 'Shares fetched successfully',
            'shares_config' => $config,
            'total_shares_bought' => $total_shares_bought,
            'total_amount_paid' => $total_amount_paid,
            'data' => $shares
        ];
        // Yii::error("********************THIS IS RESPONSE RETURNED");
        // Yii::error(json_encode($response));
        // Yii::error("********************END RESPONSE RETURNED");
        return $response;
    }

    # Buy Shares
    public function actionBuyShare(){
        $group_id = Yii::$app->request->post('group_id');
        if (empty($group_id)) throw new HttpException(255, 'Group id is required', 01);
        $amount = Yii::$app->request->post('amount');
        if (empty($amount)) throw new HttpException(255, 'Amount is required', 01);
        $amount = (int)$amount;
        // check if amount is bellow limit
        if ($amount < 1000) throw new HttpException(255, 'Amount must be above 1000', 02);

        $channel = Yii::$app->request->post('channel');
        if (empty($channel)) throw new HttpException(255, 'Channel is required', 01);
        $validChannel = Yii::$app->params['support_payment_channels'];
        if (!in_array($channel, $validChannel)) {
            throw new \Exception('Invalid channel');
        }
        $payer_msisdn = Yii::$app->request->post('msisdn');
        if (empty($payer_msisdn)) throw new HttpException(255, 'Payer msisdn is required', 01);
        //validate mobile 
        $cus_mob = Yii::$app->helper->validateMobile($payer_msisdn);

        if (!$cus_mob['success']) throw new HttpException(255, $cus_mob['message'], 02);

        $payer_msisdn = $cus_mob['cus_mob'];

        //validate channel
        $validateChannel = Yii::$app->helper->validateChannel($payer_msisdn, $channel);
        if (!$validateChannel['success']) throw new HttpException(255, $validateChannel['message'], 02);

        //check if group exist
        $group = Groups::find()->where(['id' => $group_id])->one();
        if (empty($group)) throw new HttpException(255, 'Group not found', 5);

        //check if group is kikoba
        if ($group->type!= 'Kikoba') throw new HttpException(255, 'Group is not kikoba', 13);

        //check if the current user is group Member
        $group_Member = GroupMembers::find()->where(['group_id'=> $group_id, 'user_id'=> $this->user_id])->one();
        if (empty($group_Member)) throw new HttpException(255, 'You are not a Member of this group', 13);

        //check if group has shares config
        $shares_config = SharesConfig::find()->where(['group_id' => $group_id])->one();
        if (empty($shares_config)) throw new HttpException(255, "the group has not set shares config", 13);

        //check if the buy period has passed
        $buy_period = $shares_config->buy_period_end;
        if (!empty($buy_period)){
            if (strtotime($buy_period) < time()) throw new HttpException(255, "the buy period has passed", 13);
        }

        //get share price
        $shares_price = $shares_config->share_price;

        //check if Member has exceeded max shares per Member
        $max_shares_per_Member = $shares_config->max_shares_per_Member;

        // check shares already bought
        $total_shares_bought = Shares::find()->where(['group_id' => $group_id])->sum('shares_bought') ?? 0;

        // get remaining shares to be bought
        $remaining_shares = 100 - $total_shares_bought;

        // check if the user has already bought shares
        $user_shares = Shares::find()->where(['group_id' => $group_id, 'Member_id' => $this->user_id])->sum('shares_bought')?? 0;

        // check to get the amount of shares remain for Member to buy
        $remaining_shares_for_Member = $max_shares_per_Member - $user_shares;

        //get shares to be bought
        $shares_to_be_bought = $amount / $shares_price;

        if ($shares_to_be_bought > $remaining_shares_for_Member) throw new HttpException(255, 'You have exceeded your max shares per Member remaining shares you can buy is: ' .$remaining_shares_for_Member, 13);

        
        // check exceed max shares
        if ($remaining_shares < $shares_to_be_bought) throw new HttpException(255, 'Shares limit exceeded', 13);

        //TODO:: perform push payment
        //$type = 'Buying Share'; 
        // $initiatePush = Yii::$app->helper->pushPayment($payer_msisdn, $amount, $channel, $this->user_id, group_id, $type);

        // if ($initiatePush['success']){
        //     $response = [
        //         'success' => true,
        //         'message' => 'Payment initiated successfully',
        //     ];
        // }
        // else{
        //     $response = [
        //         'success' => false,
        //         'message' => 'Payment failed',
        //     ];
        // }
        // return $response;

        //TODO:: FOR TESTING 
            $trans_ref = 'Test'. time(); 

            $model = new Payments();
            $model->group_id = $group_id;
            $model->user_id = $this->user_id;;
            $model->reference = $trans_ref;
            $model->amount = $amount;
            $model->payment_date = date("Y-m-d H:i:s");
            $model->payment_method = 'Selcom';
            $model->status = 'verified';
            $model->payment_for = 'Buying Shares';
            $model->save(false);

            //generate notification
            $login_user_details = User::find()->where(['id' => $this->user_id])->one();
            //get group owner package details
            $package  = Yii::$app->helper->getPackagebyGroupID($group_id);
            if ($package['success']) {
                $params = [
                    'user_id' => $this->user_id,
                    'user_name' => $this->login_user_name,
                    'lang' => $login_user_details->language,
                    'type' => $package->notification_support,
                    'group_id' => $group_id,
                    'group_type' => 'Kikoba',
                    'group_name' => $group->name,
                    'process' => 'Buy Share',
                ];

                $record_notification = Helper::generateNotification($params);
            }
            
            

        ///
    
        $model = new Shares();
        $model->group_id = $group_id;
        $model->Member_id = $this->user_id;
        $model->amount_paid = $amount;
        $model->shares_bought = $shares_to_be_bought;
        $model->bought_at = date('Y-m-d H:i:s');
        if ($model->save()) {
            $response = [
                'success' => true,
                'code' => 0,
                'message' => 'Shares bought successfully',
                'data' => [
                    'shares_bought' => $shares_to_be_bought,
                    //'extra_amount' => $extra_amount
                ]
            ]; 

            return $response;
        }
        else{
            Yii::error("*************************************** Error buying Shares ************************");
            Yii::error(json_encode($model->errors));
            Yii::error("*************************************** end Error buying Shares ************************");
            throw new HttpException(255, 'Failed to buy shares', 04);
        }

        //}
    }
    
    # Initiate withdraw
    public function actionInitiateWithdraw(){
        Yii::error("************************************* RECEIVED DATA *********************************");
        Yii::error(Yii::$app->request->post());
        Yii::error("***************************************************************************************");

        $group_id = Yii::$app->request->post('groupId');
        if (empty($group_id)) throw new HttpException(255, 'Group id is required', 01);
        $amount = Yii::$app->request->post('amount');
        if (empty($amount)) throw new HttpException(255, 'Amount is required', 01);
        $amount = (int)$amount;
        $reason = Yii::$app->request->post('reason');
        if (empty($reason)) throw new HttpException(255, 'Reason is required', 01);
        $recipient_id = Yii::$app->request->post('memberId');  //Member id to receive money
        if (empty($recipient_id)) throw new HttpException(255, 'Recipient id is required', 01);

        //check if group exist
        $group = Groups::find()->where(['id' => $group_id])->one();
        if (empty($group)) throw new HttpException(255, 'Group not found', 5);

        //check if current user is group Member of the group
        $group_Member = GroupMembers::find()->where(['group_id'=> $group_id, 'user_id'=> $this->user_id])->one();
        if (empty($group_Member)) throw new HttpException(255, 'You are not a Member of this group', 13);

        //check if recipient is group Member of the group
        $recipient_Member = GroupMembers::find()->where(['group_id'=> $group_id, 'user_id'=> $recipient_id])->one();
        if (empty($recipient_Member)) throw new HttpException(255, 'Recipient is not a Member of this group', 13);

        //check if current user has permission to initiate
        //get group setting
        $getSetting = $this->getGroupSetting($group['id'], $group['type']);

        $settings = $getSetting['data'];
        if (ucfirst($group_Member->role) != $settings['withdraw_initiator']) throw new HttpException(255, 'You are not authorized to initiate withdraw of this group', 13);

        //check if the amount is valid
        if ($amount < 5000) throw new HttpException(255, 'Amount is too low', 13);

        //check if the group has this amount in the group wallet
        $groupAvailableBalance = $this->getGroupAvailableBalance($group_id);
        Yii::error("Available Amount: ". $groupAvailableBalance);

        if ($amount > $groupAvailableBalance) throw new HttpException(255, 'Group does not have this amount in the group wallet', 13);

        //create request
        $model = new OutgoingPayment();
        $model->amount = $amount;
        $model->group_id = $group_id;
        $model->recipient_id = $recipient_id;
        $model->reason = $reason;
        $model->created_at = date('Y-m-d H:i:s');
        $model->created_by = $this->user_id;
        $model->status = 'pending';
        if ($model->save()) {

            $current_user = User::find()->where(['id' => $this->user_id])->one();
            $group_owner = User::find()->where(['id' => $group->created_by])->one();
            $package = Packages::find()->where(['id' => $group_owner->package_id])->one();
            //generate notification
            $params = [
                'user_id' => $this->user_id,
                'user_name' => $current_user->name,
                'group_name' => $group->name,
                'amount'=> $amount,
                'reason' => $reason,
                'group_id' => $group->id,
                'role' => $group_Member->role,
                'lang' => $group_owner->language,
                'type' => $package->notification_support,
                'process' => 'Withdraw Request',
            ];

            $record_notification = Helper::generateNotification($params);
            $response = [
            'success' => true,
                'code' => 0,
            'message' => 'Request created successfully',
                'data' => $model
            ];

            return $response;
            
        }
        else{
            Yii::error("*************************************** Error creating request ************************");
            Yii::error(json_encode($model->errors));
            Yii::error("*************************************** end Error creating request ************************");
            throw new HttpException(255, 'Failed to create request', 04);
        }
    }

    # Approve withdraw request
    public function actionApproveWithdraw(){
        Yii::error("************************************* RECEIVED DATA *********************************");
        Yii::error(Yii::$app->request->post());
        Yii::error("***************************************************************************************");

        $payment_id = Yii::$app->request->post('transactionId');
        if (empty($payment_id)) throw new HttpException(255, 'transactionId is required', 01);

        $status = Yii::$app->request->post('action');
        if (empty($status)) throw new HttpException(255,'status is required', 01);

        //check if the status is verified or rejected
        if ($status!='approve' && $status!='reject') throw new HttpException(255, 'Invalid payment status', 16);

        $payment = OutgoingPayment::find()->where(['id' => $payment_id])->one();
        if (empty($payment)) throw new HttpException(255, 'Payment not found', 5);
        
        $user_id = $this->user_id;

        $group = Groups::find()->where(['id' => $payment->group_id])->one();
        if (empty($group)) throw new HttpException(255, 'Group not found', 5);

        //get group setting
        $getSetting = $this->getGroupSetting($group['id'], $group['type']);

        $settings = $getSetting['data'];

        $group_Member = GroupMembers::find()->where(['group_id' => $payment->group_id, 'user_id' => $user_id])->one();
        if (empty($group_Member)) throw new HttpException(255, 'You are not a Member of this group', 13);

        //check if user is authorized to approve this payment
        if (ucfirst($group_Member->role) != $settings['withdraw_approver']) throw new HttpException(255, 'You are not authorized to approve withdraw of this group', 13);

        $payment->approver_two = 1; //setting to true
        $payment->updated_at = date('Y-m-d H:i:s');
        if ($status =='approve'){
            $payment->status = "Approved"; //to be used by engine to make transfer
        }
        else {
            $payment->status = "Rejected";
        }
        if($payment->save()){
            if ($status == 'approve'){

                $current_user = User::find()->where(['id' => $this->user_id])->one();
                $group_owner = User::find()->where(['id' => $group->created_by])->one();
                $package = Packages::find()->where(['id' => $group_owner->package_id])->one();
                //genereate notification
                $params = [
                    'user_id' => $this->user_id,
                    'user_name' => $current_user->name,
                    'group_name' => $group->name,
                    'amount'=> $payment->amount,
                    'reason' => $payment->reason,
                    'group_id' => $group->id,
                    'role' => $group_Member->role,
                    'lang' => $group_owner->language,
                    'type' => $package->notification_support,
                    'process' => 'Withdraw Final Approval',
                ];

                $record_notification = Helper::generateNotification($params);

            }
            else if ($status =='reject'){
                $current_user = User::find()->where(['id' => $this->user_id])->one();
                $group_owner = User::find()->where(['id' => $group->created_by])->one();
                $package = Packages::find()->where(['id' => $group_owner->package_id])->one();
                //genereate notification
                $params = [
                    'user_id' => $this->user_id,
                    'user_name' => $current_user->name,
                    'group_name' => $group->name,
                    'amount'=> $payment->amount,
                    'reason' => $payment->reason,
                    'group_id' => $group->id,
                    'role' => $group_Member->role,
                    'lang' => $group_owner->language,
                    'type' => $package->notification_support,
                    'process' => 'Withdraw Rejected',
                ];

                $record_notification = Helper::generateNotification($params);   
            }

            $response = [
               'success' => true,
                'code' => 0,
               'message' => 'Payment'.$status.'successfully',
            ];

            return $response;
        }
    }

    # Payment Callback
    public function actionPaymentCallback(){

    }

    # Generate Contribution Schedule for all groups
    public function actionGenerateContributionScheduleForGroup(){
        $group_id  = Yii::$app->request->post('groupId');
        if (empty($group_id)) throw new HttpException(255, 'Group Id is requred', 01);

        //generate contribution schedule
        $response = Helper::generateContributionSchedule2($group_id);

        return $response;

    }

    # group available balance
    public function getGroupAvailableBalance($group_id){

        $incomingPayment = Payments::find()
            ->where(['group_id' => $group_id])
            ->andWhere(['status' =>'verified'])
            ->sum('amount')?? 0;
        $outgoingPayment = OutgoingPayment::find()
            ->where(['group_id' => $group_id])
            ->andWhere(['status' =>'Approved'])
            ->sum('amount')?? 0;
        $balance = $incomingPayment - $outgoingPayment; 
        
        $available = $balance - 5000; // deduct 5000 for group security balance

        return $available;
    }

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


}
 