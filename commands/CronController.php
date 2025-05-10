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

        $groups = Groups::find()->where(['type' => ['Kikoba', 'Saccos']])->all();

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
}