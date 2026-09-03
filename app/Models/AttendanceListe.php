<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceListe extends Model
{
    protected $table = 'attendance_lists'; 

    protected $fillable = [
        'user_id',
        'attendance_id',
        'attendance_day',
        'morning',
        'lunch',
        'evening',
        'face_verification_method',
        'face_match_confidence',
    ];

    public $timestamps = true; 
}
