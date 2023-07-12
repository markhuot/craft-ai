<?php

namespace markhuot\craftai\actions;

use Illuminate\Support\Collection;
use function markhuot\openai\helpers\web\session;

class HandleChatMessagesInSession
{
    const CACHE_KEY = 'craftai-messages';

    /**
     * @param  array<array-key, array{role: string, content: string}>  $default
     * @return Collection<array-key, array{role: string, content: string}> $messages
     */
    public function get(array $default = []): Collection
    {
        /** @var array{role: string, content: string} $messages */
        $messages = session()->get(self::CACHE_KEY, $default);

        /** @var Collection<array-key, array{role: string, content: string}> $messages */
        $messages = collect($messages);

        return $messages;
    }

    /**
     * @param  array<array-key, array{role: string, content: string}>  $messages
     */
    public function set(array $messages)
    {
        session()->set(self::CACHE_KEY, $messages);
    }

    public function clear()
    {
        session()->remove(self::CACHE_KEY);
    }
}
