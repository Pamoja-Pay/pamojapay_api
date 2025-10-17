<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use app\models\User;
use app\models\Groups;
use app\models\Payments;
use app\models\Pledges;
use app\models\Shares;
use app\models\ContributionSchedule;
use app\components\Helper;
use yii\web\HttpException;
class CronController extends Controller
{
    public function actionGenerateSchedules()
    {
        $today = new \DateTime();
        $cutoffDate = (clone $today)->modify('+2 weeks');

        $groups = Groups::find()->where(['type' => 'Kikoba'])
            ->orWhere(['type' => 'Mchezo'])
            ->noCache()
            ->all();
        Yii::error("groups:");
        Yii::error($groups);

        foreach ($groups as $group) {
            $latestDue = ContributionSchedule::find()
                ->where(['group_id' => $group->id])
                ->orderBy(['due_date' => SORT_DESC])
                ->limit(1)
                ->scalar();

            if (!$latestDue || new \DateTime($latestDue) < $cutoffDate) {
                Helper::generateContributionSchedule($group->id);
                Yii::error("Generated schedules for group ID: {$group->id}");
                //Console::output("Generated schedules for group ID: {$group->id}");
            } else {
                Yii::error("Group ID: {$group->id} is up to date.");
                //Console::output("Group ID: {$group->id} is up to date.");
            }

        }
    }

    public function actionGenerateNewSchedules(){
        $today = new \DateTime();
        $cutoffDate = (clone $today)->modify('+2 months'); // ensure 2 months ahead

       //Only fetch Kikoba and Mchezo groups
       $groups = Groups::find()
       ->where(['type' => ['Kikoba', 'Mchezo']])
       ->all();

        foreach ($groups as $group) {
            Yii::error("This is group id: ". $group->id);
            // find latest due_date for this group
            $lastSchedule = ContributionSchedule::find()
                ->where(['group_id' => $group->id])
                ->orderBy(['due_date' => SORT_DESC])
                ->one();

            $startDate = $lastSchedule ? new \DateTime($lastSchedule->due_date) : $today;

            // generate new schedules until cutoff is covered
            while ($startDate < $cutoffDate) {

                $generate = Helper::generateContributionSchedule2($group->id);

                // $startDate->modify('+1 month');

                // $schedule = new ContributionSchedule();
                // $schedule->group_id = $group->id;
                // $schedule->user_id = null; // set according to your logic
                // $schedule->due_date = $startDate->format('Y-m-d');
                // $schedule->amount = $group->contribution_amount; // assuming group has this field
                // $schedule->round_number = ($lastSchedule ? $lastSchedule->round_number + 1 : 1);
                // $schedule->is_paid = 0;
                // $schedule->paid_amount = 0;
                // $schedule->remain_amount = $schedule->amount;

                if ($generate) {
                    echo "Created schedule for Group {$group->id} Completed Successful" ."\n";
                } else {
                    echo "Failed to create schedule for Group {$group->id}: " . "\n";
                }

                //$lastSchedule = $schedule;
            }
        }
    }

