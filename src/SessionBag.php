<?php

namespace VigilantForm\Kit;

/**
 * Very simple session wrapper, to allow VigilantFormKit to use either
 * a Laravel Session or PHP Session with same interface.
 *
 * Will only open a session if it is not already started.
 * Will only close the session if this class opened the session.
 *
 * Note: once save() is called, the session is closed and can not be used again.
 */
class SessionBag
{
    /** @var bool true if we should close the session when done */
    protected $close;

    /**
     * Will open a session if it is not already started.
     */
    public function __construct()
    {
        $this->close = false;

        if (!isset($_SESSION)) {
            session_start();
            $this->close = true;
        }
    }

    /**
     * Will close the session, but only if we opened it.
     */
    public function __destruct()
    {
        if ($this->close) {
            session_write_close();
        }
    }

    /**
     * Will close the session, but only if we opened it.
     * @return bool Returns true on success, otherwise false.
     */
    public function save(): bool
    {
        if ($this->close) {
            $this->close = false;
            return session_write_close();
        }
        return false;
    }

    /**
     * A field which exists within the session may be set to null.
     * @param string $key The name of the field within the session we are checking if exists.
     * @return bool Returns true if field is defined within the session, otherwise false.
     */
    public function exists(string $key): bool
    {
        return array_key_exists($key, $_SESSION);
    }

    /**
     * A field which exists within the session may be set to null; $default defaults to null.
     * @param string $key The name of the field within the session we want to get.
     * @param mixed $default Optional, what value to return should the session not have the field specified.
     * @return mixed Returns the value of the fields within the session specified, otherwise the given default.
     */
    public function get(string $key, $default = null)
    {
        return array_key_exists($key, $_SESSION) ? $_SESSION[$key] : $default;
    }

    /**
     * @param string $key The name of the field within the session we want to store.
     * @param mixed $value The value to store within this session field.
     */
    public function put(string $key, $value = null): void
    {
        $_SESSION[$key] = $value;
    }
}
