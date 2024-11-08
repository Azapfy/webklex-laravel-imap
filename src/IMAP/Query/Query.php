<?php
/*
 * File:     Query.php
 * Category: -
 * Author:   M. Goldenbaum
 * Created:  21.07.18 18:54
 * Updated:  -
 *
 * Description:
 *  -
 */

namespace Webklex\IMAP\Query;

use Carbon\Carbon;
use Webklex\IMAP\Client;
use Webklex\IMAP\Events\MessageNewEvent;
use Webklex\IMAP\Exceptions\GetMessagesFailedException;
use Webklex\IMAP\Exceptions\MessageSearchValidationException;
use Webklex\IMAP\IMAP;
use Webklex\IMAP\Message;
use Webklex\IMAP\Support\MessageCollection;

/**
 * Class Query
 *
 * @package Webklex\IMAP\Query
 */
class Query
{

    /** @var array $query */
    protected $query;

    /** @var string $raw_query  */
    protected $raw_query;

    /** @var string $charset */
    protected $charset;

    /** @var Client $client */
    protected $client;

    /** @var int $limit */
    protected $limit = null;

    /** @var int $page */
    protected $page = 1;

    /** @var int $fetch_options */
    protected $fetch_options = null;

    /** @var int $fetch_body */
    protected $fetch_body = true;

    /** @var int $fetch_attachment */
    protected $fetch_attachment = true;

    /** @var int $fetch_flags */
    protected $fetch_flags = true;

    /** @var string $date_format */
    protected $date_format;

    /**
     * Query constructor.
     * @param Client $client
     * @param string $charset
     */
    public function __construct(Client $client, $charset = 'UTF-8')
    {
        $this->setClient($client);

        if (config('imap.options.fetch') === IMAP::FT_PEEK)
            $this->leaveUnread();

        $this->date_format = config('imap.date_format', 'd M y');

        $this->charset = $charset;
        $this->query = collect();
        $this->boot();
    }

    /**
     * Instance boot method for additional functionality
     */
    protected function boot()
    {
    }

    /**
     * Parse a given value
     * @param mixed $value
     *
     * @return string
     */
    protected function parse_value($value)
    {
        switch (true) {
            case $value instanceof \Carbon\Carbon:
                $value = $value->format($this->date_format);
                break;
        }

        return (string) $value;
    }

    /**
     * Check if a given date is a valid carbon object and if not try to convert it
     * @param $date
     *
     * @return Carbon
     * @throws MessageSearchValidationException
     */
    protected function parse_date($date)
    {
        if ($date instanceof \Carbon\Carbon)
            return $date;

        try {
            $date = Carbon::parse($date);
        } catch (\Exception $e) {
            throw new MessageSearchValidationException("Invalid date provided.", 1200, $e);
        }

        return $date;
    }

    /**
     * Don't mark messages as read when fetching
     *
     * @return $this
     */
    public function leaveUnread()
    {
        $this->setFetchOptions(IMAP::FT_PEEK);

        return $this;
    }

    /**
     * Mark all messages as read when fetching
     *
     * @return $this
     */
    public function markAsRead()
    {
        $this->setFetchOptions(IMAP::FT_UID);

        return $this;
    }

    /**
     * Perform an imap search request
     *
     * @return \Illuminate\Support\Collection
     * @throws \Webklex\IMAP\Exceptions\ConnectionFailedException
     */
    protected function search()
    {
        $this->generate_query();
        $available_messages = [];

        /**
         * Don't set the charset if it isn't used - prevent strange outlook mail server errors
         * @see https://github.com/Webklex/laravel-imap/issues/100
         *
         * If a "BADCHARSET" error gets caught it will be used to determine the desired charset.
         */
        try {
            if ($this->getCharset() == null) {
                $available_messages = \imap_search($this->getClient()->getConnection(), $this->getRawQuery(), IMAP::SE_UID);
            } else {
                $available_messages = \imap_search($this->getClient()->getConnection(), $this->getRawQuery(), IMAP::SE_UID, $this->getCharset());
            }
        } catch (\Exception $e) {
            if (strpos($e, ' [BADCHARSET (')) {
                preg_match('/ \[BADCHARSET \((.*)\)\]/', $e, $matches);
                if (isset($matches[1])) {
                    if ($matches[1] !== $this->getCharset()) {
                        $this->setCharset($matches[1]);
                        return $this->search();
                    }
                }
            }
        }

        if ($available_messages !== false) {
            return collect($available_messages);
        }

        return collect();
    }

