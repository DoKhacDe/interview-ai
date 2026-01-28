<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = ['interview_session_id', 'role', 'content'];

    public function interviewSession()
    {
        return $this->belongsTo(InterviewSession::class);
    }
}
