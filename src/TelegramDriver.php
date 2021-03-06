<?php

namespace BotMan\Drivers\Telegram;

use Illuminate\Support\Collection;
use BotMan\BotMan\Drivers\HttpDriver;
use BotMan\BotMan\Messages\Incoming\Answer;
use BotMan\BotMan\Messages\Attachments\File;
use BotMan\Drivers\Telegram\Extensions\User;
use BotMan\BotMan\Messages\Attachments\Audio;
use BotMan\BotMan\Messages\Attachments\Image;
use BotMan\BotMan\Messages\Attachments\Video;
use BotMan\BotMan\Messages\Outgoing\Question;
use Symfony\Component\HttpFoundation\Request;
use BotMan\BotMan\Drivers\Events\GenericEvent;
use Symfony\Component\HttpFoundation\Response;
use BotMan\BotMan\Messages\Attachments\Location;
use Symfony\Component\HttpFoundation\ParameterBag;
use BotMan\BotMan\Messages\Incoming\IncomingMessage;
use BotMan\BotMan\Messages\Outgoing\OutgoingMessage;
use BotMan\Drivers\Telegram\Exceptions\TelegramException;

class TelegramDriver extends HttpDriver
{
    const DRIVER_NAME = 'Telegram';

    protected $endpoint = 'sendMessage';

    protected $messages = [];

    /**
     * @param Request $request
     */
    public function buildPayload(Request $request)
    {
        $this->payload = new ParameterBag((array) json_decode($request->getContent(), true));
        $this->event = Collection::make($this->payload->get('message'));
        $this->config = Collection::make($this->config->get('telegram'));
    }

    /**
     * @param IncomingMessage $matchingMessage
     * @return User
     * @throws TelegramException
     */
    public function getUser(IncomingMessage $matchingMessage)
    {
        $parameters = [
            'chat_id' => $matchingMessage->getRecipient(),
            'user_id' => $matchingMessage->getSender(),
        ];

        $response = $this->http->post('https://api.telegram.org/bot'.$this->config->get('token').'/getChatMember',
            [], $parameters);

        $responseData = json_decode($response->getContent(), true);

        if ($response->getStatusCode() !== 200) {
            throw new TelegramException('Error retrieving user info: '.$responseData['description']);
        }

        $userData = Collection::make($responseData['result']['user']);

        return new User($userData->get('id'), $userData->get('first_name'), $userData->get('last_name'),
            $userData->get('username'), $responseData['result']);
    }

    /**
     * Determine if the request is for this driver.
     *
     * @return bool
     */
    public function matchesRequest()
    {
        $noAttachments = $this->event->keys()->filter(function ($key) {
            return in_array($key, ['audio', 'voice', 'video', 'photo', 'location', 'document']);
        })->isEmpty();

        return $noAttachments && (! is_null($this->event->get('from')) || ! is_null($this->payload->get('callback_query'))) && ! is_null($this->payload->get('update_id'));
    }

    /**
     * @return bool
     */
    public function hasMatchingEvent()
    {
        $event = false;
        if ($this->event->has('new_chat_members')) {
            $event = new GenericEvent($this->event->get('new_chat_members'));
            $event->setName('new_chat_members');
        }

        if ($this->event->has('left_chat_member')) {
            $event = new GenericEvent($this->event->get('left_chat_member'));
            $event->setName('left_chat_member');
        }

        if ($this->event->has('new_chat_title')) {
            $event = new GenericEvent($this->event->get('new_chat_title'));
            $event->setName('new_chat_title');
        }

        if ($this->event->has('new_chat_photo')) {
            $event = new GenericEvent($this->event->get('new_chat_photo'));
            $event->setName('new_chat_photo');
        }

        return $event;
    }

    /**
     * This hide the inline keyboard, if is an interactive message.
     */
    public function messagesHandled()
    {
        $callback = $this->payload->get('callback_query');

        if ($callback !== null) {
            $callback['message']['chat']['id'];
            $this->removeInlineKeyboard($callback['message']['chat']['id'],
                $callback['message']['message_id']);
        }
    }

    /**
     * @param  \BotMan\BotMan\Messages\Incoming\IncomingMessage $message
     * @return Answer
     */
    public function getConversationAnswer(IncomingMessage $message)
    {
        if ($this->payload->get('callback_query') !== null) {
            $callback = Collection::make($this->payload->get('callback_query'));

            return Answer::create($callback->get('data'))
                ->setInteractiveReply(true)
                ->setMessage($message)
                ->setValue($callback->get('data'));
        }

        return Answer::create($message->getText())->setMessage($message);
    }

    /**
     * Retrieve the chat message.
     *
     * @return array
     */
    public function getMessages()
    {
        if (empty($this->messages)) {
            $this->loadMessages();
        }

        return $this->messages;
    }

    /**
     * Load Telegram messages.
     *
     * @return array
     */
    public function loadMessages()
    {
        if ($this->payload->get('callback_query') !== null) {
            $callback = Collection::make($this->payload->get('callback_query'));

            $messages = [
                new IncomingMessage($callback->get('data'), $callback->get('from')['id'],
                    $callback->get('message')['chat']['id'], $callback->get('message')),
            ];
        } else {
            $messages = [
                new IncomingMessage($this->event->get('text'), $this->event->get('from')['id'], $this->event->get('chat')['id'],
                    $this->event),
            ];
        }

        $this->messages = $messages;
    }

