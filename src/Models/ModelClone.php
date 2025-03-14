<?php

namespace Bkwld\Cloner\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\MorphedByMany;

class ModelClone extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = ['additional_attributes' => AsArrayObject::class];

    /*
    *   additional_attributes.clone_exempt_classes: array of strings ['App\Models\User', ...]
    * 
    */


    public function modelCloneProgresses()
    {
        return $this->hasMany(ModelCloneProgress::class);
    }


    public function cloneExempts(string $modelClass): MorphToMany
    {
        return $this->morphedByMany($modelClass, 'exemptable', 'model_clone_exemptable', 'model_clone_id');
    }


    public function allCloneExempts(): MorphToMany
    {
        return $this->morphedByMany('*', 'exemptable', 'model_clone_exemptable', 'model_clone_id');
    }
}
