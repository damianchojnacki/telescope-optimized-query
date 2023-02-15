<?php

namespace Laravel\Telescope\Watchers;

use Illuminate\Database\Events\QueryExecuted;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;

class QueryWatcher extends Watcher
{
    use FetchesStackTrace;

    /**
     * Register the watcher.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */
    public function register($app)
    {
        $app['events']->listen(QueryExecuted::class, [$this, 'recordQuery']);
    }

    /**
     * Record a query was executed.
     *
     * @param  \Illuminate\Database\Events\QueryExecuted  $event
     * @return void
     */
    public function recordQuery(QueryExecuted $event)
    {
        if (! Telescope::isRecording()) {
            return;
        }

        $time = $event->time;

        $caller = $this->getCallerFromStackTrace();

        if (!$caller) {
            return;
        }

        $entry = IncomingEntry::make([
            'connection' => $event->connectionName,
            'bindings' => [],
            'sql' => $this->replaceBindings($event),
            'time' => number_format($time, 2, '.', ''),
            'slow' => isset($this->options['slow']) && $time >= $this->options['slow'],
            'file' => $caller['file'],
            'line' => $caller['line'],
            'hash' => $this->familyHash($event),
            'duplicates' => 0,
        ])->tags($this->tags($event));

        if($duplicateKey = collect(Telescope::$entriesQueue)
            ->filter(fn(IncomingEntry $incomingEntry) =>
                $incomingEntry->type == EntryType::QUERY &&
                ($incomingEntry->content['hash'] ?? null) == $entry->content['hash']
            )->keys()->first()
        ){
            $duplicateEntry = Telescope::$entriesQueue[$duplicateKey];

            $entry->content['duplicates'] += $duplicateEntry->content['duplicates'] + 1;
            $entry->content['time'] = number_format(
                (float) $entry->content['time'] + (float) $duplicateEntry->content['time'],
                2,
                '.',
                ''
            );
            $entry->content['slow'] = isset($this->options['slow']) && $entry->content['time'] >= $this->options['slow'];

            unset(Telescope::$entriesQueue[$duplicateKey]);
        }

        Telescope::recordQuery($entry);
    }

    /**
     * Get the tags for the query.
     *
     * @param  \Illuminate\Database\Events\QueryExecuted  $event
     * @return array
     */
    protected function tags($event)
    {
        return isset($this->options['slow']) && $event->time >= $this->options['slow'] ? ['slow'] : [];
    }

    /**
     * Calculate the family look-up hash for the query event.
     *
     * @param  \Illuminate\Database\Events\QueryExecuted  $event
     * @return string
     */
    public function familyHash($event)
    {
        return md5($event->sql);
    }

    /**
     * Format the given bindings to strings.
     *
     * @param  \Illuminate\Database\Events\QueryExecuted  $event
     * @return array
     */
    protected function formatBindings($event)
    {
        return $event->connection->prepareBindings($event->bindings);
    }

    /**
     * Replace the placeholders with the actual bindings.
     *
     * @param  \Illuminate\Database\Events\QueryExecuted  $event
     * @return string
     */
    public function replaceBindings($event)
    {
        $sql = $event->sql;

        foreach ($this->formatBindings($event) as $key => $binding) {
            $regex = is_numeric($key)
                ? "/\?(?=(?:[^'\\\']*'[^'\\\']*')*[^'\\\']*$)/"
                : "/:{$key}(?=(?:[^'\\\']*'[^'\\\']*')*[^'\\\']*$)/";

            if ($binding === null) {
                $binding = 'null';
            } elseif (! is_int($binding) && ! is_float($binding)) {
                $binding = $this->quoteStringBinding($event, $binding);
            }

            $sql = preg_replace($regex, $binding, $sql, 1);
        }

        return $sql;
    }

    /**
     * Add quotes to string bindings.
     *
     * @param  \Illuminate\Database\Events\QueryExecuted  $event
     * @param  string  $binding
     * @return string
     */
    protected function quoteStringBinding($event, $binding)
    {
        try {
            return $event->connection->getPdo()->quote($binding);
        } catch (\PDOException $e) {
            throw_if('IM001' !== $e->getCode(), $e);
        }

        // Fallback when PDO::quote function is missing...
        $binding = \strtr($binding, [
            chr(26) => '\\Z',
            chr(8) => '\\b',
            '"' => '\"',
            "'" => "\'",
            '\\' => '\\\\',
        ]);

        return "'".$binding."'";
    }
}