    /**
     * Count all available messages matching the current search criteria
     *
     * @return int
     * @throws \Webklex\IMAP\Exceptions\ConnectionFailedException
     */
    public function count()
    {
        return $this->search()->count();
    }

    /**
     * Fetch the current query and return all found messages
     *
     * @return MessageCollection
     * @throws GetMessagesFailedException
     */
    public function get()
    {
        $messages = MessageCollection::make([]);

        try {
            $available_messages = $this->search();
            $available_messages_count = $available_messages->count();

            if ($available_messages_count > 0) {

                $messages->total($available_messages_count);

                $options = config('imap.options');

                if (strtolower($options['fetch_order']) === 'desc') {
                    $available_messages = $available_messages->reverse();
                }

                $query =& $this;

                $available_messages->forPage($this->page, $this->limit)->each(function ($msgno, $msglist) use (&$messages, $options, $query) {
                    $oMessage = new Message($msgno, $msglist, $query->getClient(), $query->getFetchOptions(), $query->getFetchBody(), $query->getFetchAttachment(), $query->getFetchFlags());
                    switch ($options['message_key']) {
                        case 'number':
                            $message_key = $oMessage->getMessageNo();
                            break;
                        case 'list':
                            $message_key = $msglist;
                            break;
                        default:
                            $message_key = empty($oMessage->getMessageId()) ? $oMessage->getMessageNo() : $oMessage->getMessageId();
                            break;

                    }
                    if ($messages->has($message_key)) {
                        $message_key = $message_key . 'duplicated' . random_int(1, 999999);
                    };
                    $messages->put($message_key, $oMessage);
                });
            }

            return $messages;
        } catch (\Exception $e) {
            throw new GetMessagesFailedException("Failed to fetch messages", 1201, $e);
        }
    }

    /**
     * Paginate the current query
     * @param int $per_page
     * @param null $page
     * @param string $page_name
     *
     * @return \Illuminate\Pagination\LengthAwarePaginator
     * @throws GetMessagesFailedException
     */
    public function paginate($per_page = 5, $page = null, $page_name = 'imap_page')
    {
        $this->page = $page > $this->page ? $page : $this->page;
        $this->limit = $per_page;

        return $this->get()->paginate($per_page, $this->page, $page_name);
    }

    /**
     * Create an idle like instance to catch incoming messages
     * @param callable|null $callback
     * @param int $timeout
     *
     * @throws \Webklex\IMAP\Exceptions\ConnectionFailedException
     * @throws \Webklex\IMAP\Exceptions\InvalidMessageDateException
     */
    public function idle(callable $callback = null, $timeout = 10)
    {
        $known_messages = [];
        $this->getClient()->overview()->each(function ($message) use (&$known_messages) {
            /** @var object $message */
            $known_messages[] = $message->uid;
        });
        while ($this->getClient()->isConnected()) {
            $this->getClient()->expunge();
            $new_messages = [];
            $this->getClient()->overview()->each(function ($message) use (&$new_messages, &$known_messages) {
                /** @var object $message */
                if (in_array($message->uid, $known_messages) == false) {
                    $new_messages[] = $message;
                    $known_messages[] = $message->uid;
                }
            });

            foreach ($new_messages as $msg) {
                $message = new Message($msg->uid, $msg->msgno, $this->getClient());
                MessageNewEvent::dispatch($message);
                if ($callback) {
                    $callback($message);
                }
            }

            sleep($timeout);
        }
    }

