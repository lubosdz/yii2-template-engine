Yii2 Template Engine
====================

Simple, fast and flexible HTML templating engine for [Yii2](https://www.yiiframework.com/) PHP framework with zero configuration.
Supports basic control structures (IF, FOR, SET), importing subtemplates (IMPORT), piped and dynamic directives, active record (AR) relations and deep nested arrays.
It is similar to [Twig](https://twig.symfony.com/) or [Blade](https://laravel.com/docs/8.x/blade), however with less overhead, no dependencies and without advanced features.

It can be used to render e.g. an invoice from HTML markup, edited in a WYSIWYG editor and turned into PDF or MS Word file - see example bellow.

Generic version without framework dependency can be found at [lubosdz/html-templating-engine](https://github.com/lubosdz/html-templating-engine).


Installation
============

```bash
$ composer require "lubosdz/yii2-template-engine"
```

or via `composer.json`:

```bash
"require": {
	...
	"lubosdz/yii2-template-engine": "^1.0",
	...
},
```


Basic usage
===========

Initiate template engine inside Yii application:

~~~php
use lubosdz\yii2\TemplateEngine;
$engine = new TemplateEngine();
~~~

or register as a component in `app/config/main.php`:

~~~php
...
'components' => [
	'engine' => [
		'class' => 'lubosdz\yii2\TemplateEngine',
	]
]
...

$engine = Yii::$app->template;
~~~

Use method `$engine->render($html [, $values])` to generate HTML output:

* $html = source HTML markup with placeholders like `<h1>{{ processMe }}</h1>`. This argument can be also path alias starting with `@` to load existing HTML file, such as `@app/templates/invoice.html`
* $values = array of values like pairs `[processMe => value]` to be injected into or evaluated inside placeholders

Once output generated, it can be e.g. supplied to [PDF](https://github.com/tecnickcom/TCPDF) or [MS Word](https://github.com/PHPOffice/PHPWord) renderer to produce a PDF or MS Word file respectively.


Simple placeholders
-------------------

Templating engine collects all `placeholders` within supplied HTML markup and attempts to replace them with matching `$values` or evaluate as control structure.
Placeholders that have not been processed are left untouched by default. This behaviour is suitable for development.
In production, setting `$engine->setForceReplace(true)` can be set to replace unprocessed placeholders with empty string.

~~~php
$html = 'Hello <b>{{ who }}</b>!';
$values = ['who' => 'world'];
echo $engine->render($html, $values);
// output: "Hello <b>world</b>!"
~~~


Built-in directives
-------------------

Template engine comes with couple of generally usable methods for basic date,
time and string manipulation - `directives`. These can be referenced directly
inside supplied HTML markup. Directives use the pipe operator `|` to chain
operations within the placeholder.

~~~php
$html = $engine->render('Generated on {{ today }} at {{ time }}.');
// output - respecting EN locale: "Generated on Dec 31, 2021 at 11:59pm."
// output - respecting SK/CS locale: "Generated on 31. 12. 2021 at 23:59."

echo $engine->render('Meet me at {{ now(7200) | time }}.');
// output example, if now is 8:30am: "Meet me at 10:30am." (shift +2 hours = 7200 secs)

echo $engine->render('My name is {{ user.name | escape }}.', ['user' => [
	'name' => '<John>',
]]);
// filtered output: "My name is &lt;John&gt;."

echo $engine->render('Hello {{ user.name | truncate(5) | e }}.', ['user' => [
	'name' => '<John>',
]]);
// truncated and filtered output: "Hello &lt;John...."
~~~

Specific for the Yii 2 framework, object properties as well as related [models](https://www.yiiframework.com/doc/api/2.0/yii-base-model)
are automatically collected, if referenced inside placeholders.

~~~php
$html = $engine->render('Hello {{ customer.name }}.', [
	'customer' => Customer::findOne($id), // find active record
]);
// output e.g.: "Hello John Doe."

$html = $engine->render('Address is {{ customer.address.city }} {{ customer.address.zip }}.', [
	'customer' => Customer::findOne($id), // Customer has defined relation to object `Address`
]);
// output e.g.: "Address is Prague 10000."
~~~

Other built-in directives:

~~~php

// trim - standard PHP trim function
$html = $engine->render('Hello {{ username | trim }} ', [
	'username' => '   John Doe!   ',
]);
// output "Hello John Doe!"

$html = $engine->render('Hello {{ username | trim(" !eo") }}', [
	'username' => '   John Doe!   ',
]);
// output "Hello John D"

// replace - match simple string or regular expression (REGEX)
$html = $engine->render('HELLO {{ name | replace (BADBOY, GOODBOY) }}!', [
	'name' => 'BADBOY'
]);
// output "HELLO GOODBOY"

// nl2br - new lines to brackets
$html = $engine->render('NOTES: {{ notes_textarea | nl2br }}', [
	'notes_textarea' => "first line ...\nsecond line\n- last line -"
]);
// output:
"NOTES: first line ...
<br>second line
<br>- last line -
"

// concatenation - joining strings
$html = $engine->render('Order #{{ order_id | concat("by user"; " - ") | concat(customer.name) }}', [
	'order_id' => "123",
	'customer' => [
		'name' => 'John Doe',
	],
]);
// output "Order #123 by customer - John Doe"

~~~


Dynamic directives
------------------

Dynamic directives allow binding custom **anonymous functions** to placeholders.
They can be added at a runtime in 2 steps and greatly extend flexibility of the engine.

* define directive ie. `$engine->setDirective(directiveName, function($arg){ ... })`
* call directive inside the placeholder ie. `{{ user.name | directiveName($arg) }}`

In the following example we will attach dynamic directive named `coloredText`
and render output with custom inline CSS:

```php
// attach dynamic directive (anonymous function) accepting 2 arguments
$engine->setDirective('coloredText', function($text, $color){
	return "<span style='color: {$color}'>{$text}</span>";
});

// process template - we can set different color in each call
echo $engine->render("This is {{ output | coloredText(yellow) }}", [
	'output' => 'colored text',
]);
// output: "This is <span style='color: yellow'>colored text</span>"
```

Note: The first argument passed into dynamic directive (`$text` in the example above)
is always the value from previous piped operation.


IF .. ELSEIF .. ELSE .. ENDIF
-----------------------------

Control structure `IF .. ELSEIF .. ELSE .. ENDIF` is supported:

~~~php
$templateHtml = "
{{ if countOrders > 0 }}
	<h3>Thank you!</h3>
{{ else }}
	<h3>Sure you want to leave?</h3>
{{ endif }}
";

$values = [
	'countOrders' => count(Customer::findOne($id)->orders); // e.g. 3
];

echo $engine->render($templateHtml, $values);
// output e.g.: "<h3>Thank you!</h3>" - if some order found
~~~


FOR ... ELSEFOR .. ENDFOR
-------------------------

Structure `FOR ... ELSEFOR .. ENDFOR` will create loop:

~~~php
$templateHtml = "
<table>
{{ for item in items }}
	{{ SET subtotal = item.qty * item.price * (100 + item.vat) / 100 }}
	<tr>
		<td>#{{ loop.index }}</td>
		<td>{{ item.description }}</td>
		<td>{{ item.qty }}</td>
		<td>{{ item.price | round(2) }}</td>
		<td>{{ item.vat | round(2) }}%</td>
		<td>{{ subtotal | round(2) }} &euro;</td>
	</tr>
	{{ SET total = total + subtotal }}
{{ endfor }}
</table>
<p>Amount due: <b> {{ total | round(2) }} Eur</b></p>
";

$values = [
	'items' => [
		['description' => 'Item one', 'qty' => 1, 'price' => 1, 'vat' => 10],
		['description' => 'Item two', 'qty' => 2, 'price' => 2, 'vat' => 20],
		['description' => 'Item three', 'qty' => 3, 'price' => 3, 'vat' => 30],
		['description' => 'Item four', 'qty' => 4, 'price' => 4, 'vat' => 40],
	]
];

$html = $engine->render($templateHtml, $values);
// outputs valid HTML table with items e.g.: "<table><tr><td>#1</td><td> ..."
~~~

Following auxiliary variables are accessible inside each loop:

* `loop.index` .. (int) 1-based iteration counter
* `loop.index0` .. (int) 0-based iteration counter
* `loop.length` .. (int) total number of items/iterations
* `loop.first` .. (bool) true on first iteration
* `loop.last` .. (bool) true on last iteration


SET command
-----------

Allows manipulating local template variables, such as count totals:

```php
{{ SET subtotal = item.qty * item.price * (100 + item.vat) / 100 }}
{{ SET total = total + subtotal }}
```

See also example under `FOR`.

Note: shorthand syntax `+=` e.g. `SET total += subtotal` is NOT supported.


IMPORT command
--------------

Allows importing another templates (subtemplates).
Importing of subtemplates from within subtemplates is supported too.
For security reasons imported subtemplate(s) must reside inside the template directory
(e.g. `../templates/header.html`) or subdirectory (e.g. `../templates/invoice/header.html`).
This allows effective structuring and maintaing template sets.
Attempt to load a template from outside of the template directory will throw an error.

First, set the template directory either explicitly or via loading template by alias:

~~~php
// set the template directory root explicitly
$engine->setDirTemplates('/abs/path/to/templates');

// template directory will be set implicitly as the parent of `invoice.html`
// note: engine's method "render()" supports Yii's aliases, so if supplied string
//       starts with ampersand `@` it is assuming template absolute path
$htmlInvoice = $engine->render('@templates/invoice.html');
~~~

Then in processed template add the `import` command:

```php
<h3>Invoice No. 20230123</h3>
{{ import invoice_header.html }}
{{ import invoice_body.html }}
{{ import _partial/version_2/invoice_footer.html }}
<p>Generated on ...</p>
```


Configuring template engine
===========================

Templating engine comes with most typical pre-configured settings.
In many cases it may be useful to change default behaviour.
The engine allows changing:

* argument separator in directives
* enabling / disabling logging of errors
* configuring replacement for empty or unprocessed placeholders

Setting the argument separator in directives
--------------------------------------------

The engine uses by default semicolon `;` which is less common and less prone to conflict with supplied texts.
It can be changed to more typical comma `,` by setting:

```php
$engine->setArgSeparator(",");

// then use it also in placeholders and directives
$engine->render("{{ user | truncate(5, '..') }}", ["user" => "John Doe"]);
```

Please note the the engine will ignore placeholders for which parsing fails.
See also [test](https://github.com/lubosdz/yii2-template-engine/blob/main/tests/TemplateEngineTest.php#L179) for detailed behaviour.


Enabling / disabling errors logging
-----------------------------------

By default the engine [logs errors](https://github.com/lubosdz/yii2-template-engine/blob/main/src/TemplateEngine.php#L98) into system logs.
Typically, these may be ie. unprocessed placeholders (meaning no value supplied) or failed parsing of placeholders.
It is highly recommended to enable this logging during development.
However, in production it may be more desired to turn it off by setting:

```php
$engine->setLogErrors(false);
```


Replacing of empty or unprocessed placeholders
----------------------------------------------

By default the engine does not replace any unprocessed or empty placeholders.
This allows quick discovering the issues in templates during development.
By defining **replacement as a string** we can force the engine to insert such a string
into the output for all empty or unprocessed placeholders.
Following are typical and valid replacement alternatives:


```php
// default - do not process any empty placeholders
// generated map will set for missed placeholders NULL
$engine->setForceReplace(false);

// yes, replace empty placeholders with empty string
$engine->setForceReplace(true);
$engine->setForceReplace("");

// yes, replace empty placeholders with 5 dots
$engine->setForceReplace(".....");

// yes, replace empty placeholders with HTML entity to retain spacing
$engine->setForceReplace("&nbsp;");
```


Reading template resources
--------------------------

After processing whole template it may be usefull to store some processed data.
Typically we may want to re-populate template with same values in the future
and store current data into the database.
The engine allows reading generated resources.


```php
list($map, $placeholders, $values, $html) = $engine->getResources();
```

This will return all data necessary for reconstructing the template:

* `$map` .. array of pairs `placeholder` => `processed value`
* `$placeholders` .. array of pairs `placeholder` => `directive`
* `$values` .. array of supplied parameters
* `$html` .. string raw HTML before processing



Rendering PDF or MS Word files
------------------------------

Rendering engine allows user to safely change output without any programming knowledge.
To add an extra value, we can also turn rendered HTML output into professionally
looking [PDF](https://github.com/tecnickcom/TCPDF) or [MS Word](https://github.com/PHPOffice/PHPWord) file:

~~~php

// first process HTML template and input values
$htmlOutput = $engine->render($htmlMarkup, $values);

// then generate PDF file:
$pdf = new \TCPDF();
$pdf->writeHTML($htmlOutput);
$path = $pdf->Output('/save/path/my-invoice.pdf');

// or generate MS Word file:
$word = new \PhpOffice\PhpWord\PhpWord();
$section = $word->addSection();
\PhpOffice\PhpWord\Shared\Html::addHtml($section, $htmlOutput);
$writer = \PhpOffice\PhpWord\IOFactory::createWriter($word, 'Word2007');
$writer->save('/save/path/my-invoice.docx');
~~~

Running tests
=============

* run `phpunit` in the root directory


Tips & notes
============

* see `tests/TemplateEngineTest.php` for more examples
* Running tests via phpunit on [NuSphere's PhpEd](http://www.nusphere.com/) in debug mode:

> use `-d` parameter to inject debugging arguments, e.g. `> phpunit -d DBGSESSID=12655478@127.0.0.1:7869 %*`

* Adding functionality - simply extend `TemplateEngine` class:

~~~php
class MyRenderer extends \lubosdz\yii2\TemplateEngine
{
	// your code, custom directives, override parent methods ..
}
~~~


Changelog
=========

1.0.9 - released 2023-12-29
---------------------------

* exclude partially parsed placeholders from expression translator


1.0.8 - released 2023-12-11
---------------------------

* refactored ELSEFOR behaviour - the condition will apply if no items to loop through
* improved parsing REGEX expressions for more precise match
* translation of placeholder keys now uses REGEX boundary `\b` to avoid naming conflicts
* added build-in directive `replace` e.g. " {{ name | replace(WHAT, REPLACE) }} "
* support for atomic booleans in `IF` condition eg. {{ if cars }} ... {{ endif }}
* added test + improved documentation


1.0.7 - released 2023-11-14
---------------------------

* fix eval error for numeric values starting with zero (should cast to string)


1.0.6 - released 2023-10-22
---------------------------

* support importing subtemplates via `{{ import file }}`
* fix converting formatted number to a valid PHP number in directive `dir_round`


1.0.5 - released 2023-09-18
---------------------------

* support configurable argument separator (beside default semicolon ";")
* resolve deep-tree argument key conflicts
* added built-in directive concat, trim
* added tests, improved documentation, typehints


1.0.4 - released 2023-09-05
---------------------------

* support PHP 8.2
* properly detect valid Datetime string in built-in directive
* forceReplace now takes beside boolean also string as a replacement value
* fixed bug - lookup lowercased AR model
* fixed the IF-test
* improved documentation


1.0.3 - released 2022-08-04
---------------------------

* added support for loading HTML via path alias, e.g. `$engine->render('@app/templates/invoice.html', $data)`
* minor documentation improvements


1.0.2 - released 2022-02-01
---------------------------

* fix compatability for PHP 8.1


1.0.1 - released 2021-12-30
---------------------------

* fix tests for PHP 7.0 - 8.0
* improved documentation


1.0.0 - released 2021-12-13
---------------------------

* initial release
