<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "groups".
 *
 * @property int $id
 * @property string $name
 * @property string $description
 * @property string $logo_url
 * @property int $created_by
 * @property string $created_at
 * @property string $type
 *
 */
class Groups extends ActiveRecord
{
    public static function tableName()
    {
        return 'groups';
    }
}
