<?php

namespace VigilantForm\Kit;

/**
 * Very simple session wrapper, to allow VigilantFormKit use either
 * Laravel Session or PHP Session with same interface.
 *
 * Will only open a session if one isn't already started.
 *
 * Note: once save() is called, the session is closed and can not be used.
 */
class SessionBag
{
    /** @var bool true if we should close the session when done */
    protected $close;

    public function __construct()
    {
        $this->close = false;

        if (!isset($_SESSION)) {
            session_start();
            $this->close = true;
        }
    }

    public function __destruct()
    {
        if ($this->close) {
            session_write_close();
        }
    }

    public function save(): void
    {
        session_write_close();
        $this->close = false;
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $_SESSION);
    }

    public function get(string $key, $default = null)
    {
        return array_key_exists($key, $_SESSION) ? $_SESSION[$key] : $default;
    }

    public function put(string $key, $value = null): void
    {
        $_SESSION[$key] = $value;
    }
}
