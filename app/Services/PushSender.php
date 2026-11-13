<?php

namespace App\Services;

interface PushSender
{
    /** @param string[] $tokens */
    public function send(array $tokens, string $title, string $body): void;
}
