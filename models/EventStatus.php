<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "event_status".
 *
 * @property int $id
 * @property int $group_id
 * @property string $status
 * @property string $updated_at
 * @property string $pledge_deadline
 * @property string $contribution_deadline
 */
class EventStatus extends ActiveRecord
{
    public static function tableName()
    {
        return 'event_status';
    }
}
