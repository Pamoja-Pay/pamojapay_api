<?php

namespace app\controllers;

use app\models\Notifications;
use Yii;
use app\models\Contributions;
use app\models\GroupMembers;
use app\models\Packages;
use app\models\EventStatus;
use app\components\Helper;
use app\models\PayoutSchedule;
use app\models\PayoutScheduleSetting;
use app\models\ContributionSchedule;
use app\models\Payments;
use app\models\Groups;
use app\models\NotificationRecipient;
use app\models\Pledges;
use yii\rest\Controller;
use app\models\User;
use yii\web\HttpException;
use yii\web\UploadedFile;

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
            $group_member = new GroupMembers();
            $group_member->group_id = $group->id;
            $group_member->user_id = $this->user_id;
            $group_member->role = 'admin';
            $group_member->joined_at = date('Y-m-d H:i:s');
            $group_member->is_active = true;
            if ($group_member->save()){

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
                        throw new HttpException(255, "Faild to Record Event Status", 04);
                    }
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
            else throw new HttpException(255, 'Group member creation failed', 04);
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

        //check if user is admin
        $group_member = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $this->user_id])->one();
        if (empty($group_member)) throw new HttpException(255, 'You are not a member of this group', 13);
        if ($group_member->role != 'admin') throw new HttpException(255, 'You are not an admin of this group', 13);

        //check if the user already is a member in the group
        $is_member = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $user_id])->one();
        if (!empty($is_member)) throw new HttpException(255, "The User is already a member", 03);

        //check if group is Mchezo
        if ($group->type == 'Mchezo'){
            //check if group has payout schedule setting
            $payout_setting = PayoutScheduleSetting::find()->where(['group_id' => $group_id])->one();
            if (empty($payout_setting)) throw new HttpException(255, "Please add payout schedule setting before adding members", 13);
        }

        if ($group->type != 'Event'){
            //check if contribution schedule is set
            $contribution_schedule = Contributions::find()->where(['group_id' => $group_id])->one();
            if (empty($contribution_schedule)) throw new HttpException(255, "Please add contribution schedule before adding members", 13);
        }

        //check if the group has reach its maximum number of members
        //get user package id
        $user_details = User::findOne($this->user_id);
        $package_id = $user_details->package_id;

        //get package group limit
        $package = Packages::findOne($package_id);
        if (empty($package)) throw new HttpException(255, 'Package not found',5);

        $member_limit = $package->member_limit;
        $member_count = GroupMembers::find()->where(['group_id' => $group_id])->count();
        if ($member_count >= $member_limit) throw new HttpException(255, "The Group has reach maximum number of Members", 14);

        if ($role == "admin"){
            //count the members with the role admin
            $counts = GroupMembers::find()->where(['group_id' => $group_id, 'role' => 'admin'])->count();
            if ($counts >= 2) throw new HttpException(255, "Group has maximum number of Admins", 15);
        }
        else if ($role == "treasurer"){
            //count the members with the role admin
            $counts = GroupMembers::find()->where(['group_id' => $group_id, 'role' => 'treasurer'])->count();
            if (!empty($counts)) throw new HttpException(255, "Group has maximum number of Treasurer", 15);
        }
        else if ($role == "member"){
             
        }
        else if ($role == "secretary"){
            //count the members with the role secretary
            $counts = GroupMembers::find()->where(['group_id' => $group_id, 'role' => 'secretary'])->count();
            if (!empty($counts)) throw new HttpException(255, "Group has maximum number of Secretary", 15);
        }
        else if ($role == "chairperson"){
            //count the members with the role chairperson
            $counts = GroupMembers::find()->where(['group_id' => $group_id, 'role' => 'chairperson'])->count();
            if (!empty($counts)) throw new HttpException(255, "Group has maximum number of Chairperson", 15);
        }
        else throw new HttpException(255, "Role not Valid", 16);

        $group_member = new GroupMembers();
        $group_member->group_id = $group_id;
        $group_member->user_id = $user_id;
        $group_member->role = strtolower($role);
        $group_member->joined_at = date('Y-m-d H:i:s');
        $group_member->is_active = true;
        if ($group_member->save()){

            //generate contribution schedule
            Helper::generateContributionSchedule($group_id);

            //generate notification
            $params = [
                'user_id' => $this->user_id,
                'user_name' => $user->name,
                'group_name' => $group->name,
                'group_type'=> $group->type,
                'group_id' => $group->id,
                'role' => $group_member->role,
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
        $member_id =  Yii::$app->request->post('user_id');
        if (empty($member_id)) throw new HttpException(255, 'User ID is required', 01);
        $group_id = Yii::$app->request->post('group_id');
        if (empty($group_id)) throw new HttpException(255, 'Group ID is required', 01);

        $user_id = $this->user_id;

        //check if group exist
        $group = Groups::find()->where(['id' => $group_id])->one();
        if (empty($group)) throw new HttpException(255, "Group not found", 5);

        //check if this user is a group member 
        $is_member1 = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $user_id])->one();
        if (empty($is_member1)) throw new HttpException(255, "Your not a group member", 13);
        
        //check if this user is admin of this group
        if ($is_member1->role != "admin") throw  new HttpException(255, "Your not an Admin of this Group", 13);

        //check if member to be removed is a member
        $is_member2 = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $member_id])->one();
        if (empty($is_member2)) throw new HttpException(255, "This user is not a member", 13);

        //check if the member to be removed is an admin
        if ($is_member2->role == "admin") throw new HttpException(255, "You can not remove admin member", 13);


        //get details of member to be removed 
        $member_details = User::find()->where(['id' => $member_id])->one();
        if (empty($member_details)) throw new HttpException(255, 'user not found', 5);


        if ($is_member2->delete()){

            $user_details = User::find()->where(['id' => $this->user_id])->one();
            if (empty($user_details)) throw new HttpException(255, 'User not found', 5);
            //get package
            $package = Packages::find()->where(['id' => $this->package_id])->one();
            if (empty($package)) throw new HttpException(255, 'Package not found', 5);


            //generate notification
            $params = [
                'user_id' => $this->user_id,
                'user_name' => $member_details->name,
                'group_name' => $group->name,
                'group_type'=> $group->type,
                'group_id' => $group->id,
                'role' => $is_member2->role,
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

    # Update user role in a group
    public function actionUpdateMember(){
        $member_id = Yii::$app->request->post('user_id');
        if (empty($member_id)) throw new HttpException(255, 'User ID is required', 01);
        $group_id = Yii::$app->request->post('group_id');
        if (empty($group_id)) throw new HttpException(255, 'Group ID is required', 01);
        $role = Yii::$app->request->post('role');
        if (empty($role)) throw new HttpException(255, "Role is required", 01);


        $user_id = $this->user_id;

        $member_details = User::find()->where(['id' => $member_id])->one();
        if (empty($member_details)) throw new HttpException(255, 'Member not found', 5);

        //check if the group exist
        $group = Groups::find()->where(['id' => $group_id])->one();
        if (empty($group)) throw new HttpException(255, 'Group not found', 5);

        //check if user is member
        $group_member = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $this->user_id])->one();
        if (empty($group_member)) throw new HttpException(255, 'You are not a member of this group', 13);
        //check if user is admin
        if ($group_member->role != 'admin') throw new HttpException(255, 'You are not an admin of this group', 13);

        //check if user id is equal to member id
        if ($user_id == $member_id) throw new HttpException(255, "You can not change your role", 13);
        
        //check if member is already member
        $is_member = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $member_id])->one();
        if (empty($group_member)) throw new HttpException(255, 'This member is not a member of this group', 13);

        //check if the role to be asigned equals to the same  member role
        if ($is_member->role == $role) throw new HttpException(255, "the user is already a ". $role , 03);

        if ($role == "admin"){
            //count the members with the role admin
            $counts = GroupMembers::find()->where(['group_id' => $group_id, 'role' => 'admin'])->count();
            if ($counts >= 2) throw new HttpException(255, "Group has maximum number of Admins", 15);
        }
        else if ($role == "treasurer"){
            //count the members with the role admin
            $counts = GroupMembers::find()->where(['group_id' => $group_id, 'role' => 'treasurer'])->count();
            if (!empty($counts)) throw new HttpException(255, "Group has maximum number of Treasurer", 15);
        }
        else if ($role == "member"){
             
        }
        else if ($role == "secretary"){
            //count the members with the role secretary
            $counts = GroupMembers::find()->where(['group_id' => $group_id, 'role' => 'secretary'])->count();
            if (!empty($counts)) throw new HttpException(255, "Group has maximum number of Secretary", 15);
        }
        else if ($role == "chairperson"){
            //count the members with the role chairperson
            $counts = GroupMembers::find()->where(['group_id' => $group_id, 'role' => 'chairperson'])->count();
            if (!empty($counts)) throw new HttpException(255, "Group has maximum number of Chairperson", 15);
        }
        else if ($role == "vice_chairperson"){
            //count the members with the role vice chairperson
            $counts = GroupMembers::find()->where(['group_id' => $group_id, 'role' => 'vice_chairperson'])->count();
            if (!empty($counts)) throw new HttpException(255, "Group has maximum number of Vice Chairperson", 15);
        }
        else if ($role == "organizer"){
            //count the members with the role organizer
            $counts = GroupMembers::find()->where(['group_id' => $group_id, 'role' => 'organizer'])->count();
            if ($counts >= 3) throw new HttpException(255, "Group has maximum number of Organizers", 15);
        }
        else if ($role == "coordinator"){
            //count the members with the role coordinator
            $counts = GroupMembers::find()->where(['group_id' => $group_id, 'role' => 'coordinator'])->count();
            if ($counts >= 2) throw new HttpException(255, "Group has maximum number of Coordinators", 15);
        }
        else throw new HttpException(255, "Role not Valid", 16);
        $is_member->role = $role;
        if ($is_member->save()){

            $login_user_details = User::find()->where(['id' => $this->id])->one();
            if (empty($login_user_details)) throw new HttpException(255, 'User not found', 5);

            $package = Packages::find()->where(['id' => $this->package_id])->one();
            if (empty($package)) throw new HttpException(255, 'Package not found', 5);
            //generate notification
            $params = [
                'user_id' => $this->user_id,
                'user_name' => $member_details->name,
                'group_name' => $group->name,
                'group_type'=> $group->type,
                'group_id' => $group->id,
                'role' => $is_member->role,
                'lang' => $login_user_details->language,
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
            ->where(['like', 'name', $group_name])
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
        $group_id = Yii::$app->request->post('groupId');
        if (empty($group_id)) throw new HttpException(255, 'Group ID is required', 01);

        $group = Groups::find()
            ->select(['id', 'name', 'description', 'created_at', 'created_by', 'logo_url', 'type'])
            ->where(['id' => $group_id])
            ->asArray()
            ->one();
            
        if (empty($group)) throw new HttpException(255, 'Group not found', 5); 

        //check if the user is a member of this group
        $group_member = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $this->user_id])->one();
        if (empty($group_member)) throw new HttpException(255, 'You are not a member of this group', 13);

        $user_role = $group_member->role;

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

            //get user payments
            $user_payments = $this->getUserPayments($this->user_id);

            //get upcoming schedules
            $upcoming_schedules = Helper::nextContributionsPerGroup($this->user_id);
            
        $response = [
            'success' => true,
            'code' => 0,
            'group' => $group,
            'is_pledged' => $is_pledged,
            'group_members' => $group_members,
            'user_payments' => $user_payments,
            'upcoming_schedules' => $upcoming_schedules,
            'user_role' => $user_role,
            'message' => null
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

        $group_member = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $this->user_id])->one();
        if (empty($group_member)) throw new HttpException(255, 'User not a member of this group', 13);

        if ($group_member->role != 'admin') throw new HttpException(255, 'User is not an admin of this group', 13);

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

        //check if the user is the member of the group
        $group_member = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $user_id])->one();
        if (empty($group_member)) throw new HttpException(255, 'You are not a member of this group', 13);

        //check if the pledge deadline is in the future
        if ($event_status->pledge_deadline < date('Y-m-d H:i:s')) throw new HttpException(255, 'Pledge deadline has passed', 13);

        //check if the to be paid at valid based on contribution deadline
        if ($event_status->contribution_deadline < $to_be_paid_at) throw new HttpException(255, 'The contribution deadline is : '. $event_status->contribution_deadline, 13);

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

        //check if user is a member of the group
        $group_member = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $user_id])->one();
        if (empty($group_member)) throw new HttpException(255, 'You are not a member of this group', 13);

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


        //check if user is a member of the group
        $group_member = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $user_id])->one();
        if (empty($group_member)) throw new HttpException(255, 'You are not a member of this group', 13);

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


        //check if user is a member of the group
        $group_member = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $user_id])->one();
        if (empty($group_member)) throw new HttpException(255, 'You are not a member of this group', 13);

        if ($group_member->role == 'admin'){
            $is_admin = true;
        }
        else {
            $is_admin = false;
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
                'is_admin' => $is_admin,
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

        //check if user is a member of the group
        $group_member = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $user_id])->one();
        if (empty($group_member)) throw new HttpException(255, 'You are not a member of this group', 13);

        //check if user is an admin of the group
        if ($group_member->role != 'admin') throw new HttpException(255, 'You are not an admin of this group', 13);

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

        //check if user is a member of the group
        $group_member = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $user_id])->one();
        if (empty($group_member)) throw new HttpException(255, 'You are not a member of this group', 13);

        //check if user is an admin of the group
        if ($group_member->role != 'admin') throw new HttpException(255, 'You are not an admin of this group', 13);

        //check if the group has a payout schedule setting
        $payout_schedule_setting = PayoutScheduleSetting::find()->where(['group_id' => $group_id])->one();
        if (empty($payout_schedule_setting)) throw new HttpException(255, 'No payout schedule setting found', 5);

        // Get all active members sorted by joined_at
        $members = GroupMembers::find()
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

        foreach ($members as $member) {
            if (!in_array($member->user_id, $existingUserIds)) {
                $schedule = new PayoutSchedule();
                $schedule->group_id = $group_id;
                $schedule->user_id = $member->user_id;
                $schedule->scheduled_date = $startDate;
                $schedule->created_at = date('Y-m-d H:i:s');
                $schedule->status = 'pending';
                $schedule->updated_at = date('Y-m-d H:i:s');
                if ($schedule->save()) {
                    $newSchedules[] = [
                        'user_id' => $member->user_id,
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
                'message' => 'No new members to add to payout schedule.',
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
        $group_id = Yii::$app->request->post('group_id');
        if (empty($group_id)) throw new HttpException(255, 'Group ID is required', 01);

        $amount = Yii::$app->request->post('amount');
        if (empty($amount)) throw new HttpException(255, 'Amount is required', 01);

        $payment_proof = UploadedFile::getInstanceByName("payment_proof");
        if (empty($payment_proof)) throw new HttpException(255, 'Payment proof is required', 01);

        $payment_date = Yii::$app->request->post('payment_date');
        if (empty($payment_date)) throw new HttpException(255, 'Payment date is required', 01);

        $payment_method = Yii::$app->request->post('payment_method');
        if (empty($payment_method)) throw new HttpException(255, 'Payment method is required', 01);

        $user_id = $this->user_id;

        //check if group exists
        $group = Groups::find()->where(['id' => $group_id])->one();
        if (empty($group)) throw new HttpException(255, 'Group not found', 5);

        //check if user is a member of the group
        $group_member = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $user_id])->one();
        if (empty($group_member)) throw new HttpException(255, 'You are not a member of this group', 13);   

        //save the payment proof
        $proof_url = time().'.'.$payment_proof->extension;
        $path = Yii::getAlias('@webroot') . '/'. 'payments/' . $proof_url;
        if($payment_proof->saveAs($path)){
            $payment = new Payments();
            $payment->group_id = $group_id;
            $payment->user_id = $user_id;
            $payment->amount = $amount;
            $payment->payment_date = $payment_date;
            $payment->reference = time().'-000'.$user_id;
            $payment->status = 'pending';
            $payment->verified_by = null;
            $payment->verified_at = null;
            $payment->proof_url = $proof_url;
            $payment->payment_method = $payment_method;
            if ($payment->save()){

                // //check group type
                // if ($group->type == 'Event'){
                //     //reduce the remaining amount
                //     $pledges = Pledges::find()->where(['group_id' => $group_id, 'user_id' => $user_id])->one();
                //     if (empty($pledges)) throw new HttpException(255, 'No pledges found', 01);

                //     $pledges->remain_amount = $pledges->remain_amount - $amount;
                //     $pledges->paid_amount = $pledges->paid_amount + $amount;
                //     $pledges->paid_at = $payment_date;
                //     $pledges->save();
                // }
                //genereate notification
                $login_user_details = User::find()->where(['id' => $this->user_id])->one();
                if (empty($login_user_details)) throw new HttpException(255, 'User not Found', 5);
                $package = Packages::find()->where(['id' => $this->package_id])->one();
                if (empty($package)) throw new HttpException(255, 'Package not found', 5);
                $params = [
                    'user_id' => $this->user_id,
                    'user_name' => $this->login_user_name,
                    'group_name' => $group->name,
                    'group_type'=> $group->type,
                    'group_id' => $group->id,
                    'amount' => $amount,
                    'lang' => $login_user_details->language,
                    'type' => $package->notification_support,
                    'process' => 'newPayment',
                ];
    
                $record_notification = Helper::generateNotification($params);
                if ($record_notification){
                    $response = [
                        'success' => true,
                        'code' => 0,
                        'message' => 'Payment added successfully Waiting for verification',
                    ];
                }
                else{
                    $response = [
                        'success' => true,
                        'code' => 0,
                        'message' => 'Payment added successfully Waiting for verification failed to generate notification',
                    ];
                }

                
            }
            else throw new HttpException(255, 'Failed to record Payment', 04);

            return $response;
        }
        else{
            throw new HttpException(255, 'Failed to save payment proof', 18);
        }        
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

        //check if user is an treasurer of the group
        $group_member = GroupMembers::find()->where(['group_id' => $payment->group_id, 'user_id' => $user_id])->one();
        if (empty($group_member)) throw new HttpException(255, 'You are not a member of this group', 13);

        if ($group_member->role != 'treasurer') throw new HttpException(255, 'You are not a treasurer of this group', 13);
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

        $response = [
            'success' => true,
            'code' => 0,
            'message' => 'Payments fetched successfully',
            'data' => $payments
        ];

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

            $expectedAmount = 0;
            $outstanding = 0;

            if (in_array($groupType, ['Kikoba', 'Mchezo'])) {
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

        $group_member = GroupMembers::find()->where(['group_id' => $group_id, 'user_id' => $user_id])->one();
        if (empty($group_member)) throw new HttpException(255, 'You are not a member of this group', 13);


        $payments = Payments::find()
            ->select(['payments.*', 'groups.name as group_name', 'users.name as user_name'])
            ->leftJoin('groups', 'groups.id = payments.group_id')
            ->leftJoin('users', 'users.id = payments.user_id')
            ->where(['group_id' => $group_id])
            ->orderBy(['payment_date' => SORT_DESC]) // Order by payment_date DESC
            ->asArray()
            ->all();

        $response = [
            'success' => true,
            'code' => 0,
            'message' => 'Payments fetched successfully',
            'user_role' => $group_member->role,
            'data' => $payments
        ];

        return $response;
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

    # Change Password
    public function actionChangePassword(){
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
        Yii::error("*************************************** Mark Notification as read ************************");
        Yii::error(Yii::$app->request->post());
        Yii::error("*************************************** end Mark Notification as read ************************");
        $notification_id = Yii::$app->request->post('notification_id');
        if (empty($notification_id)) throw new HttpException(255, 'Notification ID is required', 01);

        $notification = NotificationRecipient::find()->where(['id' => $notification_id])->one();
        if (empty($notification)) throw new HttpException(255, 'Notification not found', 5);

        $notification->read_at = date('Y-m-d H:i:s');
        if ($notification->save()) {
            return [
                'success' => true,  
                'code' => 0,
                'message' => 'Notification marked as read'
            ];
        }
        else throw new HttpException(255, 'Failed to mark notification as read', 9);    
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
}
 