    /**
     * @return bool
     */
    public function isBot()
    {
        return false;
    }

    /**
     * @param IncomingMessage $matchingMessage
     * @return Response
     */
    public function types(IncomingMessage $matchingMessage)
    {
        $parameters = [
            'chat_id' => $matchingMessage->getRecipient(),
            'action' => 'typing',
        ];

        return $this->http->post('https://api.telegram.org/bot'.$this->config->get('token').'/sendChatAction', [],
            $parameters);
    }

    /**
     * Convert a Question object into a valid
     * quick reply response object.
     *
     * @param \BotMan\BotMan\Messages\Outgoing\Question $question
     * @return array
     */
    private function convertQuestion(Question $question)
    {
        function isAssoc(array $arr) {
            return array_keys($arr) !== range(0, count($arr) - 1);
        }

        $prepare = function () {
            return function (array $button) {
                return array_merge([
                    'text' => (string) $button['text'],
                    'callback_data' => (string) $button['value'],
                ], $button['additional']);
            };
        };

        $replies = Collection::make($question->getButtons())->map(function ($button) use ($prepare) {
            if (isAssoc($button)) {
                return [ $prepare()($button) ];
            }

            return array_map($prepare(), $button);
        });

        return $replies->toArray();
    }

    /**
     * Removes the inline keyboard from an interactive
     * message.
     * @param  int $chatId
     * @param  int $messageId
     * @return Response
     */
    private function removeInlineKeyboard($chatId, $messageId)
    {
        $parameters = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'inline_keyboard' => [],
        ];

        return $this->http->post('https://api.telegram.org/bot'.$this->config->get('token').'/editMessageReplyMarkup',
            [], $parameters);
    }

    /**
     * @param string|Question|IncomingMessage $message
     * @param \BotMan\BotMan\Messages\Incoming\IncomingMessage $matchingMessage
     * @param array $additionalParameters
     * @return Response
     */
    public function buildServicePayload($message, $matchingMessage, $additionalParameters = [])
    {
        $recipient = $matchingMessage->getRecipient() === '' ? $matchingMessage->getSender() : $matchingMessage->getRecipient();
        $parameters = array_merge_recursive([
            'chat_id' => $recipient,
        ], $additionalParameters);
        /*
         * If we send a Question with buttons, ignore
         * the text and append the question.
         */
        if ($message instanceof Question) {
            $parameters['text'] = $message->getText();
            $key = isset($additionalParameters['keyboard']) && $additionalParameters['keyboard'] === 'standard' ?
                'keyboard' : 'inline_keyboard';

            $parameters['reply_markup'] = json_encode([
                $key => $this->convertQuestion($message),
            ], true);
        } elseif ($message instanceof OutgoingMessage) {
            if ($message->getAttachment() !== null) {
                $attachment = $message->getAttachment();
                $parameters['caption'] = $message->getText();
                if ($attachment instanceof Image) {
                    if (strtolower(pathinfo($attachment->getUrl(), PATHINFO_EXTENSION)) === 'gif') {
                        $this->endpoint = 'sendDocument';
                        $parameters['document'] = $attachment->getUrl();
                    } else {
                        $this->endpoint = 'sendPhoto';
                        $parameters['photo'] = $attachment->getUrl();
                    }
                    // If has a title, overwrite the caption
                    if ($attachment->getTitle() !== null) {
                        $parameters['caption'] = $attachment->getTitle();
                    }
                } elseif ($attachment instanceof Video) {
                    $this->endpoint = 'sendVideo';
                    $parameters['video'] = $attachment->getUrl();
                } elseif ($attachment instanceof Audio) {
                    $this->endpoint = 'sendAudio';
                    $parameters['audio'] = $attachment->getUrl();
                } elseif ($attachment instanceof File) {
                    $this->endpoint = 'sendDocument';
                    $parameters['document'] = $attachment->getUrl();
                } elseif ($attachment instanceof Location) {
                    $this->endpoint = 'sendLocation';
                    $parameters['latitude'] = $attachment->getLatitude();
                    $parameters['longitude'] = $attachment->getLongitude();
                }
            } else {
                $parameters['text'] = $message->getText();
            }
        } else {
            $parameters['text'] = $message;
        }

        return $parameters;
    }

    /**
     * @param mixed $payload
     * @return Response
     */
    public function sendPayload($payload)
    {
        return $this->http->post('https://api.telegram.org/bot'.$this->config->get('token').'/'.$this->endpoint,
            [], $payload);
    }

    /**
     * @return bool
     */
    public function isConfigured()
    {
        return ! empty($this->config->get('token'));
    }

    /**
     * Low-level method to perform driver specific API requests.
     *
     * @param string $endpoint
     * @param array $parameters
     * @param \BotMan\BotMan\Messages\Incoming\IncomingMessage $matchingMessage
     * @return Response
     */
    public function sendRequest($endpoint, array $parameters, IncomingMessage $matchingMessage)
    {
        $parameters = array_replace_recursive([
            'chat_id' => $matchingMessage->getRecipient(),
        ], $parameters);

        return $this->http->post('https://api.telegram.org/bot'.$this->config->get('token').'/'.$endpoint, [],
            $parameters);
    }
}
