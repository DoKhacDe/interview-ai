<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InterviewSession extends Model
{
    protected $fillable = ['status', 'jd_id', 'cv_id', 'questions_id'];

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function jd()
    {
        return $this->belongsTo(Document::class, 'jd_id');
    }

    public function cv()
    {
        return $this->belongsTo(Document::class, 'cv_id');
    }

    public function questions()
    {
        return $this->belongsTo(Document::class, 'questions_id');
    }
}
