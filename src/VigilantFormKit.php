<?php

namespace VigilantForm\Kit;

use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use InvalidArgumentException;
use UnderflowException;
use UnexpectedValueException;

/**
 * use VigilantForm\Kit\{VigilantFormKit, SessionBag};
 *
 * VigilantFormKit::setSession();
 * VigilantFormKit::trackSource();
 * On first page visited (or every page) will log meta and links.
 *
 * VigilantFormKit::setSession();
 * VigilantFormKit::generateForm($honeypot_name);
 * Will generate the html for the honeypot field.
 *
 * VigilantFormKit::setSession();
 * $vigilantFormKit = new VigilantFormKit($server_url, $client_id, $client_secret);
 * $vigilantFormKit->submitForm($website, $form_title, $form_fields, $honeypot_name);
 * Will determine if the user failed the honeypot test, calcualte duration,
 * and submit the form submission to the vigilant-form server.
 *
 * Note: the honeypot field name must not be the same name as a real field.
 */
class VigilantFormKit
{
    protected const DATE_FORMAT = 'Y-m-d H:i:s.u';

    /** @var object with put(), get(), and put(); such as SessionBag */
    protected static $session = null;

    /** @var string defaults to "vigilant_form." */
    protected static $prefix = null;

    /** @var int to make each form instance use a unique id */
    protected static $index = 1;

    /** @var string server-url */
    protected $url;

    /** @var array client-auth: ['id' => '', 'secret' => ''] */
    protected $auth;

    /* Public Static Functions ---------------------------------------------- */

    /**
     * @param object $session Object with exists(), get(), and put();, such as SessionBag.
     * @param string $prefix Prefix variables within session, to prevent collisions.
     * @throws InvalidArgumentException If the given $session object lacks required methods.
     */
    public static function setSession(object $session = null, string $prefix = 'vigilant_form.'): void
    {
        if (is_null($session)) {
            /* default to simple session */
            $session = new SessionBag();
        } elseif (!method_exists($session, 'exists') || !method_exists($session, 'get') || !method_exists($session, 'put')) {
            /* duck-footing failed, given storage object is insufficient */
            throw new InvalidArgumentException('Given session object lacks exists/get/put functions.');
        }

        static::$session = $session;
        static::$prefix  = $prefix;
    }

    /**
     * Sets various meta data, if not already set.
     */
    public static function trackSource(): void
    {
        static::needsSession();

        /* meta */
        if (!static::$session->exists(static::$prefix . 'ip_address')) {
            static::$session->put(static::$prefix . 'ip_address', ($_SERVER['REMOTE_ADDR'] ?? null));
        }
        if (!static::$session->exists(static::$prefix . 'user_agent')) {
            static::$session->put(static::$prefix . 'user_agent', ($_SERVER['HTTP_USER_AGENT'] ?? null));
        }
        if (!static::$session->exists(static::$prefix . 'http_headers')) {
            static::$session->put(static::$prefix . 'http_headers', static::getHttpHeaders());
        }

        /* links */
        if (!static::$session->exists(static::$prefix . 'referral')) {
            static::$session->put(static::$prefix . 'referral', ($_SERVER['HTTP_REFERER'] ?? null));
        }
        if (!static::$session->exists(static::$prefix . 'landing')) {
            static::$session->put(static::$prefix . 'landing', ($_SERVER['REQUEST_URI'] ?? null));
        }

        /* get when this page was requested */
        $time = DateTime::createFromFormat('U.u', $_SERVER['REQUEST_TIME_FLOAT']);

        /* last time they visited this specific page */
        static::$session->put(static::$prefix . "form_{$_SERVER['REQUEST_URI']}", $time->format(static::DATE_FORMAT));

        /* last time they visited any page, needed as a fallback */
        static::$session->put(static::$prefix . 'submit', ($_SERVER['REQUEST_URI'] ?? null));
        static::$session->put(static::$prefix . 'timestamp', $time->format(static::DATE_FORMAT));
    }

    /**
     * Honeypot logic: we use javascript to hide the input, and fill it with the correct answer.
     * If the client does not use javascript, they will be requested to answer a simple math question.
     * @param string $honeypot_name An HTML field name, whici is unique for the form.
     * @return Returns string of HTML which needs to be added to the form, for the honeypot.
     */
    public static function generateForm(string $honeypot_name): string
    {
        static::needsSession();

        /* get when this page was requested */
        $time = DateTime::createFromFormat('U.u', $_SERVER['REQUEST_TIME_FLOAT']);

        /* incrementing index to ensure each html ID is unique */
        $index = static::$index;
        static::$index++;

        [$second, $micro] = static::mathProblem($time);

        return <<<HTML
<div id="{$honeypot_name}_c{$index}">
    <label for="{$honeypot_name}_i{$index}">What is {$second} plus {$micro}?</label>
    <input type="text" id="{$honeypot_name}_i{$index}" name="{$honeypot_name}" autocomplete="off">
</div>
<script>
    document.getElementById("{$honeypot_name}_c{$index}").style.position   = "absolute";
    document.getElementById("{$honeypot_name}_c{$index}").style.height     = "11px";
    document.getElementById("{$honeypot_name}_c{$index}").style.width      = "11px";
    document.getElementById("{$honeypot_name}_c{$index}").style.textIndent = "11px";
    document.getElementById("{$honeypot_name}_c{$index}").style.overflow   = "hidden";

    document.getElementById("{$honeypot_name}_i{$index}").tabIndex = -1;
    document.getElementById("{$honeypot_name}_i{$index}").value = {$second} + {$micro};
</script>
HTML;
    }

    /* Public Functions ----------------------------------------------------- */

