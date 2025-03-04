<?php

namespace Bkwld\Cloner\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ModelClone extends Model
{
    use HasFactory;

    protected $guarded = [];


    public function modelCloneProgresses()
    {
        return $this->hasMany(ModelCloneProgress::class);
    }
}