    /**
     * Get the raw IMAP search query
     *
     * @return string
     */
    public function generate_query()
    {
        $query = '';
        $this->query->each(function ($statement) use (&$query) {
            if (count($statement) == 1) {
                $query .= $statement[0];
            } else {
                if ($statement[1] === null) {
                    $query .= $statement[0];
                } else {
                    $query .= $statement[0] . ' "' . $statement[1] . '"';
                }
            }
            $query .= ' ';

        });

        $this->raw_query = trim($query);

        return $this->raw_query;
    }

    /**
     * @return Client
     * @throws \Webklex\IMAP\Exceptions\ConnectionFailedException
     */
    public function getClient()
    {
        $this->client->checkConnection();
        return $this->client;
    }

    /**
     * Set the limit and page for the current query
     * @param int $limit
     * @param int $page
     *
     * @return $this
     */
    public function limit($limit, $page = 1)
    {
        if ($page >= 1)
            $this->page = $page;
        $this->limit = $limit;

        return $this;
    }

    /**
     * @return array
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * @param array $query
     * @return Query
     */
    public function setQuery($query)
    {
        $this->query = $query;
        return $this;
    }

    /**
     * @return string
     */
    public function getRawQuery()
    {
        return $this->raw_query;
    }

    /**
     * @param string $raw_query
     * @return Query
     */
    public function setRawQuery($raw_query)
    {
        $this->raw_query = $raw_query;
        return $this;
    }

    /**
     * @return string
     */
    public function getCharset()
    {
        return $this->charset;
    }

    /**
     * @param string $charset
     * @return Query
     */
    public function setCharset($charset)
    {
        $this->charset = $charset;
        return $this;
    }

    /**
     * @param Client $client
     * @return Query
     */
    public function setClient(Client $client)
    {
        $this->client = $client;
        return $this;
    }

    /**
     * @return int
     */
    public function getLimit()
    {
        return $this->limit;
    }

    /**
     * @param int $limit
     * @return Query
     */
    public function setLimit($limit)
    {
        $this->limit = $limit <= 0 ? null : $limit;
        return $this;
    }

    /**
     * @return int
     */
    public function getPage()
    {
        return $this->page;
    }

    /**
     * @param int $page
     * @return Query
     */
    public function setPage($page)
    {
        $this->page = $page;
        return $this;
    }

    /**
     * @param boolean $fetch_options
     * @return Query
     */
    public function setFetchOptions($fetch_options)
    {
        $this->fetch_options = $fetch_options;
        return $this;
    }

    /**
     * @param boolean $fetch_options
     * @return Query
     */
    public function fetchOptions($fetch_options)
    {
        return $this->setFetchOptions($fetch_options);
    }

    /**
     * @return int
     */
    public function getFetchOptions()
    {
        return $this->fetch_options;
    }

    /**
     * @return boolean
     */
    public function getFetchBody()
    {
        return $this->fetch_body;
    }

    /**
     * @param boolean $fetch_body
     * @return Query
     */
    public function setFetchBody($fetch_body)
    {
        $this->fetch_body = $fetch_body;
        return $this;
    }

    /**
     * @param boolean $fetch_body
     * @return Query
     */
    public function fetchBody($fetch_body)
    {
        return $this->setFetchBody($fetch_body);
    }

    /**
     * @return boolean
     */
    public function getFetchAttachment()
    {
        return $this->fetch_attachment;
    }

    /**
     * @param boolean $fetch_attachment
     * @return Query
     */
    public function setFetchAttachment($fetch_attachment)
    {
        $this->fetch_attachment = $fetch_attachment;
        return $this;
    }

    /**
     * @param boolean $fetch_attachment
     * @return Query
     */
    public function fetchAttachment($fetch_attachment)
    {
        return $this->setFetchAttachment($fetch_attachment);
    }

    /**
     * @return int
     */
    public function getFetchFlags()
    {
        return $this->fetch_flags;
    }

    /**
     * @param int $fetch_flags
     * @return Query
     */
    public function setFetchFlags($fetch_flags)
    {
        $this->fetch_flags = $fetch_flags;
        return $this;
    }
}
