# vigilant-form-kit
(Software Development) Kit for VigilantForm.

*Warning: Work In Progress*

## What is is?
VigilantFormKit is a simple library to make it easy to push your form submissions into an instance of VigilantForm.

## So what is VigilantForm, then?
VigilantForm is my attempt to stop spam-bots from filling out forms on my website, and clogging up my CRM.
So rather then taking form submissions and putting them directly in your database, you push them to VigilantForm.
It scores the submission, based on whatever logic you like; some examples include:
* checking if they passed the honeypot test
* checking how quickly the form was submitted
* checking if the email is valid (syntax and dns check)
* checking if the phone is valid
* checking origin of the ip-address (via ipstack.com)
* looking for bad input, like "http://" in the name field
* and so on

Once scored, decide what to do with it, push it into your CRM, send a Discord notification, send an email, silently ignore it, or whatever.

## Cool, so how do I use the kit?
After setting up your instance of VigilantForm, using the kit is simple.

In your website, where you want to use the kit:
```php
composer require vigilant-form-kit
```

### On every page visited, upkeep the user's data:
By default it will use the existing php session, if it's already started, or create
a new session with the defaults.
```php
use VigilantForm\Kit\{VigilantFormKit, SessionBag};

VigilantFormKit::setSession();
VigilantFormKit::trackSource();
```
If you already use a php session, make sure to open it before calling.

If you use something like Laravel, you can pass the session object to ::setSession():
```php
/** @var Request $request */
VigilantFormKit::setSession($request->session());
```
Now we'll use the session that Laravel manages.

### When you have a form on the page:
Inject a honeypot field into each form, so we can use that for scoring later:
```php
$html = VigilantFormKit::generateForm('real-sounding-unique-form-field-name');
```
*Note: the honeypot field name must not be the same name as a real field.*
But it is best for the honeypot field to avoid anything blantant, such as "honeypot" or "trap".

### Handling the form submit
```php
$vigilantFormKit = new VigilantFormKit($server_url, $client_id, $client_secret);
$success = $vigilantFormKit->submitForm($website, $form_title, $form_fields, $honeypot_name);
```
This will determine if the user failed the honeypot test, calcualte duration,
and submit the form submission to your VigilentForm instance.

$success will be true, if anythig went wrong, an exception will be thrown.
So this should be in a try/catch block.

*Note: if you call trackeSource() on the same request as calling submitForm(), you'll need to make sure that the submitForm() happens between setSession() and trackSource().*
My recommendation would be setting up a default middleware which does the trackeSource() and disable that middleware for your controller which handles the form submit.
I'll get a sample of doing all this in vanilla php and Laravel 6.x at some point.

## Using another framework session
If setting data directly within ```$_SESSION``` isn't how your framework uses the session,
you can easily make a simple wrapper to pass to setSession(), like you can with Laravel.

Checkout the SessionBag class, basically any class with the three following functions is allowed:
* exists($field): bool
* get($field): mixed
* put($field, $value)

The session class support is "duck-footed", which is to say, rather than implementing some interface,
we check the class to contain the needed methods instead. This means in the case of Laravel,
the Kit supports Laravel without having any specific ties to it.

