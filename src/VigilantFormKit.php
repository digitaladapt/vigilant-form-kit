<?php

namespace VigilantForm\Kit;

use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\{LoggerAwareInterface, LoggerAwareTrait, LoggerInterface, NullLogger};
use UnexpectedValueException;

/**
 * // copy (or setup symbolic link) vf-pn.js to your public folder.
 * // alternatively, you can use any script src and class, provided you call setHoneypot() and generateScript() correctly.
 *
 * use VigilantForm\Kit\VigilantFormKit;
 *
 * $vigilantFormKit = new VigilantFormKit("<SERVER_URL>", "<CLIENT_ID>", "<CLIENT_SECRET>");
 *
 * // optional, defaults to (new SessionBag(), "vigilantform_")
 * $vigilantFormKit->setSession($session, "<PREFIX>");
 *
 * // optional, defaults to ("age", "form_sequence", "/vf-pn.js", "vf-pn")
 * // note: "<HONEYPOT>" and "<SEQUENCE>" must be unique form field names.
 * // note: "<SCRIPT_SRC>" must be a public javascript file location.
 * // note: "<SCRIPT_CLASS>" must be the identifier used to process the honeypot in said javascript.
 * $vigilantFormKit->setHoneypot("<HONEYPOT>", "<SEQUENCE>", "<SCRIPT_SRC>", "<SCRIPT_CLASS>")
 *
 * // optional, defaults to (new NullLogger())
 * $vigilantFormKit->setLogger($logger);
 *
 *
 *
 * // once everything is setup, run the tracking
 * $vigilantFormKit->trackSource();
 *
 *
 *
 * // within each html form, recommend just before submit
 * echo $vigilantFormKit->generateHoneypot();
 *
 *
 *
 * if (!empty($_POST)) {
 *     try {
 *         // will determine if user failed the honeypot test, calculate duration, and submit to server.
 *         $vigilantFormKit->submitForm("<WEBSITE>", "<FORM_TITLE>", $_POST)
 *     } catch (UnexpectedValueException $exception) {
 *         // handle submitForm failure
 *     }
 * }
 */
