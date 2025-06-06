<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "member_extra_amount".
 *
 * @property int $id
 * @property int $group_id
 * @property int $member_id
 * @property float $extra_amount
 * @property string $created_at
 * @property string $updated_at
 */
class MemberExtraAmount extends ActiveRecord
{
    public static function tableName()
    {
        return 'member_extra_amount';
    }

}
