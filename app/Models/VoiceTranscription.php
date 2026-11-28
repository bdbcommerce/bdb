<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One voice message sent for transcription (VoiceTranscriber::transcribeVoice): the Telegram file ID and the text
 * the provider (NeuronAI) returned. status: ok | empty (no speech heard) | failed.
 */
class VoiceTranscription extends Model
{
    protected $fillable = ['file_id', 'file_unique_id', 'telegram_id', 'message_id', 'source', 'provider', 'status', 'text', 'language', 'seconds', 'cost', 'request_id', 'error'];

    protected function casts(): array
    {
        return ['seconds' => 'float', 'cost' => 'float', 'message_id' => 'integer'];
    }
}
