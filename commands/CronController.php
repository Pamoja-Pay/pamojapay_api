<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use app\models\Groups;
use app\components\Helper;
use app\models\ContributionSchedule;

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

    public function actionGenerateNewSchedules()
    {
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
}