    /**
     * @param string $server_url
     * @param string $client_id
     * @param string $client_secret
     */
    public function __construct(string $server_url, string $client_id, string $client_secret)
    {
        $this->url  = $server_url;
        $this->auth = [
            'id'     => $client_id,
            'secret' => $client_secret,
        ];
    }

    /**
     * @param string $website
     * @param string $form_title
     * @param array $form_fields
     * @param string $honeypot_name
     * @return bool Returns true if the form was successfully submitted.
     * @throws UnexpectedValueException
     */
    public function submitForm(string $website, string $form_title, array $form_fields, string $honeypot_name): bool
    {
        static::needsSession();

        /*  determine the status of duration, honeypot, and submit */
        [$duration, $honeypot, $submit] = static::calculateMeta($form_fields[$honeypot_name] ?? null);

        /* remove the honeypot from the form fields */
        unset($form_fields[$honeypot_name]);

        /* submit form to the vigilant-form server */
        $guzzle = new Client([
            'allow_redirects' => false,
            'base_uri'        => $this->url,
            'timeout'         => 60.0,
            'headers'         => [
                'X-Requested-With' => 'XMLHttpRequest',
            ],
        ]);

        try {
            $response = $guzzle->post('', ['json' => [
                'auth'   => $this->auth,
                'fields' => $form_fields,
                'meta'   => [
                    'ip_address'   => static::$session->get(static::$prefix . 'ip_address'),
                    'user_agent'   => static::$session->get(static::$prefix . 'user_agent'),
                    'http_headers' => static::$session->get(static::$prefix . 'http_headers'),
                    'honeypot'     => $honeypot,
                    'duration'     => $duration,
                ],
                'source' => [
                    'website' => $website,
                    'title'   => $form_title,
                ],
                'links'  => [
                    'referral' => static::$session->get(static::$prefix . 'referral'),
                    'landing'  => static::$session->get(static::$prefix . 'landing'),
                    'submit'   => $submit,
                ],
            ]]);
            $data = json_decode((string)$response->getBody());

            if (isset($data->success)) {
                return true;
            } else {
                /* in the unlikely event we get an error message when calling the vigilant-form server, throw an exception */
                throw new UnexpectedValueException(implode("\n", $data->errors ?? ['Unsuccessful, but no errors specified.']));
            }
        } catch (RequestException $exception) {
            /* in the unlikely event we get an exception when calling the vigilant-form server, throw an exception */
            throw new UnexpectedValueException('Request to vigilant-form server threw an exception.', $exception->getCode(), $exception);
        }
    }

    /* Protected Static Functions ------------------------------------------- */

    /**
     * Ensure we have a session available for use.
     * @throws UnderflowException
     */
    protected static function needsSession(): void
    {
        if (is_null(static::$session)) {
            throw new UnderflowException('Session must be set before calling any other functions.');
        }
    }

    /**
     * @param array $headers An array of headers.
     * @return array Returns array of only the http-related headers.
     */
    protected static function getHttpHeaders(): array
    {
        $output = [];

        /* only keep http-related headers */
        foreach ($_SERVER as $header => $value) {
            if (substr($header, 0, 5) === 'HTTP_') {
                $output[$header] = $value;
            }
        }

        /* server requires headers to be an array with something in it */
        if (empty($output)) {
            $output = [''];
        }

        return $output;
    }

    /**
     * @param DateTime $timestamp
     * @return array returns two numbers, based on the given timestamp.
     */
    protected static function mathProblem(DateTime $timestamp): array
    {
        /* get two digits from the seconds; for our simple math problem. */
        $second = (int)$timestamp->format('s');
        /* get the first two meaningful digits from the microseconds; IE: 000010 becomes 10; 123456 becomes 12. */
        $micro  = (int)((float)('0.' . (int)$timestamp->format('u')) * 100);

        return [$second, $micro];
    }

    /**
     * @param string $honeypot_value
     * @return array Returns duration, honeypot, and submit.
     * @throws UnexpectedValueException
     */
    protected static function calculateMeta(string $honeypot_value = null): array
    {
        /* get timestamp of when the form was generated, so we can determine the duration */
        $timestamp = null;
        $submit    = null;
        if (isset($_SERVER['HTTP_REFERER'])) {
            /* use the referer to determine what the request_uri for the previous page */
            [, $referer_uri] = explode($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'], $_SERVER['HTTP_REFERER']);
            $timestamp = static::$session->get(static::$prefix . "form_{$referer_uri}");
            $submit    = $referer_uri;
        }
        if (is_null($timestamp) || is_null($submit)) {
            /* was unable to look up timestamp by the referred form, fallback to timestamp of last form loaded */
            $timestamp = static::$session->get(static::$prefix . 'timestamp');
            $submit    = static::$session->get(static::$prefix . 'submit');
        }

        /* turn time data into DateTime objects */
        $then = DateTime::createFromFormat(static::DATE_FORMAT, $timestamp);
        $now  = DateTime::createFromFormat('U.u', $_SERVER['REQUEST_TIME_FLOAT']);

        if (!$then || !$now) {
            /* in the unlikely event we do not have dates to work with, throw an exception */
            throw new UnexpectedValueException('Invalid state, unable to parse dates: ' . json_encode(['then' => $timestamp, 'now' => $_SERVER['REQUEST_TIME_FLOAT']]));
        }

        /* calculate duration */
        $diff     = $now->diff($then, true);
        $duration = $diff->format('%s.%F');

        /* determine if the user failed the honeypot test */
        [$second, $micro] = static::mathProblem($then);

        /* to avoid falling for the honeypot, the field must be present, and it's value must be the expected output */
        $honeypot = is_null($honeypot_value) || ($honeypot_value !== (string)($second + $micro));

        return [$duration, $honeypot, $submit];
    }
}
