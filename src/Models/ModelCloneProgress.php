<?php

namespace Bkwld\Cloner\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModelCloneProgress extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function modelClone(): BelongsTo
    {
        return $this->belongsTo(ModelClone::class);
    }

    public function source()
    {
        return $this->morphTo(type: "model_type", id: "source_id");
    }

    public function clone()
    {
        return $this->morphTo(type: "model_type", id: "clone_id");
    }
}
