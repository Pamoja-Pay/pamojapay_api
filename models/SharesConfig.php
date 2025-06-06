<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "shares_config".
 *
 * @property int $id
 * @property int $group_id
 * @property float $share_price
 * @property int $max_shares_per_member
 * @property bool $allow_selling
 * @property string $buy_period_start
 * @property string $buy_period_end
 */
class SharesConfig extends ActiveRecord
{
    public static function tableName()
    {
        return 'shares_config';
    }

}
