<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Movie extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'image_path',
        'duration',
        'director',
        'genre',
        'release_year'
    ];

    public function screenings()
    {
        return $this->hasMany(Screening::class);
    }
}
