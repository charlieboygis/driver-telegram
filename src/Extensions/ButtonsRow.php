<?php

namespace BotMan\Drivers\Telegram\Extensions;

use Illuminate\Contracts\Support\Arrayable;

class ButtonsRow implements Arrayable
{
    private $buttons = [];

    public static function create(...$buttons)
    {
        if (count($buttons) === 1 && is_array($buttons[0])) {
            $buttons = $buttons[0];
        }

        $i = new self();
        $i->buttons = array_map(function ($b) { return $b->toArray(); }, $buttons);

        return $i;
    }

    public function toArray()
    {
        return $this->buttons;
    }
}
