<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Scene extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'municipal',
        'location',
        'barangay',
        'category',
        'address',
        'google_map_link',
        'contact_number',
        'email',
        'website',
        'facebook',
        'instagram',
        'tiktok',
        'panorama_path',
        'is_published',
    ];
}