    public function actionProcessPayments(){
        $payments = Payments::find()
        ->where(['status' => 'SUCCESS'])
        ->all();

        if (!empty($payments)){
            foreach ($payments as $payment){
                $amount = $payment->amount;
                $group_id = $payment->group_id;
                $user_id = $payment->user_id;

            
                $group_details = Groups::find()
                    ->where(['id' => $group_id])
                ->one();
                
                // check if the group is a Event
                if ($group_details->type == 'Event'){
                    //reduce the remaining amount
                    $pledges = Pledges::find()
                        ->where(['group_id' => $group_id, 'user_id' => $user_id])
                    ->one();
                    if (empty($pledges)) throw new HttpException(255, 'No pledges found', 01);
                    $pledges->remain_amount = $pledges->remain_amount - $amount;
                    if ($pledges->remain_amount < 0){
                        $pledges->status = 'Paid';
                    }
                    else{
                        $pledges->status = 'Partial Paid';
                    }
                    $pledges->paid_amount = $pledges->paid_amount + $amount;
                    $pledges->paid_at = $payment->payment_date;
                    if ($pledges->save()){
                        //genereate notification
                        $payer_id = $user_id;
                        $payer_details = User::find()->where(['id' => $payer_id])->one();
                        //get group owner package details
                        $package  = Yii::$app->helper->getPackagebyGroupID($group_id);
                        if ($package['success']) {
                            $package_details = $package['package_details'];
                        }
                        $params = [
                            'user_id' => $user_id,
                            'user_name' => $payer_details->name,
                            'group_name' => $group_details->name,
                            'group_type'=> $group_details->type,
                            'group_id' => $group_details->id,
                            'amount' => $amount,
                            'lang' => $payer_details->language,
                            'type' => $package_details->notification_support,
                            'process' => 'approvedPayment',
                        ];
                
                        $record_notification = Helper::generateNotification($params);
                        if ($record_notification){
                            $payment->status = 'COMPLETE';
                            $payment->updated_at = date('Y-m-d H:i:s');
                            $payment->save(false);

                        }
                        else{
                            throw new HttpException(255, 'Payment Completed  successfully failed to generete notification', 9);
                        }
                        
                    }
                    else throw new HttpException(255, 'Failed to update pledges', 9);

                }
                else if ($group_details->type == 'Kikoba'){
                    //if the payment if for buying shares
                   
                    if ($payment->payment_for == 'Shares'){
                        //check if group has shares config
                        //get group setting
                        $group_setting = Yii::$app->helper->getGroupSetting($group_id, $group_details->type);
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
                        //Yii::error("Shares Price: ". $shares_price);

                        //get max shares per Member
                        $max_shares_per_member = $setting->max_shares_per_member;

                        // check shares already bought
                        $total_shares_bought = Shares::find()->where(['group_id' => $group_id])->sum('shares_bought') ?? 0;
                        //Yii::error("Total Shares bought: ". $total_shares_bought);

                        // get remaining shares to be bought
                        $remaining_shares = 100 - $total_shares_bought;
                        //Yii::error("Shares Remain: ". $remaining_shares);


                        // check if the user has already bought shares
                        $user_shares = Shares::find()->where(['group_id' => $group_id, 'member_id' => $user_id])->sum('shares_bought')?? 0;

                        // check to get the amount of shares remain for Member to buy
                        $remaining_shares_for_member = $max_shares_per_member - $user_shares;
                        //Yii::error("Remain Shares for member: ". $remaining_shares_for_member);


                        //get shares to be bought
                        $shares_to_be_bought = $amount / $shares_price;
                        //Yii::error("Shares to be bought: ". $shares_to_be_bought);


                        if ($shares_to_be_bought > $remaining_shares_for_member) throw new HttpException(255, 'You have exceeded your max shares per Member remaining shares you can buy is: ' .$remaining_shares_for_member, 13);

                        
                        // check exceed max shares
                        if ($remaining_shares < $shares_to_be_bought) throw new HttpException(255, 'Shares limit exceeded', 13);

                        $model = new Shares();
                        $model->group_id = $group_id;
                        $model->member_id = $user_id;
                        $model->amount_paid = $amount;
                        $model->shares_bought = $shares_to_be_bought;
                        $model->bought_at = date('Y-m-d H:i:s');
                        $model->save(false);

                        //generate notification
                        $login_user_details = User::find()->where(['id' => $user_id])->one();
                        //get group owner package details
                        $package  = Yii::$app->helper->getPackagebyGroupID($group_id);
                        if ($package['success']) {
                            $package_details = $package['package_details'];
                            $params = [
                                'user_id' => $user_id,
                                'user_name' => $login_user_details->name,
                                'lang' => $login_user_details->language,
                                'type' => $package_details->notification_support,
                                'group_id' => $group_id,
                                'group_type' => 'Kikoba',
                                'group_name' => $group_details->name,
                                'process' => 'Buy Shares',
                            ];

                            $record_notification = Helper::generateNotification($params);

                            if (!$record_notification){
                                throw new HttpException(255, "Failed to generate notification", 13);
                            }
                        }
                    }
                    else if ($payment->payment_for == 'Contribution'){
                        $paidAmount = $amount;
                        // Fetch unpaid contribution schema records ordered by due_date
                        $contributions = ContributionSchedule::find()
                            ->where(['user_id' => $user_id, 'group_id' => $group_id])
                            ->andWhere(['is_paid' => 0])
                            ->orderBy(['due_date' => SORT_ASC])
                        ->all();

                        foreach ($contributions as $contribution) {
                            if ($paidAmount <= 0) {
                                break;
                            }

                            $remaining = $contribution->remain_amount;

                            if ($paidAmount >= $remaining) {
                                // Full payment for this record
                                $contribution->paid_amount += $remaining;
                                $contribution->remain_amount = 0;
                                $contribution->is_paid = 1;
                                $contribution->paid_at = date('Y-m-d H:i:s');
                                $paidAmount -= $remaining;
                            } else {
                                // Partial payment
                                $contribution->paid_amount += $paidAmount;
                                $contribution->remain_amount -= $paidAmount;
                                $paidAmount = 0;
                            }

                            $contribution->save(false); 
                        }
                        //genereate notification
                        $payer_id = $user_id;
                        $payer_details = User::find()->where(['id' => $payer_id])->one();
                        //get group owner package details
                        $package  = Yii::$app->helper->getPackagebyGroupID($group_id);
                        if ($package['success']) {
                            $package_details = $package['package_details'];
                        }
                        $params = [
                            'user_id' => $user_id,
                            'user_name' => $payer_details->name,
                            'group_name' => $group_details->name,
                            'group_type'=> $group_details->type,
                            'group_id' => $group_details->id,
                            'amount' => $amount,
                            'lang' => $payer_details->language,
                            'type' => $package_details->notification_support,
                            'process' => 'approvedPayment',
                        ];
            
                        $record_notification = Helper::generateNotification($params);
                        if (!$record_notification){
                            throw new HttpException(255, 'Payment Completed  successfully failed to generete notification', 9);
                        }
                    }
                }
                else if ($group_details->type == 'Mchezo'){
                    if ($$payment->payment_type == 'Contribution'){
                        $paidAmount = $amount;
                        // Fetch unpaid contribution schema records ordered by due_date
                        $contributions = ContributionSchedule::find()
                            ->where(['user_id' => $user_id, 'group_id' => $group_id])
                            ->andWhere(['is_paid' => 0])
                            ->orderBy(['due_date' => SORT_ASC])
                        ->all();

                        foreach ($contributions as $contribution) {
                            if ($paidAmount <= 0) {
                                break;
                            }

                            $remaining = $contribution->remain_amount;

                            if ($paidAmount >= $remaining) {
                                // Full payment for this record
                                $contribution->paid_amount += $remaining;
                                $contribution->remain_amount = 0;
                                $contribution->is_paid = 1;
                                $contribution->paid_at = date('Y-m-d H:i:s');
                                $paidAmount -= $remaining;
                            } else {
                                // Partial payment
                                $contribution->paid_amount += $paidAmount;
                                $contribution->remain_amount -= $paidAmount;
                                $paidAmount = 0;
                            }

                            $contribution->save(false); 
                        }
                        //genereate notification
                        $payer_id = $user_id;
                        $payer_details = User::find()->where(['id' => $payer_id])->one();
                        //get group owner package details
                        $package  = Yii::$app->helper->getPackagebyGroupID($group_id);
                        if ($package['success']) {
                            $package_details = $package['package_details'];
                        }
                        $params = [
                            'user_id' => $user_id,
                            'user_name' => $payer_details->name,
                            'group_name' => $group_details->name,
                            'group_type'=> $group_details->type,
                            'group_id' => $group_details->id,
                            'amount' => $amount,
                            'lang' => $payer_details->language,
                            'type' => $package_details->notification_support,
                            'process' => 'approvedPayment',
                        ];
            
                        $record_notification = Helper::generateNotification($params);
                        if (!$record_notification){
                            throw new HttpException(255, 'Payment Completed  successfully failed to generete notification', 9);
                        }
                    }
                }
                else if ($group_details->type == 'Ujamaa'){
                    if ($$payment->payment_type == 'Contribution'){
                        $paidAmount = $amount;
                        // Fetch unpaid contribution schema records ordered by due_date
                        $contributions = ContributionSchedule::find()
                            ->where(['user_id' => $user_id, 'group_id' => $group_id])
                            ->andWhere(['is_paid' => 0])
                            ->orderBy(['due_date' => SORT_ASC])
                            ->all();

                        foreach ($contributions as $contribution) {
                            if ($paidAmount <= 0) {
                                break;
                            }

                            $remaining = $contribution->remain_amount;

                            if ($paidAmount >= $remaining) {
                                // Full payment for this record
                                $contribution->paid_amount += $remaining;
                                $contribution->remain_amount = 0;
                                $contribution->is_paid = 1;
                                $contribution->paid_at = date('Y-m-d H:i:s');
                                $paidAmount -= $remaining;
                            } else {
                                // Partial payment
                                $contribution->paid_amount += $paidAmount;
                                $contribution->remain_amount -= $paidAmount;
                                $paidAmount = 0;
                            }

                            $contribution->save(false); 
                        }
                        //genereate notification
                        $payer_id = $user_id;
                        $payer_details = User::find()->where(['id' => $payer_id])->one();
                        //get group owner package details
                        $package  = Yii::$app->helper->getPackagebyGroupID($group_id);
                        if ($package['success']) {
                            $package_details = $package['package_details'];
                        }
                        $params = [
                            'user_id' => $user_id,
                            'user_name' => $payer_details->name,
                            'group_name' => $group_details->name,
                            'group_type'=> $group_details->type,
                            'group_id' => $group_details->id,
                            'amount' => $amount,
                            'lang' => $payer_details->language,
                            'type' => $package_details->notification_support,
                            'process' => 'approvedPayment',
                        ];
        
                        $record_notification = Helper::generateNotification($params);
                        if (!$record_notification){
                            throw new HttpException(255, 'Payment Completed  successfully failed to generete notification', 9);
                        }
                    }
                }

            }
        }  
    }
}