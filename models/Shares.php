<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "shares".
 *
 * @property int $id
 * @property int $group_id
 * @property int $member_id
 * @property float $amount_paid
 * @property float $shares_bought
 * @property string $bought_at
 * @property string $status
 */
class Shares extends ActiveRecord
{
    public static function tableName()
    {
        return 'shares';
    }

}