class VigilantFormKit implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected const DATE_FORMAT = 'Y-m-d H:i:s.u';

    protected const DEFAULT_PREFIX = 'vigilantform_';

    protected const DEFAULT_HONEYPOT = 'age';

    protected const DEFAULT_SEQUENCE = 'form_sequence';

    protected const DEFAULT_SCRIPT_SRC = '/vf-pn.js';

    protected const DEFAULT_SCRIPT_CLASS = 'vf-pn';

    /** @var int sequence for this page view */
    protected $seq_id;

    /** @var DateTime time associated with this page view */
    protected $seq_time;

    /** @var int to make each form instance use a unique id */
    protected $instance;

    /** @var string server-url */
    protected $url;

    /** @var array client-auth: ['id' => '', 'secret' => ''] */
    protected $auth;

    /** @var object with exists(), get(), and put(); defaults to SessionBag */
    protected $session;

    /** @var string prefix for the fields within session, defaults to "vigilantform_" */
    protected $prefix;

    /** @var string name of the honeypot form field, defaults to "age" */
    protected $honeypot;

    /** @var string name of the sequence form field, defaults to "form_sequence" */
    protected $sequence;

    /** @var string name of javascript file included with each honeypot, defaults to "/vf-pn.js" */
    protected $script_src;

    /** @var string name of the html class on the honeypot container, defaults to "vf-pn" */
    protected $script_class;

    /* LoggerInterface $logger from LoggerAwareTrait */

    /* ---- Public Functions ---- */

    /**
     * @param string $server_url
     * @param string $client_id
     * @param string $client_secret
     */
    public function __construct(string $server_url, string $client_id, string $client_secret)
    {
        $this->seq_id   = null; /* defer */
        $this->seq_time = null; /* defer */
        $this->instance = 1;
        $this->url      = $server_url;
        $this->auth     = [
            'id'     => $client_id,
            'secret' => $client_secret,
        ];
        $this->session      = null; /* defer */
        $this->prefix       = static::DEFAULT_PREFIX;
        $this->honeypot     = static::DEFAULT_HONEYPOT;
        $this->sequence     = static::DEFAULT_SEQUENCE;
        $this->script_src   = static::DEFAULT_SCRIPT_SRC;
        $this->script_class = static::DEFAULT_SCRIPT_CLASS;
        $this->logger       = new NullLogger();
    }

    /**
     * To disable prefix set to "", null will result in the default prefix.
     * @param object $session Optional, object with exists(), get(), and put(); defaults to SessionBag.
     * @param string $prefix Optional, prefix for the fields within session, defaults to "vigilantform_".
     * @throws UnexpectedValueException If the given $session object lacks required methods.
     * @return void
     */
    public function setSession(object $session = null, string $prefix = null): void
    {
        if (!$session) {
            /* no $session given, default to SessionBag */
            $this->session = new SessionBag();
        } elseif (!method_exists($session, 'exists') || !method_exists($session, 'get') || !method_exists($session, 'put')) {
            /* given $session object is insufficient */
            throw new UnexpectedValueException('Given session object lacks exists/get/put functions.');
        } else {
            /* $session accepted */
            $this->session = $session;
        }

        /* check against null, to allow blank string */
        $this->prefix = $prefix ?? static::DEFAULT_PREFIX;
    }

    /**
     * @param string $honeypot Optional, name of the honeypot form field, defaults to "age".
     * @param string $sequence Optional, name of the sequence form field, defaults to "form_sequence".
     * @param string $script_src Optional, name of javascript file included with each honeypot, defaults to "/vf-pn.js".
     * @param string $script_class Optional, name of the html class on the honeypot container, defaults to "vf-pn".
     */
    public function setHoneypot(string $honeypot = null, string $sequence = null, string $script_src = null, string $script_class = null): void
    {
        $this->honeypot     = $honeypot     ?: static::DEFAULT_HONEYPOT;
        $this->sequence     = $sequence     ?: static::DEFAULT_SEQUENCE;
        $this->script_src   = $script_src   ?: static::DEFAULT_SCRIPT_SRC;
        $this->script_class = $script_class ?: static::DEFAULT_SCRIPT_CLASS;
    }

    /* setLogger() provided by LoggerAwareTrait */

    /**
     * Sets various meta data, based on $_SERVER, so we have it when the form is submitted.
     */
    public function trackSource(): void
    {
        $this->loadSession();

        /* meta - set on first page */
        if (!$this->session->exists($this->addPrefix('ip_address'))) {
            $this->session->put($this->addPrefix('ip_address'), $_SERVER['REMOTE_ADDR'] ?? null);
        }
        if (!$this->session->exists($this->addPrefix('user_agent'))) {
            $this->session->put($this->addPrefix('user_agent'), $_SERVER['HTTP_USER_AGENT'] ?? null);
        }
        if (!$this->session->exists($this->addPrefix('http_headers'))) {
            $this->session->put($this->addPrefix('http_headers'), $this->getHttpHeaders());
        }

        /* links - set of first page */
        if (!$this->session->exists($this->addPrefix('referral'))) {
            $this->session->put($this->addPrefix('referral'), $_SERVER['HTTP_REFERER'] ?? null);
        }
        if (!$this->session->exists($this->addPrefix('landing'))) {
            $this->session->put($this->addPrefix('landing'),
                ($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' .
                ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost') .
                ($_SERVER['REQUEST_URI'] ?? '/')
            );
        }

        /* determine when this page was requested */
        $time = DateTime::createFromFormat('U.u', $_SERVER['REQUEST_TIME_FLOAT'] ?? number_format(microtime(true), 6, '.', ''));

        /* manage sequence, array starts with one element so all our ids are positive */
        $sequenceList   = $this->session->get($this->addPrefix('sequence'), [0 => false]);
        $this->seq_id   = count($sequenceList);
        $this->seq_time = $time;
        $sequenceList[$this->seq_id] = [
            'time' => $time->format(static::DATE_FORMAT),
            'url'  =>
                ($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' .
                ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost') .
                ($_SERVER['REQUEST_URI'] ?? '/'),
        ];
        $this->session->put($this->addPrefix('sequence'), $sequenceList);
    }

    /**
     * Call once per html form, reusing the html multiple times will cause problems.
     * If user has javascript disabled, to pass the honeypot, they'll be asked
     * a simple math problem. If they have javascript, they will see nothing.
     * @return string Returns chunk of html to insert into a form.
     */
    public function generateHoneypot(): string
    {
        $this->loadSession();

        $index = $this->instance;
        $this->instance++;

        [$second, $micro] = $this->mathProblem($this->seq_time);

        return <<<HTML
<input type="hidden" name="{$this->sequence}" value="{$this->seq_id}">
<div id="{$this->honeypot}_c{$index}" class="{$this->script_class}" data-first="{$second}" data-second="{$micro}">
    <label for="{$this->honeypot}_i{$index}">What is {$second} plus {$micro}?</label>
    <input type="text" id="{$this->honeypot}_i{$index}" name="{$this->honeypot}" autocomplete="off">
</div>
<script src="{$this->script_src}"></script>
HTML;
    }

    /**
     * One way to support the javascript needed that the honeypot needs, since inlining is an issue with CSP.
     * @return Returns string of JavaScript which needs to be found when user requests "script_src".
     */
    public static function generateScript(): string
    {
        return <<<JAVASCRIPT
(function () {
    var foo = document.getElementsByClassName("{$this->script_class}")[0];
    if (foo) {
        foo.style.position   = "absolute";
        foo.style.height     = "11px";
        foo.style.width      = "11px";
        foo.style.textIndent = "11px";
        foo.style.overflow   = "hidden";
        foo.className        = "";

        var bar = foo.getElementsByTagName("input")[0];
        if (bar) {
            bar.tabIndex  = -1;
            bar.value     = parseInt(foo.getAttribute("data-first")) + parseInt(foo.getAttribute("data-second"));
            bar.className = "";
        }
    }
})();
JAVASCRIPT;
    }

    /**
     * @param string $website Name of the website that the form exists on.
     * @param string $form_title Name of the form was submitted.
     * @param array $fields The user submission, such as $_POST.
     * @return bool Returns true on success, will throw an exception otherwise.
     * @throws UnexpectedValueException when attempt to store form is unsuccessful.
     */
    public function submitForm(string $website, string $form_title, array $fields): bool
    {
        $this->loadSession();

        /*  determine the status of duration, honeypot, and submit */
        [$duration, $honeypot, $submit] = $this->calculateMeta($fields);

        /* remove the honeypot and sequence from the form fields */
        unset($fields[$this->honeypot]);
        unset($fields[$this->sequence]);

        /* submit form to the vigilant-form server */
        $guzzle = new Client([
            'allow_redirects' => false,
            'base_uri'        => $this->url,
            'timeout'         => 10.0,
            'headers'         => [
                'X-Requested-With' => 'XMLHttpRequest',
            ],
        ]);

        $meta = [
            'ip_address'   => $this->session->get($this->addPrefix('ip_address')),
            'user_agent'   => $this->session->get($this->addPrefix('user_agent')),
            'http_headers' => $this->session->get($this->addPrefix('http_headers')),
            'honeypot'     => $honeypot,
            'duration'     => $duration,
        ];

        $source = [
            'website' => $website,
            'title'   => $form_title,
        ];

        $links = [
            'referral' => $this->session->get($this->addPrefix('referral')),
            'landing'  => $this->session->get($this->addPrefix('landing')),
            'submit'   => $submit,
            'details'  => array_filter($this->session->get($this->addPrefix('sequence'), [0 => false])),
        ];

        /* log the request */
        $this->logger->info('Submitting to VigilantForm: {data}', ['data' => [
            'fields' => $fields,
            'meta'   => $meta,
            'source' => $source,
            'links'  => $links,
        ]]);

        try {
            $response = $guzzle->post('', ['json' => [
                'auth'   => $this->auth,
                'fields' => $fields,
                'meta'   => $meta,
                'source' => $source,
                'links'  => $links,
            ]]);

            /* log the response */
            $this->logger->info('Response from VigilantForm: {data}', ['data' => (string)$response->getBody()]);

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

    /* ---- Protected Functions ---- */

    /**
     * We delay assigning session until we need it, so that setSession() can be called.
     */
    protected function loadSession(): void
    {
        if ($this->session === null) {
            $this->session = new SessionBag();
        }
    }

    /**
     * @param string $field The name of the field we need to prefix.
     * @return string Returns the given field with the prefix added.
     */
    protected function addPrefix(string $field): string
    {
        return $this->prefix . $field;
    }

    /**
     * @return array Returns array of http headers.
     */
    protected function getHttpHeaders(): array
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
     * @param DateTime $time When the form was requested.
     * @return array Returns array of two ints, based on our sequence time.
     */
    protected function mathProblem(DateTime $time): array
    {
        /* get two digits from the seconds; for our simple math problem. */
        $second = (int)$time->format('s');
        /* get the first two meaningful digits from the microseconds; IE: 000010 becomes 10; 123456 becomes 12. */
        $micro  = (int)((float)('0.' . (int)$time->format('u')) * 100);

        return [$second, $micro];
    }

    /**
     * @param array $fields The form submission, so we get the honeypot and sequence.
     * @return array Returns $duration, $honeypot, and $submit_link.
     */
    protected function calculateMeta(array $fields): array
    {
        $submit         = $_SERVER['HTTP_REFERER'] ?? null;
        $honeypot_value = $fields[$this->honeypot] ?? null;
        $seq_id         = $fields[$this->sequence] ?? null;
        $sequenceList   = $this->session->get($this->addPrefix('sequence'), [0 => false]);
        $now            = $this->seq_time;

        /* get timestamp of when the form was generated, if sequence is invalid then use the previous page load timestamp as fallback */
        $then = DateTime::createFromFormat(static::DATE_FORMAT, $sequenceList[$seq_id]['time'] ?? $sequenceList[$this->seq_id - 1]['time'] ?? false);

        if ($then) {
            /* calculate duration */
            $diff     = $now->diff($then, true);
            $duration = $diff->format('%s.%F');

            /* determine if the user failed the honeypot test */
            [$second, $micro] = $this->mathProblem($then);

            /* to avoid falling for the honeypot, the field must be present, and it's value must be the expected output */
            $honeypot = is_null($honeypot_value) || ($honeypot_value !== (string)($second + $micro));
        } else {
            /* no other timestamp to compare to, so duration is -1 second, and honeypot is failed */
            $duration = '-1.00000';
            $honeypot = true;
        }

        return [$duration, $honeypot, $submit];
    }
}
