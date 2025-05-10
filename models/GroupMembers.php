<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "group_members".
 *
 * @property int $id
 * @property int $group_id
 * @property int $user_id
 * @property string $role
 * @property string $joined_at
 * @property boolean $is_active
 */
class GroupMembers extends ActiveRecord
{
    public static function tableName()
    {
        return 'group_members';
    }

}
