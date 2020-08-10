[![CodeFactor](https://www.codefactor.io/repository/github/digitaladapt/vigilant-form-kit/badge)](https://www.codefactor.io/repository/github/digitaladapt/vigilant-form-kit)

# vigilant-form-kit
(Software Development) Kit for VigilantForm.

## So what is this?
VigilantFormKit is a simple library to make it easy to push your form submissions into an instance of VigilantForm.

## So what is VigilantForm?
VigilantForm is my attempt to keep junk form submissions from getting put in my CRM.

So rather then putting form submissions directly into my CRM, I push them to VigilantForm.

VigilantForm scores the submission, based on whatever scoring logic you choose; some examples include:
* checking if they passed the honeypot test
* checking how quickly the form was submitted
* checking if the email is valid (syntax and dns check)
* checking if the phone number is reasonable
* checking if required fields are filled out
* checking origin of the ip-address (via ipstack.com)
* looking for bad input, like "http://" in the name field
* and so on

After scoring is complete, the form submission is graded, and you can take different custom actions depending on the grade.

For example, I push quality form submissions into my CRM, but form submissions which need review go to Discord, with links to approve/reject;
meanwhile junk form submissions get logged to a file for periodic review, and spam form submsissions quietly get trashed.

## So how is it used?
First you add the library:
```bash
composer require digitaladapt/vigilant-form-kit
```

Then you hook it into your application:
```php
use VigilantForm\Kit\VigilantFormKit;

/* once per page, setup and run the tracking */
$vigilantFormKit = new VigilantFormKit("<SERVER_URL>", "<CLIENT_ID>", "<CLIENT_SECRET>");

// optional, defaults to (new SessionBag(), "vigilantform_")
// note: for Laravel you can use $request->session().
//$vigilantFormKit->setSession($session, "<PREFIX>");

// optional, defaults to ("age", "form_sequence", "/vf-pn.js", "vf-pn")
// note: "<HONEYPOT>" and "<SEQUENCE>" must be unique form field names.
// note: "<SCRIPT_SRC>" must be a public javascript file location.
// note: "<SCRIPT_CLASS>" must be the identifier used to process the honeypot in said javascript.
$vigilantFormKit->setHoneypot("<HONEYPOT>", "<SEQUENCE>", "<SCRIPT_SRC>", "<SCRIPT_CLASS>");

// optional, defaults to (new NullLogger())
//$vigilantFormKit->setLogger($logger);

// once everything is setup, run the tracking
// if this request is a non-page (script or image) file,
// pass true to track the referral page instead.
$vigilantFormKit->trackSource();
```

```php
/* once per form, add honeypot field */
echo $vigilantFormKit->generateHoneypot();
```

```php
use UnexpectedValueException;

/* handle form submission */
if (!empty($_POST)) {
    try {
        // will determine if user failed the honeypot test, calculate duration, and submit to server.
        $vigilantFormKit->submitForm("<WEBSITE>", "<FORM_TITLE>", $_POST);
    } catch (UnexpectedValueException $exception) {
        // handle submitForm failure
    }
}
```
