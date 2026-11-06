<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

abstract class ApiModel extends Model
{
    protected $guarded = [];

    public function toArray(): array
    {
        $data = [];
        foreach (parent::toArray() as $key => $value) {
            $data[Str::camel($key)] = $value;
        }

        return $data;
    }
}
