<?php

namespace App\Models;

use App\Models\Concerns\InteractsWithTrash;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class CustomRole extends Model
{
    use InteractsWithTrash, SoftDeletes;

    protected $fillable = ['role_key', 'name', 'description'];

    protected static function booted(): void
    {
        static::creating(function (self $role) {
            if (empty($role->role_key) && ! empty($role->name)) {
                $role->role_key = Str::slug($role->name);
            }
        });
    }
}
