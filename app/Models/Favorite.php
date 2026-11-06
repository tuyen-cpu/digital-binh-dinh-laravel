<?php

namespace App\Models;

class Favorite extends ApiModel
{
    public const UPDATED_AT = null;

    protected $hidden = ['user_id'];
}
